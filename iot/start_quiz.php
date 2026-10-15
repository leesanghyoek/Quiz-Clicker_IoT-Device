<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/quiz_lock.php';

$teacher_id = (int) $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: quizzes.php');
    exit;
}

$session_id = (int) ($_POST['session_id'] ?? 0);
$posted_csrf_token = $_POST['csrf_token'] ?? '';
$session_csrf_token = $_SESSION['start_quiz_csrf_token'] ?? '';

if ($session_id <= 0) {
    header('Location: quizzes.php');
    exit;
}

if (
    !is_string($posted_csrf_token)
    || !is_string($session_csrf_token)
    || $session_csrf_token === ''
    || !hash_equals($session_csrf_token, $posted_csrf_token)
) {
    header('Location: lobby.php?session_id=' . $session_id . '&error=csrf');
    exit;
}

$transaction_started = false;

try {
    $conn->begin_transaction();
    $transaction_started = true;

    $stmt = $conn->prepare("
        SELECT ls.session_id, ls.quiz_id, ls.status
        FROM live_sessions ls
        JOIN quizzes q
            ON q.quiz_id = ls.quiz_id
        JOIN classes c
            ON c.class_id = ls.class_id
        WHERE ls.session_id = ?
          AND q.teacher_id = ?
          AND c.teacher_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('iii', $session_id, $teacher_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        header('Location: lobby.php?session_id=' . $session_id . '&error=access');
        exit;
    }

    $live_session = $result->fetch_assoc();

    if ($live_session['status'] !== 'WAITING') {
        $conn->rollback();
        $transaction_started = false;
        header('Location: lobby.php?session_id=' . $session_id . '&error=status');
        exit;
    }

    $quiz_id = (int) $live_session['quiz_id'];
    $quiz_state = loadOwnedQuizEditState(
        $conn,
        $quiz_id,
        $teacher_id,
        true
    );

    if ($quiz_state === null) {
        $conn->rollback();
        $transaction_started = false;
        header('Location: lobby.php?session_id=' . $session_id . '&error=access');
        exit;
    }

    $question_stmt = $conn->prepare("
        SELECT question_id
        FROM questions
        WHERE quiz_id = ?
        ORDER BY question_order ASC, question_id ASC
        LIMIT 1
    ");
    $question_stmt->bind_param('i', $quiz_id);
    $question_stmt->execute();
    $question_result = $question_stmt->get_result();

    if ($question_result->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        header(
            'Location: lobby.php?session_id='
            . $session_id
            . '&error=no_questions'
        );
        exit;
    }

    $first_question_id = (int) $question_result
        ->fetch_assoc()['question_id'];

    $stmt = $conn->prepare("
        UPDATE live_sessions
        SET
            status = 'ACTIVE',
            started_at = NOW(),
            current_question_id = ?
        WHERE session_id = ?
          AND status = 'WAITING'
    ");
    $stmt->bind_param('ii', $first_question_id, $session_id);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('Live session was not started.');
    }

    $conn->commit();
    $transaction_started = false;
    unset($_SESSION['start_quiz_csrf_token']);

    header('Location: quiz_run.php?session_id=' . $session_id);
    exit;
} catch (Throwable $exception) {
    if ($transaction_started) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_exception) {
            error_log(
                'Start quiz rollback failed for teacher '
                . $teacher_id
                . ': '
                . $rollback_exception->getMessage()
            );
        }
    }

    error_log(
        'Start quiz failed for teacher '
        . $teacher_id
        . ': '
        . $exception->getMessage()
    );
    header('Location: lobby.php?session_id=' . $session_id . '&error=start');
    exit;
}
