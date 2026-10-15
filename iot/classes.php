<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        c.class_id,
        c.name,
        COUNT(DISTINCT s.id) AS total_students,
        COUNT(DISTINCT cq.quiz_id) AS total_quizzes
    FROM classes c
    LEFT JOIN students s ON c.class_id = s.class_id
    LEFT JOIN class_quizzes cq ON c.class_id = cq.class_id
    WHERE c.teacher_id = ?
";

if ($search !== '') {
    $sql .= " AND c.name LIKE ?";
}

$sql .= " GROUP BY c.class_id, c.name ORDER BY c.name";
$stmt = $conn->prepare($sql);

if ($search !== '') {
    $keyword = '%' . $search . '%';
    $stmt->bind_param("is", $teacher_id, $keyword);
} else {
    $stmt->bind_param("i", $teacher_id);
}

$stmt->execute();
$result = $stmt->get_result();
$classes = $result->fetch_all(MYSQLI_ASSOC);
$total_classes = count($classes);

$page_title = 'Classes';
$page_styles = ['assets/css/classes.css?v=20261005'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page">
    <?php if (isset($_GET['success'])): ?><div class="success-message">Class created successfully.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="success-message">Class and all related data deleted successfully.</div><?php endif; ?>
    <?php if (($_GET['error'] ?? '') === 'delete'): ?><div class="error-message">Failed to delete class.</div><?php endif; ?>
    <?php if (($_GET['error'] ?? '') === 'access'): ?><div class="error-message">Class not found or access denied.</div><?php endif; ?>

    <header class="page-header management-page-header">
        <div class="page-header-main">
            <h1>Classes</h1>
            <p><?= $total_classes ?> <?= $total_classes === 1 ? 'class' : 'classes' ?> shown</p>
        </div>
        <div class="page-header-actions">
            <?php /* Hidden until class import exists; students are imported per class (import_students.php).
            <button type="button" class="secondary-button" disabled title="Class import is not available yet">
                <i class="ti ti-file-import" aria-hidden="true"></i>
                Import Classes
            </button>
            */ ?>
            <a href="add_class.php" class="add-button"><i class="ti ti-plus" aria-hidden="true"></i>Create Class</a>
        </div>
    </header>

    <form method="GET" action="classes.php" class="management-toolbar" role="search">
        <label class="visually-hidden" for="class-search">Search classes</label>
        <div class="search-field">
            <span class="search-field-icon" aria-hidden="true">
                <i class="ti ti-search"></i>
            </span>
            <input id="class-search" type="search" class="form-control" name="search" placeholder="Search classes..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <?php if ($search !== ''): ?><a href="classes.php" class="secondary-button">Clear</a><?php endif; ?>
    </form>

    <?php if (!empty($classes)): ?>
        <section class="management-card-grid class-management-grid" aria-label="Classes">
            <?php foreach ($classes as $class): ?>
                <article
                    class="management-card class-management-card"
                    data-card-href="class.php?id=<?= (int) $class['class_id'] ?>"
                    tabindex="0"
                    role="link"
                    aria-label="Open class <?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?>"
                >
                    <div class="card-topline">
                        <span class="card-icon" aria-hidden="true"><i class="ti ti-school"></i></span>
                        <div class="menu-wrapper">
                            <button type="button" class="icon-button" onclick="toggleMenu(<?= (int) $class['class_id'] ?>)" aria-label="Actions for <?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="ti ti-dots-vertical"></i></button>
                            <div class="action-menu" id="menu-<?= (int) $class['class_id'] ?>">
                                <a href="edit_class.php?id=<?= (int) $class['class_id'] ?>"><i class="ti ti-pencil"></i>Rename</a>
                                <form method="POST" action="delete_class.php" onsubmit="return confirmDeleteClass();">
                                    <input type="hidden" name="class_id" value="<?= (int) $class['class_id'] ?>">
                                    <button type="submit"><i class="ti ti-trash"></i>Delete Class</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <h2><?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="card-meta-list">
                        <span><i class="ti ti-users"></i><?= (int) $class['total_students'] ?> students</span>
                        <span><i class="ti ti-clipboard-text"></i><?= (int) $class['total_quizzes'] ?> assigned quizzes</span>
                    </div>
                    <a href="class.php?id=<?= (int) $class['class_id'] ?>" class="secondary-button card-action">Open Class<i class="ti ti-arrow-right"></i></a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php else: ?>
        <div class="empty-state">
            <i class="ti ti-school" aria-hidden="true"></i>
            <h3><?= $search !== '' ? 'No matching classes' : 'No classes yet' ?></h3>
            <p><?= $search !== '' ? 'Try another class name.' : 'Create your first class to get started.' ?></p>
            <?php if ($search === ''): ?><a href="add_class.php" class="add-button">Create Class</a><?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<script>
function toggleMenu(classId) {
    document.getElementById('menu-' + classId).classList.toggle('show');
}

function confirmDeleteClass() {
    return confirm(
        'Delete this class?\n\n'
        + 'This will permanently delete:\n'
        + '- all students in this class\n'
        + '- all quiz assignments\n'
        + '- all answers and results submitted by students in this class\n\n'
        + 'Shared live sessions and original quizzes will not be deleted.\n\n'
        + 'This action cannot be undone.'
    );
}
</script>

<?php include 'includes/layout_end.php'; ?>
