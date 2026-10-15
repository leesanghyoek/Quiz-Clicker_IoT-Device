<?php
/*
 * Cancel a quiz that is still waiting in the lobby. Nothing has been asked
 * yet, so the session is removed completely, along with the list of devices
 * that joined it; it never shows up in results. A running quiz is ended from
 * its run page instead, which keeps the answers.
 */
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = (int) $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$session_id = (int) ($_POST['session_id'] ?? 0);
$posted_token = $_POST['csrf_token'] ?? '';
$expected_token = $_SESSION['cancel_session_csrf_token'] ?? '';

if (
    $session_id <= 0
    || !is_string($posted_token)
    || $expected_token === ''
    || !hash_equals($expected_token, $posted_token)
) {
    header('Location: dashboard.php?live_error=token#live-now');
    exit;
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->begin_transaction();

    $stmt = $conn->prepare("
        SELECT ls.status
        FROM live_sessions ls
        JOIN quizzes q ON q.quiz_id = ls.quiz_id
        JOIN classes c ON c.class_id = ls.class_id
        WHERE ls.session_id = ?
          AND q.teacher_id = ?
          AND c.teacher_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('iii', $session_id, $teacher_id, $teacher_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();

    if ($session === null || $session['status'] !== 'WAITING') {
        $conn->rollback();
        header('Location: dashboard.php?live_error=started#live-now');
        exit;
    }

    // A waiting session has no answers yet; only the joins have to go.
    $stmt = $conn->prepare('DELETE FROM device_sessions WHERE session_id = ?');
    $stmt->bind_param('i', $session_id);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM live_sessions WHERE session_id = ? AND status = 'WAITING'");
    $stmt->bind_param('i', $session_id);
    $stmt->execute();

    $conn->commit();
    header('Location: dashboard.php?cancelled=1#live-now');
    exit;
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $rollback_exception) {
        error_log('Cancel session rollback failed: ' . $rollback_exception->getMessage());
    }

    error_log('Cancel session ' . $session_id . ' failed: ' . $exception->getMessage());
    header('Location: dashboard.php?live_error=server#live-now');
    exit;
}
