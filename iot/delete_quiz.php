<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/quiz_lock.php';

$teacher_id = $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: quizzes.php");
    exit;
}

$quiz_id = isset($_POST['quiz_id'])
    ? (int) $_POST['quiz_id']
    : 0;

if ($quiz_id <= 0) {
    header("Location: quizzes.php?error=invalid");
    exit;
}

$conn->begin_transaction();

try {
    $quiz_state = loadOwnedQuizEditState(
        $conn,
        $quiz_id,
        (int) $teacher_id,
        true
    );

    if ($quiz_state === null) {
        $conn->rollback();
        header("Location: quizzes.php?error=access");
        exit;
    }

    if ($quiz_state['is_locked']) {
        $conn->rollback();
        header('Location: quiz.php?id=' . $quiz_id . '&error=locked');
        exit;
    }

    $stmt = $conn->prepare("
        DELETE r
        FROM responses r
        LEFT JOIN live_sessions ls
            ON ls.session_id = r.session_id
        LEFT JOIN questions q
            ON q.question_id = r.question_id
        WHERE ls.quiz_id = ?
           OR q.quiz_id = ?
    ");
    $stmt->bind_param("ii", $quiz_id, $quiz_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        DELETE FROM live_sessions
        WHERE quiz_id = ?
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        DELETE FROM class_quizzes
        WHERE quiz_id = ?
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        DELETE qo
        FROM question_options qo
        JOIN questions q
            ON q.question_id = qo.question_id
        WHERE q.quiz_id = ?
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        DELETE FROM questions
        WHERE quiz_id = ?
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        DELETE FROM quizzes
        WHERE quiz_id = ?
          AND teacher_id = ?
    ");
    $stmt->bind_param("ii", $quiz_id, $teacher_id);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException("Quiz could not be deleted.");
    }

    $conn->commit();

    header("Location: quizzes.php?deleted=1");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: quizzes.php?error=delete");
    exit;
}
?>
