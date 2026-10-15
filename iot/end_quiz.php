<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = (int) $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: quizzes.php');
    exit;
}

$session_id = (int) ($_POST['session_id'] ?? 0);
$posted_csrf_token = $_POST['csrf_token'] ?? '';
$session_csrf_token = $_SESSION['end_quiz_csrf_token'] ?? '';

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
    header('Location: quiz_run.php?session_id=' . $session_id . '&error=csrf');
    exit;
}

$transaction_started = false;

try {
    $conn->begin_transaction();
    $transaction_started = true;

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
        FOR UPDATE
    ");
    $stmt->bind_param('iii', $session_id, $teacher_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $conn->rollback();
        $transaction_started = false;
        header('Location: quizzes.php');
        exit;
    }

    $live_session = $result->fetch_assoc();

    if ($live_session['status'] !== 'ACTIVE') {
        $conn->rollback();
        $transaction_started = false;
        header('Location: quiz_run.php?session_id=' . $session_id . '&error=status');
        exit;
    }

    $quiz_id = (int) $live_session['quiz_id'];
    $current_question_id = (int) $live_session['current_question_id'];

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
        $conn->rollback();
        $transaction_started = false;
        header('Location: quiz_run.php?session_id=' . $session_id . '&error=question');
        exit;
    }

    $update_stmt = $conn->prepare("
        UPDATE live_sessions
        SET
            status = 'ENDED',
            ended_at = NOW()
        WHERE session_id = ?
          AND status = 'ACTIVE'
          AND current_question_id = ?
    ");
    $update_stmt->bind_param('ii', $session_id, $current_question_id);
    $update_stmt->execute();

    if ($update_stmt->affected_rows !== 1) {
        throw new RuntimeException('The active quiz could not be ended.');
    }

    $conn->commit();
    $transaction_started = false;
    unset($_SESSION['end_quiz_csrf_token']);

    header('Location: results.php?session_id=' . $session_id);
    exit;
} catch (Throwable $exception) {
    if ($transaction_started) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_exception) {
            error_log(
                'End quiz rollback failed for teacher '
                . $teacher_id
                . ': '
                . $rollback_exception->getMessage()
            );
        }
    }

    error_log(
        'End quiz failed for teacher '
        . $teacher_id
        . ': '
        . $exception->getMessage()
    );
    header('Location: quiz_run.php?session_id=' . $session_id . '&error=end');
    exit;
}
