<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = $_SESSION['teacher_id'];
$error = '';

$class_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Lấy class nhưng bắt buộc phải thuộc teacher hiện tại
$stmt = $conn->prepare("
    SELECT class_id, name
    FROM classes
    WHERE class_id = ?
      AND teacher_id = ?
");

$stmt->bind_param("ii", $class_id, $teacher_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Class not found or access denied.");
}

$class = $result->fetch_assoc();


// Khi nhấn Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $class_name = trim($_POST['class_name'] ?? '');

    if ($class_name === '') {

        $error = "Class name is required.";

    } else {

        // Kiểm tra teacher có class khác trùng tên chưa
        $stmt = $conn->prepare("
            SELECT class_id
            FROM classes
            WHERE teacher_id = ?
              AND name = ?
              AND class_id != ?
        ");

        $stmt->bind_param(
            "isi",
            $teacher_id,
            $class_name,
            $class_id
        );

        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {

            $error = "This class name already exists.";

        } else {

            // Update nhưng vẫn kiểm tra teacher_id
            $stmt = $conn->prepare("
                UPDATE classes
                SET name = ?
                WHERE class_id = ?
                  AND teacher_id = ?
            ");

            $stmt->bind_param(
                "sii",
                $class_name,
                $class_id,
                $teacher_id
            );

            $stmt->execute();

            header("Location: classes.php?updated=1");
            exit;
        }
    }
}
?>

<?php
$page_title = 'Edit Class';
$page_styles = ['assets/css/classes.css?v=20261005'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <div class="form-card">

        <h1>Edit Class</h1>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="form-group">

                <label>Class Name</label>

                <input
                    type="text"
                    name="class_name"
                    value="<?= htmlspecialchars($class['name']) ?>"
                    required
                >

            </div>

            <div class="form-actions">

                <a href="classes.php" class="cancel-button">
                    Cancel
                </a>

                <button type="submit" class="save-button">
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>

<?php include 'includes/layout_end.php'; ?>
