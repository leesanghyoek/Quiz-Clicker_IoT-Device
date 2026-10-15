<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: classes.php?error=delete");
    exit;
}

$class_id = isset($_POST['class_id'])
    ? (int) $_POST['class_id']
    : 0;

if ($class_id <= 0) {
    header("Location: classes.php?error=delete");
    exit;
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        SELECT class_id
        FROM classes
        WHERE class_id = ?
          AND teacher_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param("ii", $class_id, $teacher_id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows !== 1) {
        $conn->rollback();
        header("Location: classes.php?error=access");
        exit;
    }

    $stmt = $conn->prepare("
        DELETE r
        FROM responses r
        JOIN students s
            ON s.id = r.student_pk
        WHERE s.class_id = ?
    ");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        DELETE FROM class_quizzes
        WHERE class_id = ?
    ");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        DELETE FROM students
        WHERE class_id = ?
    ");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        DELETE FROM classes
        WHERE class_id = ?
          AND teacher_id = ?
    ");
    $stmt->bind_param("ii", $class_id, $teacher_id);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException("Class could not be deleted.");
    }

    $conn->commit();

    header("Location: classes.php?deleted=1");
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    header("Location: classes.php?error=delete");
    exit;
}
?>

