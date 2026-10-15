<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/join_code.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$error = '';
$quiz = null;
$assigned_classes = [];
$other_classes = [];

if (empty($_SESSION['launch_quiz_csrf_token'])) {
    $_SESSION['launch_quiz_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['launch_quiz_csrf_token'];
$quiz_id = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (int) ($_POST['quiz_id'] ?? 0)
    : (int) ($_GET['quiz_id'] ?? 0);
$selected_class_id = (int) ($_POST['class_id'] ?? 0);

if ($quiz_id <= 0) {
    $error = 'Invalid quiz.';
} else {
    $stmt = $conn->prepare("
        SELECT quiz_id, title
        FROM quizzes
        WHERE quiz_id = ?
          AND teacher_id = ?
    ");
    $stmt->bind_param('ii', $quiz_id, $teacher_id);
    $stmt->execute();
    $quiz_result = $stmt->get_result();

    if ($quiz_result->num_rows !== 1) {
        http_response_code(403);
        $error = 'Quiz not found or access denied.';
    } else {
        $quiz = $quiz_result->fetch_assoc();
    }
}

if ($quiz !== null) {
    // Every class of this teacher can be picked; launching for a class the
    // quiz is not assigned to yet assigns it on the way.
    $stmt = $conn->prepare("
        SELECT
            c.class_id,
            c.name,
            COUNT(s.id) AS student_count,
            EXISTS (
                SELECT 1
                FROM class_quizzes cq
                WHERE cq.class_id = c.class_id
                  AND cq.quiz_id = ?
            ) AS is_assigned
        FROM classes c
        LEFT JOIN students s
            ON s.class_id = c.class_id
        WHERE c.teacher_id = ?
        GROUP BY c.class_id, c.name
        ORDER BY c.name
    ");
    $stmt->bind_param('ii', $quiz_id, $teacher_id);
    $stmt->execute();
    $classes_result = $stmt->get_result();

    while ($class = $classes_result->fetch_assoc()) {
        $class_entry = [
            'class_id' => (int) $class['class_id'],
            'name' => $class['name'],
            'student_count' => (int) $class['student_count']
        ];

        if ((int) $class['is_assigned'] === 1) {
            $assigned_classes[] = $class_entry;
        } else {
            $other_classes[] = $class_entry;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $quiz !== null) {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($posted_csrf_token)
        || !hash_equals($csrf_token, $posted_csrf_token)
    ) {
        $error = 'Your form session expired. Please try again.';
    } elseif ($selected_class_id <= 0) {
        $error = 'Please select a class.';
    } else {
        $stmt = $conn->prepare("
            SELECT class_id
            FROM classes
            WHERE class_id = ?
              AND teacher_id = ?
        ");
        $stmt->bind_param('ii', $selected_class_id, $teacher_id);
        $stmt->execute();

        if ($stmt->get_result()->num_rows !== 1) {
            $error = 'Class not found or access denied.';
        }
    }

    if ($error === '') {
        $allocation_lock_acquired = false;
        $transaction_started = false;
        $session_id = 0;

        try {
            acquireJoinCodeAllocationLock($conn);
            $allocation_lock_acquired = true;
            $conn->begin_transaction();
            $transaction_started = true;
            $join_code = allocateLiveSessionJoinCode($conn);

            $stmt = $conn->prepare("
                INSERT IGNORE INTO class_quizzes
                    (class_id, quiz_id)
                VALUES
                    (?, ?)
            ");
            $stmt->bind_param('ii', $selected_class_id, $quiz_id);
            $stmt->execute();

            $stmt = $conn->prepare("
                INSERT INTO live_sessions
                    (
                        quiz_id,
                        class_id,
                        join_code,
                        status,
                        current_question_id,
                        started_at,
                        ended_at
                    )
                VALUES
                    (?, ?, ?, 'WAITING', NULL, NULL, NULL)
            ");
            $stmt->bind_param(
                'iis',
                $quiz_id,
                $selected_class_id,
                $join_code
            );
            $stmt->execute();

            $session_id = (int) $conn->insert_id;

            if ($session_id <= 0) {
                throw new RuntimeException('Live session ID was not created.');
            }

            $conn->commit();
            $transaction_started = false;
        } catch (JoinCodeExhaustedException $exception) {
            $error = 'All join codes are currently in use. End an active session and try again.';
        } catch (Throwable $exception) {
            error_log(
                'Quiz launch failed for teacher '
                . $teacher_id
                . ': '
                . $exception->getMessage()
            );
            $error = 'Unable to launch the quiz. Please try again.';
        } finally {
            if ($transaction_started) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollback_exception) {
                    error_log(
                        'Quiz launch rollback failed: '
                        . $rollback_exception->getMessage()
                    );
                }
            }

            if ($allocation_lock_acquired) {
                try {
                    releaseJoinCodeAllocationLock($conn);
                } catch (Throwable $release_exception) {
                    error_log(
                        'Join-code lock release failed: '
                        . $release_exception->getMessage()
                    );
                }
            }
        }

        if ($error === '' && $session_id > 0) {
            unset($_SESSION['launch_quiz_csrf_token']);
            header('Location: lobby.php?session_id=' . $session_id);
            exit;
        }
    }
}
?>

<?php
$page_title = 'Launch Quiz';
$page_styles = ['assets/css/quiz.css?v=20261005-10'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">
    <div class="form-card">
        <h1>Launch Quiz</h1>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($quiz !== null): ?>
            <p>
                Quiz:
                <strong><?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?></strong>
            </p>

            <?php if (!empty($assigned_classes) || !empty($other_classes)): ?>
                <form method="POST">
                    <input type="hidden" name="quiz_id" value="<?= $quiz_id ?>">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <div class="form-group">
                        <label for="class-id">Select Class</label>
                        <select id="class-id" name="class_id" required>
                            <?php foreach (['Assigned to this quiz' => $assigned_classes, 'Other classes' => $other_classes] as $group_label => $group_classes): ?>
                                <?php if (empty($group_classes)) continue; ?>
                                <optgroup label="<?= $group_label ?>">
                                    <?php foreach ($group_classes as $class): ?>
                                        <option
                                            value="<?= $class['class_id'] ?>"
                                            data-student-count="<?= $class['student_count'] ?>"
                                            <?= $selected_class_id === $class['class_id'] ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($other_classes)): ?>
                            <p class="assignment-hint">Launching for a class under "Other classes" also assigns this quiz to it.</p>
                        <?php endif; ?>
                    </div>

                    <p>
                        Students:
                        <strong id="student-count">0</strong>
                    </p>

                    <div class="form-actions">
                        <a href="quiz.php?id=<?= $quiz_id ?>" class="cancel-button">
                            Cancel
                        </a>
                        <button type="submit" class="save-button">Launch</button>
                    </div>
                </form>
            <?php else: ?>
                <p>You do not have any classes yet. Create a class first, then launch this quiz.</p>

                <div class="form-actions">
                    <a href="quiz.php?id=<?= $quiz_id ?>" class="cancel-button">
                        Cancel
                    </a>
                    <a href="add_class.php" class="save-button">
                        Create Class
                    </a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="form-actions">
                <a href="quizzes.php" class="cancel-button">
                    Back to Quiz Library
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const classSelect = document.getElementById('class-id');
const studentCount = document.getElementById('student-count');

function updateStudentCount() {
    if (!classSelect || !studentCount) {
        return;
    }

    const selectedOption = classSelect.options[classSelect.selectedIndex];
    studentCount.textContent = selectedOption.dataset.studentCount || '0';
}

if (classSelect) {
    classSelect.addEventListener('change', updateStudentCount);
    updateStudentCount();
}
</script>

<?php include 'includes/layout_end.php'; ?>
