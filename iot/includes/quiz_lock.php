<?php
declare(strict_types=1);

/**
 * Load the edit-lock state for a quiz owned by a teacher.
 *
 * When $for_update is true, the quiz row is locked for the surrounding
 * transaction. start_quiz.php takes the same row lock before setting
 * started_at, so starting a session and editing quiz content cannot race.
 */
function loadOwnedQuizEditState(
    mysqli $conn,
    int $quiz_id,
    int $teacher_id,
    bool $for_update = false
): ?array {
    $sql = "
        SELECT quiz_id
        FROM quizzes
        WHERE quiz_id = ?
          AND teacher_id = ?
    ";

    if ($for_update) {
        $sql .= ' FOR UPDATE';
    }

    $quiz_stmt = $conn->prepare($sql);
    $quiz_stmt->bind_param('ii', $quiz_id, $teacher_id);
    $quiz_stmt->execute();

    if ($quiz_stmt->get_result()->num_rows !== 1) {
        return null;
    }

    $started_stmt = $conn->prepare("
        SELECT 1
        FROM live_sessions
        WHERE quiz_id = ?
          AND started_at IS NOT NULL
        LIMIT 1
    ");
    $started_stmt->bind_param('i', $quiz_id);
    $started_stmt->execute();

    return [
        'quiz_id' => $quiz_id,
        'is_locked' => $started_stmt->get_result()->num_rows === 1
    ];
}
