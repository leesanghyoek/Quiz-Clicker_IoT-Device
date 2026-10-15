<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/quiz_lock.php';

$teacher_id = $_SESSION['teacher_id'];


// =====================================
// 1. POST ONLY
// =====================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header("Location: quizzes.php");
    exit;
}


// =====================================
// 2. QUESTION ID
// =====================================

$question_id = isset($_POST['question_id'])
    ? (int) $_POST['question_id']
    : 0;

if ($question_id <= 0) {

    header("Location: quizzes.php");
    exit;
}


// =====================================
// 3. DELETE SAFELY IN ONE TRANSACTION
// =====================================

$quiz_id = 0;
$conn->begin_transaction();

try {

    // Lock question and verify ownership inside the transaction.
    $stmt = $conn->prepare("
        SELECT
            q.question_id,
            q.quiz_id,
            q.question_order
        FROM questions q
        JOIN quizzes qu
            ON q.quiz_id = qu.quiz_id
        WHERE q.question_id = ?
          AND qu.teacher_id = ?
        FOR UPDATE
    ");

    $stmt->bind_param(
        "ii",
        $question_id,
        $teacher_id
    );
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $conn->rollback();
        header("Location: quizzes.php?error=access");
        exit;
    }

    $question = $result->fetch_assoc();
    $quiz_id = (int) $question['quiz_id'];
    $deleted_order = (int) $question['question_order'];

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

    // Delete child rows first to satisfy the foreign key.
    $stmt = $conn->prepare("
        DELETE FROM question_options
        WHERE question_id = ?
    ");
    $stmt->bind_param("i", $question_id);
    $stmt->execute();

    // Delete the question after all of its options are gone.
    $stmt = $conn->prepare("
        DELETE FROM questions
        WHERE question_id = ?
          AND quiz_id = ?
    ");
    $stmt->bind_param(
        "ii",
        $question_id,
        $quiz_id
    );
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException(
            "Question could not be deleted."
        );
    }

    // Close the ordering gap left by the deleted question.
    $stmt = $conn->prepare("
        UPDATE questions
        SET question_order = question_order - 1
        WHERE quiz_id = ?
          AND question_order > ?
    ");
    $stmt->bind_param(
        "ii",
        $quiz_id,
        $deleted_order
    );
    $stmt->execute();
    $conn->commit();

    header(
        "Location: quiz.php?id="
        . $quiz_id
        . "&deleted=1"
    );

    exit;

} catch (Throwable $e) {

    $conn->rollback();

    if ($quiz_id > 0) {
        header(
            "Location: quiz.php?id="
            . $quiz_id
            . "&error=delete"
        );
    } else {
        header("Location: quizzes.php?error=delete");
    }

    exit;
}
?>
