<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = $_SESSION['teacher_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title = trim($_POST['title'] ?? '');

    // Validate
    if ($title === '') {

        $error = "Quiz title is required.";

    } else {

        // Tạo Quiz mới
        $stmt = $conn->prepare("
            INSERT INTO quizzes
                (teacher_id, title)
            VALUES
                (?, ?)
        ");

        $stmt->bind_param(
            "is",
            $teacher_id,
            $title
        );

        if ($stmt->execute()) {

            // Lấy ID của Quiz vừa được tạo
            $quiz_id = $conn->insert_id;

            header(
                "Location: quiz.php?id=" . $quiz_id
            );

            exit;

        } else {

            $error = "Failed to create quiz.";
        }
    }
}
?>

<?php
$page_title = 'Create Quiz';
$page_styles = ['assets/css/quizzes.css?v=20261005'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <div class="form-card">

        <h1>Create Quiz</h1>

        <?php if ($error !== ''): ?>

            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">

                <label>
                    Quiz Title
                </label>

                <input
                    type="text"
                    name="title"
                    placeholder="WiFi Fundamentals"
                    value="<?= htmlspecialchars(
                        $_POST['title'] ?? ''
                    ) ?>"
                    required
                >

            </div>

            <div class="form-actions">

                <a
                    href="quizzes.php"
                    class="cancel-button"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="save-button"
                >
                    Create Quiz
                </button>

            </div>

        </form>

    </div>

</div>

<?php include 'includes/layout_end.php'; ?>
