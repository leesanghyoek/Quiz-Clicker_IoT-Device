<?php
declare(strict_types=1);

ini_set('display_errors', '0');

// Answer devices are in the classroom; nothing from the Internet (tunnel) may use this.
require_once __DIR__ . '/../includes/security.php';
stemBlockInternet();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sendJoinJson(int $httpStatus, array $payload): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendJoinError(string $reason, int $httpStatus = 200): never
{
    sendJoinJson($httpStatus, ['status' => 'ERROR', 'reason' => $reason]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendJoinError('method_not_allowed', 405);
}

$data = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($data)) {
    sendJoinError('invalid_json', 400);
}

$device_code = $data['device_id'] ?? null;
$student_id = $data['student_id'] ?? null;
$join_code = $data['join_code'] ?? null;

if (
    !is_string($device_code)
    || !is_string($student_id)
    || !is_string($join_code)
    || !preg_match('/^\d{2}$/', $device_code)
    || !preg_match('/^\d{2}$/', $student_id)
    || !preg_match('/^\d{2}$/', $join_code)
) {
    sendJoinError('invalid_request', 400);
}

$transaction_started = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../config/db.php';

    $conn->begin_transaction();
    $transaction_started = true;

    // Lock the same row used by start_quiz.php before checking WAITING.
    $session_stmt = $conn->prepare("
        SELECT session_id, class_id, quiz_id, status
        FROM live_sessions
        WHERE join_code = ?
          AND status IN ('WAITING', 'ACTIVE')
        ORDER BY session_id DESC
        FOR UPDATE
    ");
    $session_stmt->bind_param('s', $join_code);
    $session_stmt->execute();
    $session_result = $session_stmt->get_result();

    if ($session_result->num_rows !== 1) {
        if ($session_result->num_rows > 1) {
            error_log('Ambiguous live-session join code detected: ' . $join_code);
        }

        $conn->rollback();
        $transaction_started = false;
        sendJoinError('invalid_join_code');
    }

    $live_session = $session_result->fetch_assoc();
    $session_id = (int) $live_session['session_id'];
    $class_id = (int) $live_session['class_id'];

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
        sendJoinError('device_not_registered');
    }

    $device = $device_result->fetch_assoc();

    if ($device['status'] !== 'ACTIVE') {
        $conn->rollback();
        $transaction_started = false;
        sendJoinError('device_inactive');
    }

    $device_id = (int) $device['device_id'];
    $student_stmt = $conn->prepare("
        SELECT id
        FROM students
        WHERE student_id = ?
          AND class_id = ?
        LIMIT 1
    ");
    $student_stmt->bind_param('si', $student_id, $class_id);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();

    if ($student_result->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        sendJoinError('student_not_in_class');
    }

    $student_pk = (int) $student_result->fetch_assoc()['id'];

    // Rejoin: this device already joined this session as this student (it was
    // reset, lost its saved session, or the phone page was reloaded). Let it
    // back in, even after the quiz has started; nothing new is recorded.
    $rejoin_stmt = $conn->prepare("
        SELECT device_session_id
        FROM device_sessions
        WHERE session_id = ?
          AND device_id = ?
          AND student_pk = ?
        LIMIT 1
    ");
    $rejoin_stmt->bind_param('iii', $session_id, $device_id, $student_pk);
    $rejoin_stmt->execute();

    if ($rejoin_stmt->get_result()->num_rows > 0) {
        $conn->commit();
        $transaction_started = false;
        sendJoinJson(200, ['status' => 'OK', 'session_id' => $session_id, 'rejoined' => true]);
    }

    if ($live_session['status'] !== 'WAITING') {
        $conn->rollback();
        $transaction_started = false;
        sendJoinError('session_not_waiting');
    }

    $device_session_stmt = $conn->prepare("
        SELECT device_session_id
        FROM device_sessions
        WHERE session_id = ?
          AND device_id = ?
        LIMIT 1
    ");
    $device_session_stmt->bind_param('ii', $session_id, $device_id);
    $device_session_stmt->execute();

    if ($device_session_stmt->get_result()->num_rows > 0) {
        $conn->rollback();
        $transaction_started = false;
        sendJoinError('device_already_joined');
    }

    $student_session_stmt = $conn->prepare("
        SELECT device_session_id
        FROM device_sessions
        WHERE session_id = ?
          AND student_pk = ?
        LIMIT 1
    ");
    $student_session_stmt->bind_param('ii', $session_id, $student_pk);
    $student_session_stmt->execute();

    if ($student_session_stmt->get_result()->num_rows > 0) {
        $conn->rollback();
        $transaction_started = false;
        sendJoinError('student_already_joined');
    }

    $insert_stmt = $conn->prepare("
        INSERT INTO device_sessions (
            session_id,
            device_id,
            student_pk
        ) VALUES (?, ?, ?)
    ");
    $insert_stmt->bind_param('iii', $session_id, $device_id, $student_pk);
    $insert_stmt->execute();

    $conn->commit();
    $transaction_started = false;
    sendJoinJson(200, ['status' => 'OK', 'session_id' => $session_id]);
} catch (mysqli_sql_exception $exception) {
    if ($transaction_started) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_exception) {
            error_log('Join rollback failed: ' . $rollback_exception->getMessage());
        }
    }

    error_log('Join API database error: ' . $exception->getMessage());
    sendJoinError('server_error', 500);
} catch (Throwable $exception) {
    if ($transaction_started && isset($conn)) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_exception) {
            error_log('Join rollback failed: ' . $rollback_exception->getMessage());
        }
    }

    error_log('Join API error: ' . $exception->getMessage());
    sendJoinError('server_error', 500);
}
