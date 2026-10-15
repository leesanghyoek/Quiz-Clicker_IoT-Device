<?php
/*
 * Learning statistics across finished quiz sessions, and review quizzes
 * built from the questions a class misses most.
 *
 * The counting matches the session results page:
 *   - only ENDED sessions that at least one student joined count;
 *   - a question was presented if it comes at or before the session's last
 *     presented question (current_question_id);
 *   - every student who joined the session is one attempt at each presented
 *     question: the correct option is "correct", another option is "wrong",
 *     and no answer is "no answer". Missed = wrong + no answer.
 */

/** Quizzes this class has finished with at least one student, newest first. */
function loadReviewQuizzes(mysqli $conn, int $teacherId, int $classId): array
{
    $stmt = $conn->prepare("
        SELECT
            q.quiz_id,
            q.title,
            COUNT(ls.session_id) AS session_count,
            MAX(COALESCE(ls.ended_at, ls.started_at)) AS last_session_at
        FROM live_sessions ls
        JOIN quizzes q ON q.quiz_id = ls.quiz_id
        WHERE q.teacher_id = ?
          AND ls.class_id = ?
          AND ls.status = 'ENDED'
          AND ls.current_question_id IS NOT NULL
          AND EXISTS (SELECT 1 FROM device_sessions ds WHERE ds.session_id = ls.session_id)
        GROUP BY q.quiz_id, q.title
        ORDER BY last_session_at DESC, q.title
    ");
    $stmt->bind_param('ii', $teacherId, $classId);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Safe to inline: every value is cast to int.
function reviewIdList(array $ids): string
{
    return implode(',', array_map('intval', $ids));
}

/**
 * Per-question statistics for the chosen quizzes in one class, most missed first.
 *
 * @param array<int, int> $quizIds
 * @return array{sessions: int, students: int, questions: array<int, array>}
 */
function loadReviewStats(mysqli $conn, int $teacherId, int $classId, array $quizIds): array
{
    $empty = ['sessions' => 0, 'students' => 0, 'questions' => []];
    $quizIds = array_values(array_filter(array_map('intval', $quizIds), static fn ($id) => $id > 0));

    if (empty($quizIds)) {
        return $empty;
    }

    $stmt = $conn->prepare("
        SELECT ls.session_id, ls.quiz_id, ls.current_question_id
        FROM live_sessions ls
        JOIN quizzes q ON q.quiz_id = ls.quiz_id
        WHERE q.teacher_id = ?
          AND ls.class_id = ?
          AND ls.status = 'ENDED'
          AND ls.current_question_id IS NOT NULL
          AND ls.quiz_id IN (" . reviewIdList($quizIds) . ")
          AND EXISTS (SELECT 1 FROM device_sessions ds WHERE ds.session_id = ls.session_id)
    ");
    $stmt->bind_param('ii', $teacherId, $classId);
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($sessions)) {
        return $empty;
    }

    $sessionIds = array_map(static fn ($s) => (int) $s['session_id'], $sessions);

    // Questions in quiz order, with their options.
    $questions = [];
    $orderInQuiz = [];
    $result = $conn->query("
        SELECT q.question_id, q.quiz_id, q.question_order, q.content, z.title AS quiz_title
        FROM questions q
        JOIN quizzes z ON z.quiz_id = q.quiz_id
        WHERE q.quiz_id IN (" . reviewIdList($quizIds) . ")
        ORDER BY q.quiz_id, q.question_order, q.question_id
    ");

    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $questionId = (int) $row['question_id'];
        $quizId = (int) $row['quiz_id'];
        $orderInQuiz[$quizId][$questionId] = count($orderInQuiz[$quizId] ?? []);
        $questions[$questionId] = [
            'question_id' => $questionId,
            'quiz_id' => $quizId,
            'quiz_title' => (string) $row['quiz_title'],
            'number' => $orderInQuiz[$quizId][$questionId] + 1,
            'content' => (string) $row['content'],
            'options' => [],
            'correct_letter' => null,
            'attempts' => 0,
            'correct' => 0,
            'wrong' => 0,
            'no_answer' => 0,
            'wrong_choices' => []
        ];
    }

    $result = $conn->query("
        SELECT question_id, option_order, content, is_correct
        FROM question_options
        WHERE question_id IN (" . reviewIdList(array_keys($questions) ?: [0]) . ")
        ORDER BY question_id, option_order
    ");

    foreach ($result->fetch_all(MYSQLI_ASSOC) as $option) {
        $questionId = (int) $option['question_id'];
        $letter = chr(64 + (int) $option['option_order']);
        $questions[$questionId]['options'][$letter] = (string) $option['content'];

        if ((int) $option['is_correct'] === 1) {
            $questions[$questionId]['correct_letter'] = $letter;
        }
    }

    // Who joined each session.
    $participants = [];
    $students = [];
    $result = $conn->query("
        SELECT session_id, student_pk
        FROM device_sessions
        WHERE session_id IN (" . reviewIdList($sessionIds) . ")
    ");

    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $participants[(int) $row['session_id']][(int) $row['student_pk']] = true;
        $students[(int) $row['student_pk']] = true;
    }

    // Each joined student is one attempt at every question the session presented.
    $presented = [];

    foreach ($sessions as $session) {
        $sessionId = (int) $session['session_id'];
        $quizId = (int) $session['quiz_id'];
        $lastIndex = $orderInQuiz[$quizId][(int) $session['current_question_id']] ?? null;
        $joined = count($participants[$sessionId] ?? []);

        if ($lastIndex === null || $joined === 0) {
            continue;
        }

        foreach ($orderInQuiz[$quizId] as $questionId => $index) {
            if ($index <= $lastIndex) {
                $presented[$sessionId][$questionId] = true;
                $questions[$questionId]['attempts'] += $joined;
            }
        }
    }

    $result = $conn->query("
        SELECT session_id, question_id, student_pk, answer
        FROM responses
        WHERE session_id IN (" . reviewIdList($sessionIds) . ")
    ");

    foreach ($result->fetch_all(MYSQLI_ASSOC) as $response) {
        $sessionId = (int) $response['session_id'];
        $questionId = (int) $response['question_id'];

        if (
            !isset($presented[$sessionId][$questionId])
            || !isset($participants[$sessionId][(int) $response['student_pk']])
        ) {
            continue;
        }

        $letter = strtoupper(trim((string) $response['answer']));

        if ($letter === $questions[$questionId]['correct_letter']) {
            $questions[$questionId]['correct']++;
        } else {
            $questions[$questionId]['wrong']++;
            $questions[$questionId]['wrong_choices'][$letter] = ($questions[$questionId]['wrong_choices'][$letter] ?? 0) + 1;
        }
    }

    $rows = [];

    foreach ($questions as $question) {
        if ($question['attempts'] === 0) {
            continue;
        }

        $question['no_answer'] = max(0, $question['attempts'] - $question['correct'] - $question['wrong']);
        $question['missed_rate'] = ($question['wrong'] + $question['no_answer']) / $question['attempts'];
        arsort($question['wrong_choices']);
        $question['top_wrong'] = array_key_first($question['wrong_choices']);
        $rows[] = $question;
    }

    usort($rows, static function (array $a, array $b): int {
        return [$b['missed_rate'], $b['attempts'], $a['quiz_id'], $a['number']]
            <=> [$a['missed_rate'], $a['attempts'], $b['quiz_id'], $b['number']];
    });

    return [
        'sessions' => count($sessions),
        'students' => count($students),
        'questions' => $rows
    ];
}

/**
 * Copies the chosen questions (and their options) into a new quiz for the
 * class. The originals stay untouched, so either can be edited later.
 *
 * @param array<int, int> $questionIds in the order they should appear
 * @return int the new quiz id
 */
function createReviewQuiz(mysqli $conn, int $teacherId, int $classId, string $title, array $questionIds): int
{
    $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds), static fn ($id) => $id > 0)));

    if (empty($questionIds)) {
        throw new InvalidArgumentException('No questions chosen.');
    }

    $conn->begin_transaction();

    try {
        // Only the teacher's own questions can be copied.
        $result = $conn->query("
            SELECT q.question_id, q.content
            FROM questions q
            JOIN quizzes z ON z.quiz_id = q.quiz_id
            WHERE z.teacher_id = " . (int) $teacherId . "
              AND q.question_id IN (" . reviewIdList($questionIds) . ")
        ");
        $sources = [];

        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $sources[(int) $row['question_id']] = (string) $row['content'];
        }

        if (count($sources) !== count($questionIds)) {
            throw new InvalidArgumentException('Some questions could not be found.');
        }

        $stmt = $conn->prepare('INSERT INTO quizzes (teacher_id, title) VALUES (?, ?)');
        $stmt->bind_param('is', $teacherId, $title);
        $stmt->execute();
        $quizId = (int) $conn->insert_id;

        $insertQuestion = $conn->prepare('INSERT INTO questions (quiz_id, question_order, content) VALUES (?, ?, ?)');
        $copyOptions = $conn->prepare("
            INSERT INTO question_options (question_id, option_order, content, is_correct)
            SELECT ?, option_order, content, is_correct
            FROM question_options
            WHERE question_id = ?
            ORDER BY option_order
        ");

        foreach ($questionIds as $position => $sourceId) {
            $order = $position + 1;
            $insertQuestion->bind_param('iis', $quizId, $order, $sources[$sourceId]);
            $insertQuestion->execute();
            $newQuestionId = (int) $conn->insert_id;
            $copyOptions->bind_param('ii', $newQuestionId, $sourceId);
            $copyOptions->execute();
        }

        $stmt = $conn->prepare('INSERT INTO class_quizzes (class_id, quiz_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $classId, $quizId);
        $stmt->execute();

        $conn->commit();

        return $quizId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
