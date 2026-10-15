<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = $_SESSION['teacher_id'];

$sql = "
    SELECT
        s.id,
        s.student_id,
        s.mssv,
        s.name AS student_name,
        c.class_id,
        c.name AS class_name
    FROM students s
    JOIN classes c
        ON s.class_id = c.class_id
    WHERE c.teacher_id = ?
    ORDER BY c.name, s.student_id
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $teacher_id);
$stmt->execute();

$result = $stmt->get_result();
$total_students = $result->num_rows;

$page_title = 'Students';
$page_styles = ['assets/css/students.css?v=20261005'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <?php if (isset($_GET['success'])): ?>
        <div class="success-message">
            Student added successfully.
        </div>
    <?php endif; ?>

    <div class="page-header management-page-header">
        <div>
            <h1>Students</h1>

            <p>
                Total students:
                <strong><?= $total_students ?></strong>
            </p>
        </div>

        <div class="page-header-actions">
            <a href="import_students.php" class="secondary-button">
                <i class="ti ti-file-import" aria-hidden="true"></i>
                Import Students
            </a>

            <a href="add_student.php" class="add-button">
                + Add Student
            </a>
        </div>
    </div>

    <div class="filters">
        <label class="visually-hidden" for="all-students-search">Search students</label>
        <div class="search-field">
            <span class="search-field-icon" aria-hidden="true">
                <i class="ti ti-search"></i>
            </span>
            <input
                id="all-students-search"
                type="search"
                class="form-control"
                placeholder="Search students..."
            >
        </div>

        <select>
            <option>All Classes</option>
        </select>
    </div>

    <?php if ($total_students > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>STT</th>
                    <th>MSSV</th>
                    <th>Name</th>
                    <th>Class</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php while ($student = $result->fetch_assoc()): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($student['student_id']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars((string) ($student['mssv'] ?? '') !== '' ? $student['mssv'] : '—') ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($student['student_name']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($student['class_name']) ?>
                    </td>

                    <td>
                        <a href="#">Edit</a>
                        <a href="#">Delete</a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">
            <h3>No students yet</h3>

            <p>
                Add your first student to get started.
            </p>
        </div>
    <?php endif; ?>

</div>

<?php include 'includes/layout_end.php'; ?>
