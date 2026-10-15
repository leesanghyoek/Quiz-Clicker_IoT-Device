<?php
declare(strict_types=1);

ini_set('display_errors', '0');

// Answer devices are in the classroom; nothing from the Internet (tunnel) may use this.
require_once __DIR__ . '/../includes/security.php';
stemBlockInternet();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sendAnswerJson(int $httpStatus, array $payload): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendAnswerError(string $reason, int $httpStatus = 200): never
{
    sendAnswerJson($httpStatus, ['status' => 'ERROR', 'reason' => $reason]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendAnswerError('method_not_allowed', 405);
}

$data = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($data)) {
    sendAnswerError('invalid_json', 400);
}

$device_code = $data['device_id'] ?? null;
$session_id = filter_var($data['session_id'] ?? null, FILTER_VALIDATE_INT);
$question_id = filter_var($data['question_id'] ?? null, FILTER_VALIDATE_INT);
$raw_answer = $data['answer'] ?? null;
$answer = is_string($raw_answer) ? strtoupper(trim($raw_answer)) : '';

if (
    !is_string($device_code)
    || !preg_match('/^\d{2}$/', $device_code)
    || $session_id === false
    || $session_id <= 0
    || $question_id === false
    || $question_id <= 0
    || !in_array($answer, ['A', 'B', 'C', 'D'], true)
) {
    sendAnswerError('invalid_request', 400);
}

$transaction_started = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../config/db.php';

    $conn->begin_transaction();
    $transaction_started = true;

    // Keep the session state stable until the response is committed.
    $session_stmt = $conn->prepare("
        SELECT quiz_id, status, current_question_id
        FROM live_sessions
        WHERE session_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $session_stmt->bind_param('i', $session_id);
    $session_stmt->execute();
    $session_result = $session_stmt->get_result();

    if ($session_result->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('session_not_found');
    }

    $live_session = $session_result->fetch_assoc();

    if ($live_session['status'] !== 'ACTIVE') {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('session_not_active');
    }

    $current_question_id = (int) $live_session['current_question_id'];

    if ($question_id !== $current_question_id) {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('stale_question');
    }

    $quiz_id = (int) $live_session['quiz_id'];
    $question_stmt = $conn->prepare("
        SELECT question_id
        FROM questions
        WHERE question_id = ?
          AND quiz_id = ?
        LIMIT 1
    ");
    $question_stmt->bind_param('ii', $question_id, $quiz_id);
    $question_stmt->execute();

    if ($question_stmt->get_result()->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('invalid_question');
    }

    $device_stmt = $conn->prepare("
        SELECT device_id, status
        FROM devices
        WHERE device_code = ?
        LIMIT 1
    ");
    $device_stmt->bind_param('s', $device_code);
    $device_stmt->execute();
    $device_result = $device_stmt->get_result();

    if ($device_result->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('device_not_registered');
    }

    $device = $device_result->fetch_assoc();

    if ($device['status'] !== 'ACTIVE') {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('device_inactive');
    }

    $device_id = (int) $device['device_id'];
    $joined_stmt = $conn->prepare("
        SELECT student_pk
        FROM device_sessions
        WHERE session_id = ?
          AND device_id = ?
        LIMIT 1
    ");
    $joined_stmt->bind_param('ii', $session_id, $device_id);
    $joined_stmt->execute();
    $joined_result = $joined_stmt->get_result();

    if ($joined_result->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('device_not_joined');
    }

    $student_pk = (int) $joined_result->fetch_assoc()['student_pk'];
    $option_order_map = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4];
    $option_order = $option_order_map[$answer];
    $option_stmt = $conn->prepare("
        SELECT option_id
        FROM question_options
        WHERE question_id = ?
          AND option_order = ?
        LIMIT 1
    ");
    $option_stmt->bind_param('ii', $question_id, $option_order);
    $option_stmt->execute();

    if ($option_stmt->get_result()->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('invalid_option');
    }

    $existing_stmt = $conn->prepare("
        SELECT response_id
        FROM responses
        WHERE session_id = ?
          AND question_id = ?
          AND student_pk = ?
        LIMIT 1
    ");
    $existing_stmt->bind_param('iii', $session_id, $question_id, $student_pk);
    $existing_stmt->execute();

    if ($existing_stmt->get_result()->num_rows > 0) {
        $conn->rollback();
        $transaction_started = false;
        sendAnswerError('already_answered');
    }

    $insert_stmt = $conn->prepare("
        INSERT INTO responses (
            session_id,
            question_id,
            student_pk,
            answer
        ) VALUES (?, ?, ?, ?)
    ");
    $insert_stmt->bind_param('iiis', $session_id, $question_id, $student_pk, $answer);
    $insert_stmt->execute();

    $conn->commit();
    $transaction_started = false;
    sendAnswerJson(200, ['status' => 'OK']);
} catch (mysqli_sql_exception $exception) {
    if ($transaction_started) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_exception) {
            error_log('Answer rollback failed: ' . $rollback_exception->getMessage());
        }
    }

    if ((int) $exception->getCode() === 1062) {
        sendAnswerError('already_answered');
    }

    error_log('Answer API database error: ' . $exception->getMessage());
    sendAnswerError('server_error', 500);
} catch (Throwable $exception) {
    if ($transaction_started && isset($conn)) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_exception) {
            error_log('Answer rollback failed: ' . $rollback_exception->getMessage());
        }
    }

    error_log('Answer API error: ' . $exception->getMessage());
    sendAnswerError('server_error', 500);
}
