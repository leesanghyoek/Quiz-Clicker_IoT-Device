<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = $_SESSION['teacher_id'];


// Lấy tất cả Quiz của teacher hiện tại
$stmt = $conn->prepare("
    SELECT
        q.quiz_id,
        q.title,

        COUNT(DISTINCT qu.question_id) AS total_questions,

        CASE
            WHEN COUNT(DISTINCT started_session.session_id) > 0 THEN 1
            ELSE 0
        END AS is_locked,

        GROUP_CONCAT(
            DISTINCT c.name
            ORDER BY c.name
            SEPARATOR ', '
        ) AS assigned_classes

    FROM quizzes q

    LEFT JOIN questions qu
        ON q.quiz_id = qu.quiz_id

    LEFT JOIN class_quizzes cq
        ON q.quiz_id = cq.quiz_id

    LEFT JOIN classes c
        ON cq.class_id = c.class_id

    LEFT JOIN live_sessions started_session
        ON started_session.quiz_id = q.quiz_id
       AND started_session.started_at IS NOT NULL

    WHERE q.teacher_id = ?

    GROUP BY
        q.quiz_id,
        q.title

    ORDER BY q.created_at DESC
");

$stmt->bind_param("i", $teacher_id);
$stmt->execute();

$quizzes = $stmt->get_result();
?>

<?php
$page_title = 'My Quizzes';
$page_styles = ['assets/css/quizzes.css?v=20261006'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>


<main class="main-content management-page">

    <?php if (isset($_GET['deleted'])): ?>
        <div class="success-message">
            Quiz deleted successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="error-message">
            Failed to delete quiz.
        </div>
    <?php endif; ?>

    <header class="page-header management-page-header">

        <div class="page-header-main">
            <h1>My Quizzes</h1>
            <p>Create, assign and launch engaging classroom quizzes.</p>
        </div>

        <div class="page-header-actions">
            <a
                href="ai_quiz.php"
                class="secondary-button"
            >
                <i class="ti ti-sparkles" aria-hidden="true"></i>
                Create with AI
            </a>

            <a
                href="import_quiz.php"
                class="secondary-button"
            >
                <i class="ti ti-file-import" aria-hidden="true"></i>
                Import Quiz
            </a>

            <a
                href="create_quiz.php"
                class="add-button"
            >
                <i class="ti ti-plus" aria-hidden="true"></i>
                Create Quiz
            </a>
        </div>

    </header>


    <section class="collection-panel" aria-labelledby="quiz-library-heading">
        <div class="collection-panel-header">
            <div>
                <span class="section-kicker">Your content</span>
                <h2 id="quiz-library-heading">Quiz Library</h2>
                <p>Choose a quiz to launch or continue editing.</p>
            </div>

            <span class="count-badge">
                <?= (int) $quizzes->num_rows ?>
                <?= $quizzes->num_rows === 1 ? 'quiz' : 'quizzes' ?>
            </span>
        </div>

    <?php if ($quizzes->num_rows > 0): ?>

        <section class="management-card-grid quiz-management-grid" aria-label="Quiz library">

            <?php while ($quiz = $quizzes->fetch_assoc()): ?>

                <article
                    class="management-card quiz-management-card"
                    data-card-href="quiz.php?id=<?= (int) $quiz['quiz_id'] ?>"
                    tabindex="0"
                    role="link"
                    aria-label="Open quiz <?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?>"
                >

                    <span class="quiz-card-accent" aria-hidden="true"></span>

                    <?php if ((int) $quiz['is_locked'] !== 1): ?>
                        <button
                            type="button"
                            class="icon-button quiz-menu-button"
                            onclick="toggleQuizMenu(<?= $quiz['quiz_id'] ?>)"
                            aria-label="Quiz actions"
                        >
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>

                        <div
                            class="action-menu quiz-menu"
                            id="quiz-menu-<?= $quiz['quiz_id'] ?>"
                        >
                            <button
                                type="button"
                                onclick="openDeleteQuizDialog(<?= $quiz['quiz_id'] ?>)"
                            >
                                <i class="ti ti-trash" aria-hidden="true"></i>
                                Delete Quiz
                            </button>
                        </div>
                    <?php endif; ?>

                    <div class="quiz-card-heading">
                        <span class="card-icon quiz-card-icon" aria-hidden="true">
                            <i class="ti ti-clipboard-text"></i>
                        </span>

                        <div>
                            <span class="card-category">Classroom quiz</span>
                            <h2><?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>
                    </div>

                    <div class="quiz-card-meta">
                        <span class="meta-badge meta-badge-blue">
                            <i class="ti ti-list-check"></i>
                            <?= (int) $quiz['total_questions'] ?>
                            <?= (int) $quiz['total_questions'] === 1 ? 'question' : 'questions' ?>
                        </span>

                        <?php if ((int) $quiz['is_locked'] === 1): ?>
                            <span class="meta-badge quiz-lock-badge">
                                <i class="ti ti-lock"></i>
                                Content locked
                            </span>
                        <?php endif; ?>

                        <span
                            class="meta-badge meta-badge-teal"
                            title="<?= htmlspecialchars((string) $quiz['assigned_classes'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <i class="ti ti-school"></i>
                            <?= !empty($quiz['assigned_classes'])
                                ? htmlspecialchars($quiz['assigned_classes'], ENT_QUOTES, 'UTF-8')
                                : 'No assigned classes' ?>
                        </span>
                    </div>

                    <div class="card-actions">
                        <a href="launch_quiz.php?quiz_id=<?= (int) $quiz['quiz_id'] ?>" class="add-button quiz-launch-button"><i class="ti ti-player-play"></i>Launch</a>
                        <a href="quiz.php?id=<?= (int) $quiz['quiz_id'] ?>" class="secondary-button">
                            <i class="ti ti-<?= (int) $quiz['is_locked'] === 1 ? 'eye' : 'pencil' ?>"></i>
                            <?= (int) $quiz['is_locked'] === 1 ? 'View' : 'Edit' ?>
                        </a>
                    </div>
                </article>

            <?php endwhile; ?>

        </section>


    <?php else: ?>

        <div class="empty-state collection-empty-state">

            <i class="ti ti-clipboard-off" aria-hidden="true"></i>
            <h2>No quizzes yet</h2>

            <p>
                Create your first quiz to get started.
            </p>

            <a
                href="create_quiz.php"
                class="add-button"
            >
                Create Quiz
            </a>

        </div>

    <?php endif; ?>

    </section>

</main>

<dialog
    class="confirmation-dialog"
    id="delete-quiz-dialog"
>
    <div class="confirmation-dialog-icon" aria-hidden="true">!</div>

    <h2>Delete this quiz?</h2>

    <p>
        If you delete this quiz, all questions, answers,
        live sessions, responses, class assignments, and
        related information will be permanently deleted.
    </p>

    <div class="confirmation-dialog-actions">
        <button
            type="button"
            class="cancel-button"
            onclick="closeDeleteQuizDialog()"
        >
            Cancel
        </button>

        <form method="POST" action="delete_quiz.php">
            <input
                type="hidden"
                name="quiz_id"
                id="delete-quiz-id"
            >

            <button type="submit" class="danger-button">
                Yes, Delete Quiz
            </button>
        </form>
    </div>
</dialog>

<script>
function toggleQuizMenu(quizId) {
    const menu = document.getElementById(
        'quiz-menu-' + quizId
    );

    menu.classList.toggle('show');
}

function openDeleteQuizDialog(quizId) {
    document.getElementById('delete-quiz-id').value = quizId;
    document.getElementById('delete-quiz-dialog').showModal();
}

function closeDeleteQuizDialog() {
    document.getElementById('delete-quiz-dialog').close();
}
</script>

<?php include 'includes/layout_end.php'; ?>
