<?php
declare(strict_types=1);

ini_set('display_errors', '0');

function sendReorderJson(int $http_status, array $payload): never
{
    http_response_code($http_status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function sendReorderError(
    int $http_status,
    string $code,
    string $message
): never {
    sendReorderJson($http_status, [
        'status' => 'ERROR',
        'code' => $code,
        'message' => $message
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendReorderError(405, 'invalid_request', 'Only POST requests are accepted.');
}

require_once __DIR__ . '/../includes/security.php';
stemSessionStart();

if (!isset($_SESSION['teacher_id'])) {
    sendReorderError(
        401,
        'authentication_required',
        'Teacher authentication is required.'
    );
}

$raw_body = file_get_contents('php://input');

if ($raw_body === false || $raw_body === '' || strlen($raw_body) > 65536) {
    sendReorderError(400, 'invalid_request', 'A valid JSON body is required.');
}

try {
    $request = json_decode($raw_body, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    sendReorderError(400, 'invalid_request', 'Malformed JSON request.');
}

if (!is_array($request)) {
    sendReorderError(400, 'invalid_request', 'The JSON body must be an object.');
}

$teacher_id = (int) $_SESSION['teacher_id'];
$quiz_id = $request['quiz_id'] ?? null;
$question_ids = $request['question_ids'] ?? null;
$posted_csrf_token = $request['csrf_token'] ?? null;
$session_csrf_token = $_SESSION['reorder_questions_csrf_token'] ?? '';

if (
    !is_int($quiz_id)
    || $quiz_id <= 0
    || !is_array($question_ids)
    || empty($question_ids)
    || !is_string($posted_csrf_token)
) {
    sendReorderError(400, 'invalid_request', 'Invalid reorder request.');
}

if (
    !is_string($session_csrf_token)
    || $session_csrf_token === ''
    || !hash_equals($session_csrf_token, $posted_csrf_token)
) {
    sendReorderError(403, 'invalid_csrf', 'The form session has expired.');
}

$normalized_question_ids = [];

foreach ($question_ids as $question_id) {
    if (!is_int($question_id) || $question_id <= 0) {
        sendReorderError(400, 'invalid_request', 'Invalid question list.');
    }

    $normalized_question_ids[] = $question_id;
}

if (count($normalized_question_ids) !== count(array_unique($normalized_question_ids))) {
    sendReorderError(400, 'invalid_request', 'Question IDs must be unique.');
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/quiz_lock.php';
} catch (Throwable $exception) {
    error_log('Question reorder database connection failed: ' . $exception->getMessage());
    sendReorderError(500, 'server_error', 'Unable to save question order.');
}

$transaction_started = false;

try {
    $conn->begin_transaction();
    $transaction_started = true;

    $quiz_state = loadOwnedQuizEditState(
        $conn,
        $quiz_id,
        $teacher_id,
        true
    );

    if ($quiz_state === null) {
        $conn->rollback();
        $transaction_started = false;
        sendReorderError(404, 'quiz_not_found', 'Quiz not found or access denied.');
    }

    if ($quiz_state['is_locked']) {
        $conn->rollback();
        $transaction_started = false;
        sendReorderError(
            409,
            'quiz_locked',
            'This quiz has already been used in a live session and is locked.'
        );
    }

    $question_stmt = $conn->prepare("
        SELECT question_id, question_order
        FROM questions
        WHERE quiz_id = ?
        ORDER BY question_order ASC, question_id ASC
        FOR UPDATE
    ");
    $question_stmt->bind_param('i', $quiz_id);
    $question_stmt->execute();
    $question_result = $question_stmt->get_result();
    $owned_question_ids = [];

    while ($question = $question_result->fetch_assoc()) {
        $question_order = (int) $question['question_order'];

        if ($question_order <= 0) {
            throw new RuntimeException('Existing question order is invalid.');
        }

        $owned_question_ids[] = (int) $question['question_id'];
    }

    $submitted_ids = $normalized_question_ids;
    $owned_ids_for_comparison = $owned_question_ids;
    sort($submitted_ids, SORT_NUMERIC);
    sort($owned_ids_for_comparison, SORT_NUMERIC);

    if ($submitted_ids !== $owned_ids_for_comparison) {
        $conn->rollback();
        $transaction_started = false;
        sendReorderError(
            409,
            'question_mismatch',
            'The question list changed. Refresh the page and try again.'
        );
    }

    $update_stmt = $conn->prepare("
        UPDATE questions
        SET question_order = ?
        WHERE question_id = ?
          AND quiz_id = ?
    ");

    foreach ($normalized_question_ids as $index => $question_id) {
        $temporary_order = -($index + 1);
        $update_stmt->bind_param(
            'iii',
            $temporary_order,
            $question_id,
            $quiz_id
        );
        $update_stmt->execute();

        if ($update_stmt->affected_rows !== 1) {
            throw new RuntimeException('Question could not be locked for reorder.');
        }
    }

    foreach ($normalized_question_ids as $index => $question_id) {
        $final_order = $index + 1;
        $update_stmt->bind_param(
            'iii',
            $final_order,
            $question_id,
            $quiz_id
        );
        $update_stmt->execute();

        if ($update_stmt->affected_rows !== 1) {
            throw new RuntimeException('Question order could not be updated.');
        }
    }

    $conn->commit();
    $transaction_started = false;

    sendReorderJson(200, [
        'status' => 'OK',
        'quiz_id' => $quiz_id,
        'question_count' => count($normalized_question_ids)
    ]);
} catch (Throwable $exception) {
    if ($transaction_started) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_exception) {
            error_log(
                'Question reorder rollback failed for teacher '
                . $teacher_id
                . ': '
                . $rollback_exception->getMessage()
            );
        }
    }

    error_log(
        'Question reorder failed for teacher '
        . $teacher_id
        . ': '
        . $exception->getMessage()
    );
    sendReorderError(500, 'server_error', 'Unable to save question order.');
}
