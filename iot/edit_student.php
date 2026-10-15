<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/student_rules.php';

$teacher_id = $_SESSION['teacher_id'];
$error = '';


// =====================================
// 1. LẤY STUDENT RECORD ID TỪ URL
// =====================================

$student_pk = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($student_pk <= 0) {
    die("Invalid student ID.");
}


// =====================================
// 2. LOAD STUDENT + CHECK OWNERSHIP
// =====================================

$stmt = $conn->prepare("
    SELECT
        s.id,
        s.student_id,
        s.mssv,
        s.name,
        s.class_id,
        c.name AS class_name
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

if ($result->num_rows !== 1) {
    die("Student not found or access denied.");
}

$student = $result->fetch_assoc();

$class_id = $student['class_id'];


// =====================================
// 3. HANDLE UPDATE
// =====================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $new_student_id =
        normalizeStudentNumber((string) ($_POST['student_id'] ?? ''));

    $new_mssv =
        normalizeMssv((string) ($_POST['student_mssv'] ?? ''));

    $new_name =
        normalizeStudentName((string) ($_POST['student_name'] ?? ''));


    // Validate
    if ($new_student_id === '') {

        $error = "STT is required.";

    } elseif (!isValidStudentNumber($new_student_id)) {

        $error = "STT must be " . STUDENT_NUMBER_HINT . ".";

    } elseif (!isValidMssv($new_mssv)) {

        $error = "MSSV can only hold letters, digits and . _ - / (at most 20).";

    } elseif ($new_name === '') {

        $error = "Student name is required.";

    } elseif (studentNameIsTooLong($new_name)) {

        $error = "Student name is longer than 100 characters.";

    } else {

        // =====================================
        // 4. CHECK DUPLICATE STUDENT ID
        // =====================================

        $stmt = $conn->prepare("
            SELECT student_id
            FROM students
            WHERE class_id = ?
              AND (student_id = ? OR (mssv <> '' AND mssv = ?))
              AND id != ?
        ");

        $stmt->bind_param(
            "issi",
            $class_id,
            $new_student_id,
            $new_mssv,
            $student_pk
        );

        $stmt->execute();

        $duplicate = $stmt->get_result()->fetch_assoc();

        if ($duplicate !== null && $duplicate['student_id'] === $new_student_id) {

            $error = "STT " . $new_student_id . " already exists in this class.";

        } elseif ($duplicate !== null) {

            $error = "MSSV " . $new_mssv . " already belongs to STT "
                . $duplicate['student_id'] . " in this class.";

        } else {

            // =====================================
            // 5. UPDATE
            // =====================================

            $stmt = $conn->prepare("
                UPDATE students
                SET
                    student_id = ?,
                    mssv = ?,
                    name = ?
                WHERE id = ?
            ");

            $mssv_value = $new_mssv !== '' ? $new_mssv : null;

            $stmt->bind_param(
                "sssi",
                $new_student_id,
                $mssv_value,
                $new_name,
                $student_pk
            );

            if ($stmt->execute()) {

                header(
                    "Location: class.php?id="
                    . $class_id
                    . "&tab=students&updated=1"
                );

                exit;

            } else {

                $error = "Failed to update student.";
            }
        }
    }
}
?>


<?php
$page_title = 'Edit Student';
$page_styles = ['assets/css/students.css?v=20261005'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>


<div class="main-content management-page">

    <div class="form-card">

        <h1>
            Edit Student
        </h1>

        <p>
            Class:
            <?= htmlspecialchars($student['class_name']) ?>
        </p>


        <?php if ($error !== ''): ?>

            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <form method="POST">

            <div class="form-group">

                <label for="student-number">
                    STT (number typed on the answer device)
                </label>

                <input
                    type="text"
                    id="student-number"
                    name="student_id"
                    value="<?= htmlspecialchars(
                        $_POST['student_id']
                        ?? $student['student_id']
                    ) ?>"
                    inputmode="numeric"
                    pattern="\d{1,2}"
                    maxlength="2"
                    title="One or two digits, 0 to 99"
                    required
                >

            </div>


            <div class="form-group">

                <label for="student-mssv">
                    MSSV (optional)
                </label>

                <input
                    type="text"
                    id="student-mssv"
                    name="student_mssv"
                    value="<?= htmlspecialchars(
                        $_POST['student_mssv']
                        ?? (string) ($student['mssv'] ?? '')
                    ) ?>"
                    maxlength="20"
                >

            </div>


            <div class="form-group">

                <label>
                    Student Name
                </label>

                <input
                    type="text"
                    name="student_name"
                    value="<?= htmlspecialchars(
                        $_POST['student_name']
                        ?? $student['name']
                    ) ?>"
                    required
                >

            </div>


            <div class="form-actions">

                <a
                    href="class.php?id=<?= $class_id ?>&tab=students"
                    class="cancel-button"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="save-button"
                >
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>

<?php include 'includes/layout_end.php'; ?>
