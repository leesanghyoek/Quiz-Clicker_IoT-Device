<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$error = '';

$teacher_id = $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $class_name = trim($_POST['class_name'] ?? '');

    if ($class_name === '') {
        $error = "Class name is required.";
    } else {

        // Kiểm tra class trùng trong phạm vi teacher hiện tại
        $stmt = $conn->prepare("
            SELECT class_id
            FROM classes
            WHERE teacher_id = ?
              AND name = ?
        ");

        $stmt->bind_param("is", $teacher_id, $class_name);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            $error = "This class already exists.";

        } else {

            $stmt = $conn->prepare("
                INSERT INTO classes
                    (teacher_id, name)
                VALUES
                    (?, ?)
            ");

            $stmt->bind_param(
                "is",
                $teacher_id,
                $class_name
            );

            if ($stmt->execute()) {

                header("Location: import_students.php?class_id=" . (int) $conn->insert_id . "&created=1");
                exit;

            } else {

                $error = "Failed to create class.";
            }
        }
    }
}
?>

<?php
$page_title = 'Create Class';
$page_styles = ['assets/css/classes.css?v=20261005'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <div class="form-card">

        <h1>Create Class</h1>

        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="add_class.php">

            <div class="form-group">

                <label for="class_name">
                    Class Name
                </label>

                <input
                    type="text"
                    id="class_name"
                    name="class_name"
                    placeholder="10A1"
                    required
                >

            </div>

            <div class="form-actions">

                <a
                    href="classes.php"
                    class="cancel-button"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="save-button"
                >
                    Create
                </button>

            </div>

        </form>

    </div>

</div>

<?php include 'includes/layout_end.php'; ?>
