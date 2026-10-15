<?php
/*
 * Delete every student in a class at once.
 *
 * Students with quiz history (answers, or joins to finished sessions) are
 * kept unless the teacher ticks "also delete their answers"; then those
 * answers and joins are deleted too and past results no longer list them.
 * Students taking part in a session that has not ended are always kept.
 */
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = (int) $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: classes.php');
    exit;
}

$class_id = (int) ($_POST['class_id'] ?? 0);
$with_history = ($_POST['with_history'] ?? '') === '1';
$posted_token = $_POST['csrf_token'] ?? '';
$expected_token = $_SESSION['delete_students_csrf_token'] ?? '';

if (
    $class_id <= 0
    || !is_string($posted_token)
    || $expected_token === ''
    || !hash_equals($expected_token, $posted_token)
) {
    header('Location: ' . ($class_id > 0 ? 'class.php?id=' . $class_id . '&tab=students&error=clear' : 'classes.php'));
    exit;
}

/** @return array<int, int> */
function studentIds(mysqli $conn, string $sql, int $class_id): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $class_id);
    $stmt->execute();

    return array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_NUM), 0));
}

// Safe to inline: every value is cast to int.
function idList(array $ids): string
{
    return implode(',', array_map('intval', $ids));
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->begin_transaction();

    $stmt = $conn->prepare('SELECT class_id FROM classes WHERE class_id = ? AND teacher_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $class_id, $teacher_id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows !== 1) {
        $conn->rollback();
        header('Location: classes.php?error=access');
        exit;
    }

    $all = studentIds($conn, 'SELECT id FROM students WHERE class_id = ? FOR UPDATE', $class_id);

    $in_live_session = studentIds($conn, "
        SELECT DISTINCT ds.student_pk
        FROM device_sessions ds
        JOIN live_sessions ls ON ls.session_id = ds.session_id
        JOIN students s ON s.id = ds.student_pk
        WHERE s.class_id = ?
          AND ls.status IN ('WAITING', 'ACTIVE')
    ", $class_id);

    $with_answers_or_joins = studentIds($conn, "
        SELECT s.id
        FROM students s
        WHERE s.class_id = ?
          AND (
              EXISTS (SELECT 1 FROM responses r WHERE r.student_pk = s.id)
              OR EXISTS (SELECT 1 FROM device_sessions ds WHERE ds.student_pk = s.id)
          )
    ", $class_id);

    $kept_live = array_values(array_intersect($all, $in_live_session));
    $candidates = array_values(array_diff($all, $kept_live));
    $history = array_values(array_intersect($candidates, $with_answers_or_joins));
    $kept_history = $with_history ? [] : $history;
    $to_delete = array_values(array_diff($candidates, $kept_history));

    if (!empty($to_delete)) {
        if ($with_history && !empty($history)) {
            $conn->query('DELETE FROM responses WHERE student_pk IN (' . idList($history) . ')');
            $conn->query('DELETE FROM device_sessions WHERE student_pk IN (' . idList($history) . ')');
        }

        $conn->query('DELETE FROM students WHERE id IN (' . idList($to_delete) . ')');
    }

    $conn->commit();

    header(
        'Location: class.php?id=' . $class_id . '&tab=students'
        . '&cleared=' . count($to_delete)
        . '&kept_history=' . count($kept_history)
        . '&kept_live=' . count($kept_live)
    );
    exit;
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $rollback_exception) {
        error_log('Delete students rollback failed: ' . $rollback_exception->getMessage());
    }

    error_log('Delete students failed for class ' . $class_id . ': ' . $exception->getMessage());
    header('Location: class.php?id=' . $class_id . '&tab=students&error=clear');
    exit;
}
