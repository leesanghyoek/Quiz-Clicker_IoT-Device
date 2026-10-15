<?php
/*
 * Quiz sessions that have not ended yet (waiting in the lobby, or running),
 * so a teacher who closed the page can find them again.
 */

/** @return array<int, array> newest first */
function loadOpenSessions(mysqli $conn, int $teacherId): array
{
    $stmt = $conn->prepare("
        SELECT
            ls.session_id,
            ls.status,
            ls.join_code,
            ls.created_at,
            ls.started_at,
            -- Ages computed by the database, whose clock and time zone wrote these times.
            TIMESTAMPDIFF(MINUTE, ls.created_at, NOW()) AS opened_minutes,
            DATE_FORMAT(ls.created_at, '%d/%m %H:%i') AS opened_label,
            TIMESTAMPDIFF(MINUTE, ls.started_at, NOW()) AS started_minutes,
            DATE_FORMAT(ls.started_at, '%d/%m %H:%i') AS started_label,
            q.title AS quiz_title,
            c.name AS class_name,
            (SELECT COUNT(*) FROM device_sessions ds WHERE ds.session_id = ls.session_id) AS joined,
            (SELECT COUNT(*) FROM students s WHERE s.class_id = ls.class_id) AS class_size,
            (SELECT COUNT(*) FROM questions tq WHERE tq.quiz_id = ls.quiz_id) AS question_count,
            (
                SELECT COUNT(*)
                FROM questions pq
                JOIN questions cq ON cq.question_id = ls.current_question_id
                WHERE pq.quiz_id = ls.quiz_id
                  AND pq.question_order <= cq.question_order
            ) AS question_number
        FROM live_sessions ls
        JOIN quizzes q ON q.quiz_id = ls.quiz_id
        JOIN classes c ON c.class_id = ls.class_id
        WHERE ls.status IN ('WAITING', 'ACTIVE')
          AND q.teacher_id = ?
          AND c.teacher_id = ?
        ORDER BY ls.status = 'ACTIVE' DESC, COALESCE(ls.started_at, ls.created_at) DESC, ls.session_id DESC
    ");
    $stmt->bind_param('ii', $teacherId, $teacherId);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Where the teacher continues a session: the lobby before Start, the run page after. */
function openSessionUrl(array $session): string
{
    return ($session['status'] === 'ACTIVE' ? 'quiz_run.php' : 'lobby.php')
        . '?session_id=' . (int) $session['session_id'];
}
