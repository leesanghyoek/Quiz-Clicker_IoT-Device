<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = $_SESSION['teacher_id'];


// Chỉ chấp nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: classes.php");
    exit;
}


// Lấy student record ID
$student_pk = isset($_POST['student_id'])
    ? (int) $_POST['student_id']
    : 0;

if ($student_pk <= 0) {
    header("Location: classes.php?error=invalid_student");
    exit;
}


// Tìm student và kiểm tra ownership
$stmt = $conn->prepare("
    SELECT
        s.id,
        s.class_id
    FROM students s
    JOIN classes c
        ON s.class_id = c.class_id
    WHERE s.id = ?
      AND c.teacher_id = ?
");

$stmt->bind_param(
    "ii",
    $student_pk,
    $teacher_id
);

$stmt->execute();

$result = $stmt->get_result();


// Không tìm thấy hoặc student thuộc teacher khác
if ($result->num_rows !== 1) {
    header("Location: classes.php?error=access");
    exit;
}

$student = $result->fetch_assoc();

$class_id = $student['class_id'];


// Answers and joins point at the student; keep them rather than fail on the foreign keys.
$stmt = $conn->prepare("
    SELECT
        EXISTS (SELECT 1 FROM responses WHERE student_pk = ?)
        OR EXISTS (SELECT 1 FROM device_sessions WHERE student_pk = ?) AS has_history
");

$stmt->bind_param(
    "ii",
    $student_pk,
    $student_pk
);

$stmt->execute();

if ((int) $stmt->get_result()->fetch_assoc()['has_history'] === 1) {
    header(
        "Location: class.php?id="
        . $class_id
        . "&tab=students&error=history"
    );

    exit;
}


// Delete student
$stmt = $conn->prepare("
    DELETE FROM students
    WHERE id = ?
");

$stmt->bind_param(
    "i",
    $student_pk
);

if ($stmt->execute()) {

    header(
        "Location: class.php?id="
        . $class_id
        . "&tab=students&deleted=1"
    );

    exit;

} else {

    header(
        "Location: class.php?id="
        . $class_id
        . "&tab=students&error=delete"
    );

    exit;
}
?>
