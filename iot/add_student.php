<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/student_rules.php';

$teacher_id = $_SESSION['teacher_id'];
$error = '';


// ============================
// 1. LẤY CLASS_ID TỪ URL
// ============================

$class_id = isset($_GET['class_id'])
    ? (int) $_GET['class_id']
    : 0;

if ($class_id <= 0) {
    die("Invalid class ID.");
}


// ============================
// 2. CHECK OWNERSHIP
// ============================

$stmt = $conn->prepare("
    SELECT class_id, name
    FROM classes
    WHERE class_id = ?
      AND teacher_id = ?
");

$stmt->bind_param(
    "ii",
    $class_id,
    $teacher_id
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Class not found or access denied.");
}

$class = $result->fetch_assoc();


// ============================
// 3. HANDLE POST
// ============================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $student_id = normalizeStudentNumber((string) ($_POST['student_id'] ?? ''));
    $student_mssv = normalizeMssv((string) ($_POST['student_mssv'] ?? ''));
    $student_name = normalizeStudentName((string) ($_POST['student_name'] ?? ''));


    // Validate required
    if ($student_id === '') {

        $error = "STT is required.";

    } elseif (!isValidStudentNumber($student_id)) {

        $error = "STT must be " . STUDENT_NUMBER_HINT . ".";

    } elseif (!isValidMssv($student_mssv)) {

        $error = "MSSV can only hold letters, digits and . _ - / (at most 20).";

    } elseif ($student_name === '') {

        $error = "Student name is required.";

    } elseif (studentNameIsTooLong($student_name)) {

        $error = "Student name is longer than 100 characters.";

    } else {

        // ============================
        // 4. CHECK DUPLICATE
        // ============================

        $stmt = $conn->prepare("
            SELECT student_id, mssv
            FROM students
            WHERE class_id = ?
              AND (student_id = ? OR (mssv <> '' AND mssv = ?))
        ");

        $stmt->bind_param(
            "iss",
            $class_id,
            $student_id,
            $student_mssv
        );

        $stmt->execute();

        $duplicate_result = $stmt->get_result()->fetch_assoc();

        if ($duplicate_result !== null && $duplicate_result['student_id'] === $student_id) {

            $error = "STT " . $student_id . " already exists in this class.";

        } elseif ($duplicate_result !== null) {

            $error = "MSSV " . $student_mssv . " already belongs to STT "
                . $duplicate_result['student_id'] . " in this class.";

        } else {

            // ============================
            // 5. INSERT STUDENT
            // ============================

            $stmt = $conn->prepare("
                INSERT INTO students
                    (class_id, student_id, mssv, name)
                VALUES
                    (?, ?, ?, ?)
            ");

            $mssv_value = $student_mssv !== '' ? $student_mssv : null;

            $stmt->bind_param(
                "isss",
                $class_id,
                $student_id,
                $mssv_value,
                $student_name
            );

            if ($stmt->execute()) {

                header(
                    "Location: class.php?id="
                    . $class_id
                    . "&tab=students&added=1"
                );

                exit;

            } else {

                $error = "Failed to add student.";
            }
        }
    }
}
?>


<?php
$page_title = 'Add Student';
$page_styles = ['assets/css/students.css?v=20261005'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>


<div class="main-content management-page">

    <div class="form-card">

        <h1>
            Add Student to
            <?= htmlspecialchars($class['name']) ?>
        </h1>


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
                        $_POST['student_id'] ?? ''
                    ) ?>"
                    placeholder="01"
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
                        $_POST['student_mssv'] ?? ''
                    ) ?>"
                    placeholder="23134001"
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
                        $_POST['student_name'] ?? ''
                    ) ?>"
                    placeholder="Nguyễn Văn A"
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
                    Add Student
                </button>

            </div>

        </form>

    </div>

</div>

<?php include 'includes/layout_end.php'; ?>
