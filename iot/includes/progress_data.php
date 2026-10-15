<?php
/*
 * Learning progress of one class across its finished quiz sessions.
 *
 * Every session is scored by loadSessionResultData(), the same numbers the
 * session results page shows: a student's score is correct answers out of
 * the questions presented, and the class score is the mean of the scores of
 * the students who joined. Sessions nobody joined are left out.
 */
require_once __DIR__ . '/results_data.php';

const PROGRESS_ATTENTION_SCORE = 50;      // average below this needs attention
const PROGRESS_ATTENTION_ATTENDANCE = 50; // or joined fewer than this share of sessions

function loadClassProgress(mysqli $conn, int $teacherId, int $classId, int $maxSessions = 30): array
{
    $stmt = $conn->prepare("
        SELECT ls.session_id
        FROM live_sessions ls
        JOIN quizzes q ON q.quiz_id = ls.quiz_id
        JOIN classes c ON c.class_id = ls.class_id
        WHERE ls.class_id = ?
          AND q.teacher_id = ?
          AND c.teacher_id = ?
          AND ls.status = 'ENDED'
          AND ls.current_question_id IS NOT NULL
          AND EXISTS (SELECT 1 FROM device_sessions ds WHERE ds.session_id = ls.session_id)
        ORDER BY COALESCE(ls.ended_at, ls.started_at) DESC, ls.session_id DESC
        LIMIT ?
    ");
    $stmt->bind_param('iiii', $classId, $teacherId, $teacherId, $maxSessions);
    $stmt->execute();
    $sessionIds = array_reverse(array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_NUM), 0)));

    $sessions = [];
    $scores = []; // [student_pk][session_id] => score percent

    foreach ($sessionIds as $sessionId) {
        try {
            $data = loadSessionResultData($conn, $teacherId, $sessionId);
        } catch (Throwable $exception) {
            continue; // a session whose questions changed too much to score
        }

        $overview = $data['overview'];

        if ($overview['participant_count'] === 0) {
            continue;
        }

        $sessions[] = [
            'session_id' => $sessionId,
            'quiz_title' => $data['quiz']['title'],
            'date' => (string) ($data['session']['ended_at'] ?? $data['session']['started_at']),
            'average' => $overview['average_score'],
            'joined' => $overview['participant_count'],
            'class_size' => $overview['class_student_count'],
            'questions' => $overview['presented_question_count']
        ];

        foreach ($data['participants'] as $participant) {
            $scores[$participant['student_pk']][$sessionId] = $participant['score_percent'];
        }
    }

    $stmt = $conn->prepare('SELECT id, student_id, name FROM students WHERE class_id = ? ORDER BY student_id, id');
    $stmt->bind_param('i', $classId);
    $stmt->execute();

    $students = [];
    $sessionCount = count($sessions);

    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $pk = (int) $row['id'];
        $history = [];

        foreach ($sessions as $session) {
            $history[] = $scores[$pk][$session['session_id']] ?? null;
        }

        $taken = array_values(array_filter($history, static fn ($score) => $score !== null));
        $joined = count($taken);
        $average = $joined > 0 ? (int) round(array_sum($taken) / $joined) : null;
        $attendance = $sessionCount > 0 ? resultPercent($joined, $sessionCount) : 0;
        $change = $joined >= 2 ? $taken[$joined - 1] - $taken[$joined - 2] : null;

        $reasons = [];

        if ($average !== null && $average < PROGRESS_ATTENTION_SCORE) {
            $reasons[] = 'low scores';
        }

        if ($sessionCount >= 2 && $attendance < PROGRESS_ATTENTION_ATTENDANCE) {
            $reasons[] = 'often absent';
        }

        $students[] = [
            'student_pk' => $pk,
            'student_id' => (string) $row['student_id'],
            'name' => (string) $row['name'],
            'history' => $history,
            'joined' => $joined,
            'average' => $average,
            'last' => $joined > 0 ? $taken[$joined - 1] : null,
            'change' => $change,
            'attendance' => $attendance,
            'reasons' => $reasons
        ];
    }

    // Score bands of the students who took part at least once.
    $bands = [];

    foreach ([[0, 19], [20, 39], [40, 59], [60, 79], [80, 100]] as [$low, $high]) {
        $bands[] = ['low' => $low, 'high' => $high, 'students' => []];
    }

    foreach ($students as $student) {
        if ($student['average'] !== null) {
            $bands[min(4, intdiv($student['average'], 20))]['students'][] = $student;
        }
    }

    return [
        'sessions' => $sessions,
        'students' => $students,
        'bands' => $bands
    ];
}
