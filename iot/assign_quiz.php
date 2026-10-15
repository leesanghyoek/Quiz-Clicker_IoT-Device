<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = $_SESSION['teacher_id'];
$error = '';

$quiz_id = isset($_GET['quiz_id'])
    ? (int) $_GET['quiz_id']
    : 0;

if ($quiz_id <= 0) {
    die("Invalid quiz ID.");
}

$stmt = $conn->prepare("
    SELECT quiz_id, title
    FROM quizzes
    WHERE quiz_id = ?
      AND teacher_id = ?
");
$stmt->bind_param("ii", $quiz_id, $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Quiz not found or access denied.");
}

$quiz = $result->fetch_assoc();

$stmt = $conn->prepare("
    SELECT class_id, name
    FROM classes
    WHERE teacher_id = ?
    ORDER BY name
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$classes_result = $stmt->get_result();

$classes = [];
$allowed_class_ids = [];

while ($class = $classes_result->fetch_assoc()) {
    $class_id = (int) $class['class_id'];

    $classes[] = [
        'class_id' => $class_id,
        'name' => $class['name']
    ];
    $allowed_class_ids[$class_id] = true;
}

$stmt = $conn->prepare("
    SELECT cq.class_id
    FROM class_quizzes cq
    JOIN classes c
        ON c.class_id = cq.class_id
    WHERE cq.quiz_id = ?
      AND c.teacher_id = ?
");
$stmt->bind_param("ii", $quiz_id, $teacher_id);
$stmt->execute();
$assigned_result = $stmt->get_result();

$selected_class_ids = [];

while ($assignment = $assigned_result->fetch_assoc()) {
    $selected_class_ids[] = (int) $assignment['class_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_class_ids = $_POST['class_ids'] ?? [];

    if (!is_array($posted_class_ids)) {
        $error = "Invalid class selection.";
    } else {
        $selected_class_ids = [];

        foreach ($posted_class_ids as $posted_class_id) {
            $class_id = filter_var(
                $posted_class_id,
                FILTER_VALIDATE_INT
            );

            if (
                $class_id === false
                || !isset($allowed_class_ids[$class_id])
            ) {
                $error = "One or more selected classes are invalid.";
                break;
            }

            $selected_class_ids[$class_id] = $class_id;
        }

        $selected_class_ids = array_values(
            $selected_class_ids
        );
    }

    if ($error === '') {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                DELETE FROM class_quizzes
                WHERE quiz_id = ?
            ");
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();

            if (!empty($selected_class_ids)) {
                $insert_stmt = $conn->prepare("
                    INSERT INTO class_quizzes
                        (class_id, quiz_id)
                    VALUES
                        (?, ?)
                ");

                foreach ($selected_class_ids as $class_id) {
                    $insert_stmt->bind_param(
                        "ii",
                        $class_id,
                        $quiz_id
                    );
                    $insert_stmt->execute();
                }
            }

            $conn->commit();

            header(
                "Location: quiz.php?id="
                . $quiz_id
                . "&assigned=1"
            );
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            $error = "Failed to update class assignments.";
        }
    }
}
?>

<?php
$page_title = 'Assign Quiz to Classes';
$page_styles = ['assets/css/quiz.css?v=20261005-4'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <div class="form-card assignment-card">

        <h1>Assign Quiz</h1>

        <p>
            Choose the classes that can use
            <strong><?= htmlspecialchars($quiz['title']) ?></strong>.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($classes)): ?>

            <form method="POST">

                <div class="assignment-list">

                    <?php foreach ($classes as $class): ?>
                        <?php
                        $is_selected = in_array(
                            $class['class_id'],
                            $selected_class_ids,
                            true
                        );
                        ?>

                        <label class="assignment-option">
                            <input
                                type="checkbox"
                                name="class_ids[]"
                                value="<?= $class['class_id'] ?>"
                                <?= $is_selected ? 'checked' : '' ?>
                            >

                            <span>
                                <strong>
                                    <?= htmlspecialchars($class['name']) ?>
                                </strong>

                                <small>
                                    <?= $is_selected
                                        ? 'Currently assigned'
                                        : 'Not assigned'
                                    ?>
                                </small>
                            </span>
                        </label>
                    <?php endforeach; ?>

                </div>

                <p class="assignment-hint">
                    Uncheck every class to remove this quiz from all classes.
                </p>

                <div class="form-actions">
                    <a
                        href="quiz.php?id=<?= $quiz_id ?>"
                        class="cancel-button"
                    >
                        Cancel
                    </a>

                    <button type="submit" class="save-button">
                        Save Assignments
                    </button>
                </div>

            </form>

        <?php else: ?>

            <div class="empty-state">
                <h3>No classes available</h3>

                <p>
                    Create a class before assigning this quiz.
                </p>

                <a href="add_class.php" class="add-button">
                    + Create Class
                </a>
            </div>

            <div class="form-actions">
                <a
                    href="quiz.php?id=<?= $quiz_id ?>"
                    class="cancel-button"
                >
                    Back to Quiz
                </a>
            </div>

        <?php endif; ?>

    </div>

</div>

<?php include 'includes/layout_end.php'; ?>
