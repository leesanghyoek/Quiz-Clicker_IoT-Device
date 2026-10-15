<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/results_data.php';
require_once 'includes/live_sessions.php';

$teacher_id = (int) $_SESSION['teacher_id'];

$summary_stmt = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM classes WHERE teacher_id = ?) AS total_classes,
        (
            SELECT COUNT(s.id)
            FROM students s
            JOIN classes c ON c.class_id = s.class_id
            WHERE c.teacher_id = ?
        ) AS total_students,
        (SELECT COUNT(*) FROM quizzes WHERE teacher_id = ?) AS total_quizzes
");
$summary_stmt->bind_param("iii", $teacher_id, $teacher_id, $teacher_id);
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();

$classes_stmt = $conn->prepare("
    SELECT
        c.class_id,
        c.name,
        COUNT(DISTINCT s.id) AS total_students,
        COUNT(DISTINCT cq.quiz_id) AS total_quizzes
    FROM classes c
    LEFT JOIN students s ON s.class_id = c.class_id
    LEFT JOIN class_quizzes cq ON cq.class_id = c.class_id
    WHERE c.teacher_id = ?
    GROUP BY c.class_id, c.name
    ORDER BY c.name
");
$classes_stmt->bind_param("i", $teacher_id);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$quizzes_stmt = $conn->prepare("
    SELECT
        q.quiz_id,
        q.title,
        COUNT(DISTINCT qu.question_id) AS total_questions,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS assigned_classes
    FROM quizzes q
    LEFT JOIN questions qu ON qu.quiz_id = q.quiz_id
    LEFT JOIN class_quizzes cq ON cq.quiz_id = q.quiz_id
    LEFT JOIN classes c ON c.class_id = cq.class_id
    WHERE q.teacher_id = ?
    GROUP BY q.quiz_id, q.title, q.created_at
    ORDER BY q.created_at DESC
");
$quizzes_stmt->bind_param("i", $teacher_id);
$quizzes_stmt->execute();
$quizzes = $quizzes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

try {
    $recent_results = loadTeacherResultHistory($conn, $teacher_id, null, 5);
} catch (Throwable $exception) {
    error_log(
        'Dashboard recent results failed for teacher '
        . $teacher_id
        . ': '
        . $exception->getMessage()
    );
    $recent_results = [];
}

$page_title = 'Dashboard';
$layout_show_navigation = true;

function dashboardCount(int $count, string $singular, string $plural): string
{
    return $count . ' ' . ($count === 1 ? $singular : $plural);
}

$total_classes = (int) $summary['total_classes'];
$total_students = (int) $summary['total_students'];
$total_quizzes = (int) $summary['total_quizzes'];
$visible_classes = array_slice($classes, 0, 5);
$visible_quizzes = array_slice($quizzes, 0, 5);

$open_sessions = loadOpenSessions($conn, (int) $_SESSION['teacher_id']);

if (empty($_SESSION['cancel_session_csrf_token'])) {
    $_SESSION['cancel_session_csrf_token'] = bin2hex(random_bytes(32));
}

$live_errors = [
    'token' => 'Your form session expired. Please try again.',
    'started' => 'That quiz has already started or ended, so it was not cancelled. Open it to end it.',
    'server' => 'The quiz could not be cancelled. Please try again.'
];
$live_error = $live_errors[$_GET['live_error'] ?? ''] ?? '';

function dashboardAgo($minutes, ?string $label): string
{
    if ($minutes === null) {
        return '';
    }

    $minutes = max(0, (int) $minutes);

    if ($minutes < 1) {
        return 'just now';
    }
    if ($minutes < 60) {
        return $minutes . ' min ago';
    }
    if ($minutes < 60 * 24) {
        return floor($minutes / 60) . ' h ago';
    }

    return (string) $label;
}

include 'includes/layout_start.php';
?>

<main class="main-content management-page">
    <header class="page-header management-page-header">
        <div class="page-header-main">
            <h1>
                <span class="sky-greeting">Welcome back,</span>
                <span class="sky-name"><?= htmlspecialchars(
                    (string) $_SESSION['teacher_name'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></span>
            </h1>
        </div>

        <div class="page-header-actions">
            <a href="create_quiz.php" class="add-button">
                <i class="ti ti-plus" aria-hidden="true"></i>
                Create quiz
            </a>
        </div>
    </header>

    <p class="lake-summary">
        <?php if ($total_classes === 0 && $total_quizzes === 0): ?>
            Nothing here yet. Start by
            <a href="add_class.php">creating a class</a>,
            then write a quiz for it.
        <?php else: ?>
            You teach
            <a href="classes.php"><?= dashboardCount($total_classes, 'class', 'classes') ?></a>
            with <?= dashboardCount($total_students, 'student', 'students') ?>, and have
            <a href="quizzes.php"><?= dashboardCount($total_quizzes, 'quiz', 'quizzes') ?></a>
            ready to run.
        <?php endif; ?>
    </p>

    <?php if (isset($_GET['cancelled'])): ?>
        <div class="success-message">Quiz cancelled. Devices that had joined go back to the start.</div>
    <?php endif; ?>

    <?php if ($live_error !== ''): ?>
        <div class="error-message"><?= htmlspecialchars($live_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($open_sessions)): ?>
        <section class="live-now" id="live-now" aria-labelledby="live-now-title">
            <div class="section-header">
                <div>
                    <h2 id="live-now-title"><span class="sky-live-dot" aria-hidden="true"></span>Not ended yet</h2>
                    <p>Quizzes still open. Continue where you left off, or cancel ones you no longer need.</p>
                </div>
            </div>

            <ul class="live-list">
                <?php foreach ($open_sessions as $open): ?>
                    <?php $running = $open['status'] === 'ACTIVE'; ?>
                    <li class="live-item <?= $running ? 'is-running' : '' ?>">
                        <div class="live-code" title="Join code">
                            <span>Code</span>
                            <strong><?= htmlspecialchars((string) ($open['join_code'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>

                        <div class="live-info">
                            <h3><?= htmlspecialchars($open['quiz_title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p>
                                <?= htmlspecialchars($open['class_name'], ENT_QUOTES, 'UTF-8') ?> ·
                                <?php if ($running): ?>
                                    <strong>Running</strong>, question <?= (int) $open['question_number'] ?> of <?= (int) $open['question_count'] ?>
                                    · started <?= htmlspecialchars(dashboardAgo($open['started_minutes'], $open['started_label']), ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <strong>Waiting in the lobby</strong>
                                    · opened <?= htmlspecialchars(dashboardAgo($open['opened_minutes'], $open['opened_label']), ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                                · <?= (int) $open['joined'] ?>/<?= (int) $open['class_size'] ?> joined
                            </p>
                        </div>

                        <div class="live-actions">
                            <a href="<?= htmlspecialchars(openSessionUrl($open), ENT_QUOTES, 'UTF-8') ?>" class="add-button">
                                <i class="ti ti-player-play" aria-hidden="true"></i><?= $running ? 'Continue' : 'Open lobby' ?>
                            </a>
                            <?php if (!$running): ?>
                                <form method="POST" action="cancel_session.php"
                                    onsubmit="return confirm('Cancel this quiz? Devices that joined go back to the start; nothing is saved to results.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['cancel_session_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="session_id" value="<?= (int) $open['session_id'] ?>">
                                    <button type="submit" class="secondary-button live-cancel"><i class="ti ti-x" aria-hidden="true"></i>Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <div class="lake-columns">
        <section aria-labelledby="dashboard-classes-title">
            <div class="section-header">
                <h2 id="dashboard-classes-title">Classes</h2>
                <a href="add_class.php" class="secondary-button">New class</a>
            </div>

            <?php if (!empty($visible_classes)): ?>
                <ul class="lake-list">
                    <?php foreach ($visible_classes as $class): ?>
                        <li>
                            <a class="lake-row" href="class.php?id=<?= (int) $class['class_id'] ?>">
                                <span class="lake-row-title"><?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="lake-row-meta">
                                    <?= dashboardCount((int) $class['total_students'], 'student', 'students') ?>,
                                    <?= dashboardCount((int) $class['total_quizzes'], 'quiz', 'quizzes') ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($classes) > count($visible_classes)): ?>
                    <div class="lake-section-end"><a href="classes.php">All <?= count($classes) ?> classes</a></div>
                <?php endif; ?>
            <?php else: ?>
                <p class="lake-empty">No classes yet. <a href="add_class.php">Create your first class</a> to start adding students.</p>
            <?php endif; ?>
        </section>

        <section aria-labelledby="dashboard-quizzes-title">
            <div class="section-header">
                <h2 id="dashboard-quizzes-title">Quizzes</h2>
                <a href="import_quiz.php" class="secondary-button">Import</a>
            </div>

            <?php if (!empty($visible_quizzes)): ?>
                <ul class="lake-list">
                    <?php foreach ($visible_quizzes as $quiz): ?>
                        <li>
                            <a class="lake-row" href="quiz.php?id=<?= (int) $quiz['quiz_id'] ?>">
                                <span class="lake-row-title"><?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="lake-row-meta"><?= dashboardCount((int) $quiz['total_questions'], 'question', 'questions') ?></span>
                                <span class="lake-row-note">
                                    <?= $quiz['assigned_classes'] !== null && $quiz['assigned_classes'] !== ''
                                        ? 'For ' . htmlspecialchars($quiz['assigned_classes'], ENT_QUOTES, 'UTF-8')
                                        : 'Not assigned to a class yet' ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($quizzes) > count($visible_quizzes)): ?>
                    <div class="lake-section-end"><a href="quizzes.php">All <?= count($quizzes) ?> quizzes</a></div>
                <?php endif; ?>
            <?php else: ?>
                <p class="lake-empty">No quizzes yet. <a href="create_quiz.php">Write one</a> or <a href="import_quiz.php">import a file</a>.</p>
            <?php endif; ?>
        </section>
    </div>

    <?php if (!empty($recent_results)): ?>
        <section class="lake-results" aria-labelledby="dashboard-results-title">
            <div class="section-header">
                <h2 id="dashboard-results-title">Recent sessions</h2>
                <a href="results.php" class="secondary-button">All results</a>
            </div>

            <ul class="lake-list">
                <?php foreach ($recent_results as $result): ?>
                    <?php
                    $result_date = $result['ended_at'] ?? $result['started_at'];
                    try {
                        $result_date_label = $result_date
                            ? (new DateTime($result_date))->format('j M Y, H:i')
                            : 'Date unavailable';
                    } catch (Throwable) {
                        $result_date_label = 'Date unavailable';
                    }
                    $score = $result['average_score'];
                    ?>
                    <li>
                        <a class="lake-row" href="results.php?session_id=<?= (int) $result['session_id'] ?>">
                            <span>
                                <span class="lake-row-title"><?= htmlspecialchars($result['quiz_title'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="lake-row-note">
                                    <?= htmlspecialchars($result['class_name'], ENT_QUOTES, 'UTF-8') ?>,
                                    <?= htmlspecialchars($result_date_label, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </span>
                            <span class="lake-row-meta"><?= dashboardCount((int) $result['participant_count'], 'participant', 'participants') ?></span>
                            <span class="lake-row-score-wrap">
                                <?php if ($score !== null): ?>
                                    <span class="lake-row-score"><?= (int) $score ?>%</span>
                                    <span class="lake-gauge" style="--level: <?= (int) $score ?>%" aria-hidden="true"></span>
                                <?php else: ?>
                                    <span class="lake-row-meta">No score</span>
                                <?php endif; ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</main>

<?php include 'includes/layout_end.php'; ?>
