<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/results_data.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$class_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($class_id <= 0) {
    die('Invalid class ID.');
}

$stmt = $conn->prepare("
    SELECT class_id, name
    FROM classes
    WHERE class_id = ? AND teacher_id = ?
");
$stmt->bind_param("ii", $class_id, $teacher_id);
$stmt->execute();
$class_result = $stmt->get_result();

if ($class_result->num_rows !== 1) {
    die('Class not found or access denied.');
}

$class = $class_result->fetch_assoc();
$tab = $_GET['tab'] ?? 'overview';

if (!in_array($tab, ['overview', 'students', 'quizzes', 'results'], true)) {
    $tab = 'overview';
}

$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM students WHERE class_id = ?");
$count_stmt->bind_param("i", $class_id);
$count_stmt->execute();
$total_students = (int) $count_stmt->get_result()->fetch_assoc()['total'];

$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $keyword = '%' . $search . '%';
    $stmt = $conn->prepare("
        SELECT id, student_id, mssv, name
        FROM students
        WHERE class_id = ? AND (student_id LIKE ? OR mssv LIKE ? OR name LIKE ?)
        ORDER BY student_id
    ");
    $stmt->bind_param("isss", $class_id, $keyword, $keyword, $keyword);
} else {
    $stmt = $conn->prepare("
        SELECT id, student_id, mssv, name
        FROM students
        WHERE class_id = ?
        ORDER BY student_id
    ");
    $stmt->bind_param("i", $class_id);
}

$stmt->execute();
$students = $stmt->get_result();

// What "Delete students" would keep: students with quiz history, and students in a quiz that has not ended.
$students_with_history = 0;
$students_in_live_session = 0;

if ($tab === 'students' && $total_students > 0) {
    if (empty($_SESSION['delete_students_csrf_token'])) {
        $_SESSION['delete_students_csrf_token'] = bin2hex(random_bytes(32));
    }

    $history_stmt = $conn->prepare("
        SELECT
            SUM(
                EXISTS (SELECT 1 FROM responses r WHERE r.student_pk = s.id)
                OR EXISTS (SELECT 1 FROM device_sessions ds WHERE ds.student_pk = s.id)
            ) AS with_history,
            SUM(EXISTS (
                SELECT 1
                FROM device_sessions ds
                JOIN live_sessions ls ON ls.session_id = ds.session_id
                WHERE ds.student_pk = s.id
                  AND ls.status IN ('WAITING', 'ACTIVE')
            )) AS in_live_session
        FROM students s
        WHERE s.class_id = ?
    ");
    $history_stmt->bind_param("i", $class_id);
    $history_stmt->execute();
    $history_counts = $history_stmt->get_result()->fetch_assoc();
    $students_in_live_session = (int) $history_counts['in_live_session'];
    $students_with_history = (int) $history_counts['with_history'] - $students_in_live_session;
}

$quiz_stmt = $conn->prepare("
    SELECT
        q.quiz_id,
        q.title,
        COUNT(DISTINCT qu.question_id) AS total_questions
    FROM class_quizzes cq
    JOIN quizzes q ON q.quiz_id = cq.quiz_id
    LEFT JOIN questions qu ON qu.quiz_id = q.quiz_id
    WHERE cq.class_id = ? AND q.teacher_id = ?
    GROUP BY q.quiz_id, q.title
    ORDER BY q.title
");
$quiz_stmt->bind_param("ii", $class_id, $teacher_id);
$quiz_stmt->execute();
$assigned_quizzes = $quiz_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total_quizzes = count($assigned_quizzes);
$class_results = [];
$class_results_error = '';

if ($tab === 'results') {
    try {
        $class_results = loadTeacherResultHistory(
            $conn,
            $teacher_id,
            $class_id
        );
    } catch (Throwable $exception) {
        error_log(
            'Class results failed for teacher '
            . $teacher_id
            . ': '
            . $exception->getMessage()
        );
        $class_results_error = 'Unable to load class results.';
    }
}

$page_title = (string) $class['name'];
$page_styles = ['assets/css/class.css?v=20261005'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page">
    <header class="page-header management-page-header">
        <div class="page-header-main">
            <h1><?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= $total_students ?> students · <?= $total_quizzes ?> assigned quizzes</p>
        </div>
        <div class="page-header-actions">
            <a href="edit_class.php?id=<?= $class_id ?>" class="secondary-button"><i class="ti ti-pencil"></i>Edit Class</a>
        </div>
    </header>

    <nav class="tabs management-tabs" aria-label="Class sections">
        <a href="class.php?id=<?= $class_id ?>&tab=overview" class="<?= $tab === 'overview' ? 'active' : '' ?>"><i class="ti ti-layout-dashboard"></i>Overview</a>
        <a href="class.php?id=<?= $class_id ?>&tab=students" class="<?= $tab === 'students' ? 'active' : '' ?>"><i class="ti ti-users"></i>Students</a>
        <a href="class.php?id=<?= $class_id ?>&tab=quizzes" class="<?= $tab === 'quizzes' ? 'active' : '' ?>"><i class="ti ti-clipboard-text"></i>Quizzes</a>
        <a href="class.php?id=<?= $class_id ?>&tab=results" class="<?= $tab === 'results' ? 'active' : '' ?>"><i class="ti ti-chart-bar"></i>Results</a>
    </nav>

    <?php if ($tab === 'overview'): ?>
        <section class="overview-cards class-overview-cards" aria-label="Class overview">
            <article class="overview-card"><span class="overview-card-icon"><i class="ti ti-users"></i></span><div><span>Total Students</span><strong><?= $total_students ?></strong></div></article>
            <article class="overview-card"><span class="overview-card-icon"><i class="ti ti-clipboard-text"></i></span><div><span>Assigned Quizzes</span><strong><?= $total_quizzes ?></strong></div></article>
        </section>

        <section class="content-section">
            <div class="section-header"><div><h2>Quick Actions</h2><p>Manage this class from one place.</p></div></div>
            <div class="quick-link-grid">
                <a href="class.php?id=<?= $class_id ?>&tab=students" class="management-card quick-link-card"><span class="card-icon"><i class="ti ti-users"></i></span><div><h3>Manage Students</h3><p>Search, add, edit or remove students.</p></div><i class="ti ti-arrow-right"></i></a>
                <a href="class.php?id=<?= $class_id ?>&tab=quizzes" class="management-card quick-link-card"><span class="card-icon"><i class="ti ti-clipboard-text"></i></span><div><h3>Class Quizzes</h3><p>Review quizzes assigned to this class.</p></div><i class="ti ti-arrow-right"></i></a>
            </div>
        </section>

    <?php elseif ($tab === 'students'): ?>
        <?php if (isset($_GET['added'])): ?><div class="success-message">Student added successfully.</div><?php endif; ?>
        <?php if (isset($_GET['updated'])): ?><div class="success-message">Student updated successfully.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="success-message">Student deleted successfully.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'delete'): ?><div class="error-message">Failed to delete student.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'history'): ?>
            <div class="error-message">
                This student has quiz answers, so they were kept. To remove them together with their answers,
                use Delete students and tick "Also delete their quiz answers".
            </div>
        <?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'clear'): ?><div class="error-message">The students could not be deleted. Nothing was changed; please try again.</div><?php endif; ?>
        <?php if (isset($_GET['cleared'])): ?>
            <?php
            $cleared_count = max(0, (int) $_GET['cleared']);
            $kept_history_count = max(0, (int) ($_GET['kept_history'] ?? 0));
            $kept_live_count = max(0, (int) ($_GET['kept_live'] ?? 0));
            ?>
            <div class="success-message">
                <?= $cleared_count ?> <?= $cleared_count === 1 ? 'student' : 'students' ?> deleted.
                <?php if ($kept_history_count > 0): ?>
                    <?= $kept_history_count ?> with quiz answers <?= $kept_history_count === 1 ? 'was' : 'were' ?> kept.
                <?php endif; ?>
                <?php if ($kept_live_count > 0): ?>
                    <?= $kept_live_count ?> in a quiz that has not ended <?= $kept_live_count === 1 ? 'was' : 'were' ?> kept.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['imported'])): ?>
            <?php
            $imported_count = max(0, (int) $_GET['imported']);
            $updated_import_count = max(0, (int) ($_GET['updated_count'] ?? 0));
            $skipped_count = max(0, (int) ($_GET['skipped'] ?? 0));
            ?>
            <div class="success-message">
                <?= $imported_count ?> <?= $imported_count === 1 ? 'student' : 'students' ?> added.
                <?php if ($updated_import_count > 0): ?>
                    <?= $updated_import_count ?> <?= $updated_import_count === 1 ? 'student was' : 'students were' ?> updated.
                <?php endif; ?>
                <?php if ($skipped_count > 0): ?>
                    <?= $skipped_count ?> <?= $skipped_count === 1 ? 'row was' : 'rows were' ?> skipped.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <div><h2>Students</h2><p><?= $total_students ?> enrolled students</p></div>
            <div class="section-actions">
                <?php if ($total_students > 0): ?>
                    <button type="button" class="danger-button" onclick="document.getElementById('delete-students-dialog').showModal()"><i class="ti ti-trash"></i>Delete students</button>
                <?php endif; ?>
                <a href="import_students.php?class_id=<?= $class_id ?>" class="secondary-button"><i class="ti ti-file-import"></i>Import Students</a>
                <a href="add_student.php?class_id=<?= $class_id ?>" class="add-button"><i class="ti ti-user-plus"></i>Add Student</a>
            </div>
        </div>

        <form method="GET" action="class.php" class="management-toolbar" role="search">
            <input type="hidden" name="id" value="<?= $class_id ?>">
            <input type="hidden" name="tab" value="students">
            <label class="visually-hidden" for="student-search">Search students</label>
            <div class="search-field">
                <span class="search-field-icon" aria-hidden="true"><i class="ti ti-search"></i></span>
                <input id="student-search" type="search" class="form-control" name="search" placeholder="Search by STT, MSSV or name..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <?php if ($search !== ''): ?><a href="class.php?id=<?= $class_id ?>&tab=students" class="secondary-button">Clear</a><?php endif; ?>
        </form>

        <?php if ($students->num_rows > 0): ?>
            <div class="table-card">
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>STT</th><th>MSSV</th><th>Name</th><th class="table-actions-heading">Actions</th></tr></thead>
                        <tbody>
                        <?php while ($student = $students->fetch_assoc()): ?>
                            <tr>
                                <td><span class="table-id"><?= htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="table-id"><?= htmlspecialchars((string) ($student['mssv'] ?? '') !== '' ? $student['mssv'] : '—', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="student-actions">
                                    <button type="button" class="icon-button" onclick="toggleStudentMenu(<?= (int) $student['id'] ?>)" aria-label="Actions for <?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="ti ti-dots-vertical"></i></button>
                                    <div class="action-menu student-menu" id="student-menu-<?= (int) $student['id'] ?>">
                                        <a href="edit_student.php?id=<?= (int) $student['id'] ?>"><i class="ti ti-pencil"></i>Edit</a>
                                        <form method="POST" action="delete_student.php" onsubmit="return confirm('Are you sure you want to delete this student?');"><input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>"><button type="submit"><i class="ti ti-trash"></i>Delete</button></form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="ti ti-user-search"></i><h3>No students found</h3><p>This class does not contain any matching students.</p></div>
        <?php endif; ?>

        <?php if ($total_students > 0): ?>
            <dialog class="confirmation-dialog" id="delete-students-dialog" aria-labelledby="delete-students-title">
                <form method="POST" action="delete_students.php">
                    <input type="hidden" name="class_id" value="<?= $class_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['delete_students_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="confirmation-dialog-icon" aria-hidden="true">!</div>
                    <h2 id="delete-students-title">Delete all students in <?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?>?</h2>
                    <p>
                        This removes <?= $total_students ?> <?= $total_students === 1 ? 'student' : 'students' ?>
                        from the class. Quizzes and the class itself stay.
                    </p>

                    <?php if ($students_with_history > 0): ?>
                        <label class="delete-history-option">
                            <input type="checkbox" name="with_history" value="1">
                            <span>
                                Also delete their quiz answers.
                                <?= $students_with_history ?> <?= $students_with_history === 1 ? 'student has' : 'students have' ?>
                                answered or joined past quizzes; without this they are kept. With it, past
                                results will no longer list them.
                            </span>
                        </label>
                    <?php endif; ?>

                    <?php if ($students_in_live_session > 0): ?>
                        <p class="delete-live-note">
                            <?= $students_in_live_session ?> <?= $students_in_live_session === 1 ? 'student is' : 'students are' ?>
                            in a quiz that has not ended and will be kept.
                        </p>
                    <?php endif; ?>

                    <div class="confirmation-dialog-actions">
                        <button type="button" class="cancel-button" onclick="this.closest('dialog').close()">Cancel</button>
                        <button type="submit" class="danger-button">Delete students</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>

    <?php elseif ($tab === 'quizzes'): ?>
        <div class="section-header">
            <div><h2>Assigned Quizzes</h2><p>Quizzes currently available to this class.</p></div>
            <div class="section-actions">
                <a href="quizzes.php" class="secondary-button"><i class="ti ti-library"></i>Add Existing Quiz</a>
                <a href="create_quiz.php" class="add-button"><i class="ti ti-plus"></i>Create New Quiz</a>
            </div>
        </div>

        <?php if (!empty($assigned_quizzes)): ?>
            <section class="management-card-grid entity-card-grid" aria-label="Assigned quizzes">
                <?php foreach ($assigned_quizzes as $quiz): ?>
                    <article
                        class="management-card"
                        data-card-href="quiz.php?id=<?= (int) $quiz['quiz_id'] ?>"
                        tabindex="0"
                        role="link"
                        aria-label="Open quiz <?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <span class="card-icon"><i class="ti ti-clipboard-text"></i></span>
                        <h3><?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <div class="card-meta-list"><span><i class="ti ti-list-check"></i><?= (int) $quiz['total_questions'] ?> questions</span></div>
                        <a href="quiz.php?id=<?= (int) $quiz['quiz_id'] ?>" class="secondary-button card-action">Open Quiz<i class="ti ti-arrow-right"></i></a>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="empty-state"><i class="ti ti-clipboard-off"></i><h3>No quizzes assigned</h3><p>Open the Quiz Library to assign an existing quiz to this class.</p><a href="quizzes.php" class="secondary-button">Browse Quiz Library</a></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="section-header">
            <div><h2>Results</h2><p>Completed quiz sessions for this class.</p></div>
            <div class="section-actions">
                <a href="progress.php?class_id=<?= $class_id ?>" class="secondary-button"><i class="ti ti-chart-line"></i>Progress</a>
                <a href="review.php?class_id=<?= $class_id ?>" class="secondary-button"><i class="ti ti-target-arrow"></i>Review missed questions</a>
                <a href="results.php" class="secondary-button">All Results</a>
            </div>
        </div>

        <?php if ($class_results_error !== ''): ?>
            <div class="error-message"><?= htmlspecialchars($class_results_error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif (!empty($class_results)): ?>
            <section class="class-results-list" aria-label="Completed class sessions">
                <?php foreach ($class_results as $result): ?>
                    <?php
                    $result_date = $result['ended_at'] ?? $result['started_at'];
                    try {
                        $result_date_label = $result_date
                            ? (new DateTime($result_date))->format('d M Y, H:i')
                            : 'Date unavailable';
                    } catch (Throwable) {
                        $result_date_label = 'Date unavailable';
                    }
                    ?>
                    <article class="class-result-card">
                        <span class="card-icon" aria-hidden="true"><i class="ti ti-chart-bar"></i></span>
                        <div class="class-result-main">
                            <h3><?= htmlspecialchars($result['quiz_title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p><?= htmlspecialchars($result_date_label, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="class-result-stat"><span>Participants</span><strong><?= (int) $result['participant_count'] ?> / <?= (int) $result['class_student_count'] ?></strong></div>
                        <div class="class-result-stat"><span>Average Score</span><strong><?= $result['average_score'] !== null ? (int) $result['average_score'] . '%' : 'Unavailable' ?></strong></div>
                        <a href="results.php?session_id=<?= (int) $result['session_id'] ?>" class="secondary-button">View Results<i class="ti ti-arrow-right"></i></a>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="empty-state"><i class="ti ti-chart-bar-off"></i><h3>No completed sessions</h3><p>Finished quiz sessions for this class will appear here.</p></div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<script>
function toggleStudentMenu(studentId) {
    document.getElementById('student-menu-' + studentId).classList.toggle('show');
}
</script>

<?php include 'includes/layout_end.php'; ?>
