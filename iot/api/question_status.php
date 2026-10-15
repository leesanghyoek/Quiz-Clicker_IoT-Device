<?php
declare(strict_types=1);

ini_set('display_errors', '0');

function sendQuestionStatusJson(int $httpStatus, array $payload): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function sendQuestionStatusError(
    int $httpStatus,
    string $code,
    string $message
): never {
    sendQuestionStatusJson($httpStatus, [
        'status' => 'ERROR',
        'code' => $code,
        'message' => $message
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    sendQuestionStatusError(
        405,
        'invalid_request',
        'Only GET requests are accepted.'
    );
}

require_once __DIR__ . '/../includes/security.php';
stemSessionStart();

if (!isset($_SESSION['teacher_id'])) {
    sendQuestionStatusError(
        401,
        'authentication_required',
        'Teacher authentication is required.'
    );
}

$teacher_id = (int) $_SESSION['teacher_id'];
$session_id = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);

if ($teacher_id <= 0 || $session_id === false || $session_id <= 0) {
    sendQuestionStatusError(
        400,
        'invalid_request',
        'A valid session_id is required.'
    );
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../config/db.php';

    $stmt = $conn->prepare("
        SELECT
            ls.session_id,
            ls.quiz_id,
            ls.status,
            ls.current_question_id
        FROM live_sessions ls
        JOIN quizzes q
            ON q.quiz_id = ls.quiz_id
        JOIN classes c
            ON c.class_id = ls.class_id
        WHERE ls.session_id = ?
          AND q.teacher_id = ?
          AND c.teacher_id = ?
    ");
    $stmt->bind_param('iii', $session_id, $teacher_id, $teacher_id);
    $stmt->execute();
    $session_result = $stmt->get_result();

    if ($session_result->num_rows !== 1) {
        sendQuestionStatusError(
            404,
            'session_not_found',
            'Live session not found or access denied.'
        );
    }

    $live_session = $session_result->fetch_assoc();
    $quiz_id = (int) $live_session['quiz_id'];
    $current_question_id = (int) $live_session['current_question_id'];

    if ($current_question_id <= 0) {
        sendQuestionStatusError(
            409,
            'question_not_open',
            'No current question is open for this session.'
        );
    }

    $question_stmt = $conn->prepare("
        SELECT question_id
        FROM questions
        WHERE question_id = ?
          AND quiz_id = ?
        LIMIT 1
    ");
    $question_stmt->bind_param('ii', $current_question_id, $quiz_id);
    $question_stmt->execute();

    if ($question_stmt->get_result()->num_rows !== 1) {
        sendQuestionStatusError(
            409,
            'invalid_current_question',
            'The current question does not belong to this quiz.'
        );
    }

    $joined_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT student_pk) AS joined_count
        FROM device_sessions
        WHERE session_id = ?
    ");
    $joined_stmt->bind_param('i', $session_id);
    $joined_stmt->execute();
    $joined = (int) $joined_stmt
        ->get_result()
        ->fetch_assoc()['joined_count'];

    $answered_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT r.student_pk) AS answered_count
        FROM responses r
        JOIN device_sessions ds
            ON ds.session_id = r.session_id
           AND ds.student_pk = r.student_pk
        WHERE r.session_id = ?
          AND r.question_id = ?
    ");
    $answered_stmt->bind_param(
        'ii',
        $session_id,
        $current_question_id
    );
    $answered_stmt->execute();
    $answered = (int) $answered_stmt
        ->get_result()
        ->fetch_assoc()['answered_count'];
    $waiting = max(0, $joined - $answered);

    sendQuestionStatusJson(200, [
        'status' => (string) $live_session['status'],
        'question_id' => $current_question_id,
        'joined' => $joined,
        'answered' => $answered,
        'waiting' => $waiting
    ]);
} catch (Throwable $exception) {
    error_log(
        'Question status failed for teacher '
        . $teacher_id
        . ': '
        . $exception->getMessage()
    );
    sendQuestionStatusError(
        500,
        'server_error',
        'Unable to load question status.'
    );
}
