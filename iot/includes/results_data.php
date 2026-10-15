<?php

function resultPercent(int|float $numerator, int|float $denominator): int
{
    if ($denominator <= 0) {
        return 0;
    }

    return (int) round(($numerator / $denominator) * 100);
}

function resultAnswerOptionOrder(string $answer): ?int
{
    return match (strtoupper(trim($answer))) {
        'A' => 1,
        'B' => 2,
        'C' => 3,
        'D' => 4,
        default => null,
    };
}

function loadSessionResultData(
    mysqli $conn,
    int $teacherId,
    int $sessionId
): array {
    if ($teacherId <= 0 || $sessionId <= 0) {
        throw new InvalidArgumentException('Invalid results request.');
    }

    $sessionStmt = $conn->prepare("
        SELECT
            ls.session_id,
            ls.quiz_id,
            ls.class_id,
            ls.status,
            ls.current_question_id,
            ls.started_at,
            ls.ended_at,
            q.title AS quiz_title,
            c.name AS class_name
        FROM live_sessions ls
        JOIN quizzes q
            ON q.quiz_id = ls.quiz_id
        JOIN classes c
            ON c.class_id = ls.class_id
        WHERE ls.session_id = ?
          AND q.teacher_id = ?
          AND c.teacher_id = ?
        LIMIT 1
    ");
    $sessionStmt->bind_param('iii', $sessionId, $teacherId, $teacherId);
    $sessionStmt->execute();
    $sessionResult = $sessionStmt->get_result();

    if ($sessionResult->num_rows !== 1) {
        throw new RuntimeException('Session not found or access denied.');
    }

    $session = $sessionResult->fetch_assoc();

    if ($session['status'] !== 'ENDED') {
        throw new DomainException('Results are available only after the session has ended.');
    }

    $quizId = (int) $session['quiz_id'];
    $classId = (int) $session['class_id'];
    $currentQuestionId = (int) ($session['current_question_id'] ?? 0);

    if ($currentQuestionId <= 0) {
        throw new UnexpectedValueException(
            'This legacy session does not record its last presented question.'
        );
    }

    $questionStmt = $conn->prepare("
        SELECT question_id, question_order, content
        FROM questions
        WHERE quiz_id = ?
        ORDER BY question_order ASC, question_id ASC
    ");
    $questionStmt->bind_param('i', $quizId);
    $questionStmt->execute();
    $questionRows = $questionStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($questionRows)) {
        throw new UnexpectedValueException('This quiz has no questions.');
    }

    $currentQuestionIndex = null;
    $questions = [];
    $questionIndexes = [];

    foreach ($questionRows as $index => $questionRow) {
        $questionId = (int) $questionRow['question_id'];
        $questionIndexes[$questionId] = $index;

        if ($questionId === $currentQuestionId) {
            $currentQuestionIndex = $index;
        }

        $questions[$questionId] = [
            'question_id' => $questionId,
            'question_order' => (int) $questionRow['question_order'],
            'number' => $index + 1,
            'content' => (string) $questionRow['content'],
            'presented' => false,
            'wrong_rate' => null,
            'correct_count' => 0,
            'wrong_count' => 0,
            'unanswered_count' => 0,
            'submitted_count' => 0,
            'invalid_response_count' => 0,
            'options' => []
        ];
    }

    if ($currentQuestionIndex === null) {
        throw new UnexpectedValueException(
            'The session last question does not belong to its quiz.'
        );
    }

    foreach ($questions as $questionId => &$question) {
        $question['presented'] = $questionIndexes[$questionId] <= $currentQuestionIndex;
    }
    unset($question);

    $optionStmt = $conn->prepare("
        SELECT
            qo.option_id,
            qo.question_id,
            qo.option_order,
            qo.content,
            qo.is_correct
        FROM question_options qo
        JOIN questions q
            ON q.question_id = qo.question_id
        WHERE q.quiz_id = ?
        ORDER BY q.question_order ASC, qo.option_order ASC, qo.option_id ASC
    ");
    $optionStmt->bind_param('i', $quizId);
    $optionStmt->execute();
    $optionResult = $optionStmt->get_result();

    while ($option = $optionResult->fetch_assoc()) {
        $questionId = (int) $option['question_id'];

        if (!isset($questions[$questionId])) {
            continue;
        }

        $optionOrder = (int) $option['option_order'];
        $questions[$questionId]['options'][$optionOrder] = [
            'option_id' => (int) $option['option_id'],
            'option_order' => $optionOrder,
            'label' => chr(64 + $optionOrder),
            'content' => (string) $option['content'],
            'is_correct' => (int) $option['is_correct'] === 1,
            'response_count' => 0,
            'response_percent' => 0
        ];
    }

    $classCountStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM students
        WHERE class_id = ?
    ");
    $classCountStmt->bind_param('i', $classId);
    $classCountStmt->execute();
    $classStudentCount = (int) $classCountStmt
        ->get_result()
        ->fetch_assoc()['total'];

    $participantStmt = $conn->prepare("
        SELECT
            s.id AS student_pk,
            s.student_id,
            s.name,
            ds.joined_at
        FROM device_sessions ds
        JOIN students s
            ON s.id = ds.student_pk
        WHERE ds.session_id = ?
        ORDER BY s.name ASC, s.student_id ASC, s.id ASC
    ");
    $participantStmt->bind_param('i', $sessionId);
    $participantStmt->execute();
    $participantResult = $participantStmt->get_result();
    $participants = [];

    while ($participant = $participantResult->fetch_assoc()) {
        $studentPk = (int) $participant['student_pk'];
        $answers = [];

        foreach ($questions as $questionId => $question) {
            $answers[$questionId] = [
                'state' => $question['presented'] ? 'unanswered' : 'not_presented',
                'answer' => null,
                'option_order' => null
            ];
        }

        $participants[$studentPk] = [
            'student_pk' => $studentPk,
            'student_id' => (string) $participant['student_id'],
            'name' => (string) $participant['name'],
            'joined_at' => (string) $participant['joined_at'],
            'correct_count' => 0,
            'submitted_count' => 0,
            'score_percent' => 0,
            'answers' => $answers
        ];
    }

    $responseStmt = $conn->prepare("
        SELECT
            r.question_id,
            r.student_pk,
            r.answer,
            r.answered_at
        FROM responses r
        JOIN questions q
            ON q.question_id = r.question_id
           AND q.quiz_id = ?
        WHERE r.session_id = ?
        ORDER BY r.response_id ASC
    ");
    $responseStmt->bind_param('ii', $quizId, $sessionId);
    $responseStmt->execute();
    $responseResult = $responseStmt->get_result();
    $submittedTotal = 0;
    $correctTotal = 0;

    while ($response = $responseResult->fetch_assoc()) {
        $questionId = (int) $response['question_id'];
        $studentPk = (int) $response['student_pk'];

        if (
            !isset($questions[$questionId])
            || !$questions[$questionId]['presented']
            || !isset($participants[$studentPk])
        ) {
            continue;
        }

        $answer = strtoupper(trim((string) $response['answer']));
        $optionOrder = resultAnswerOptionOrder($answer);
        $option = $optionOrder !== null
            ? ($questions[$questionId]['options'][$optionOrder] ?? null)
            : null;
        $isCorrect = $option !== null && $option['is_correct'];
        $state = $isCorrect ? 'correct' : 'wrong';

        $participants[$studentPk]['answers'][$questionId] = [
            'state' => $state,
            'answer' => $answer,
            'option_order' => $optionOrder
        ];
        $participants[$studentPk]['submitted_count']++;
        $questions[$questionId]['submitted_count']++;
        $submittedTotal++;

        if ($option !== null) {
            $questions[$questionId]['options'][$optionOrder]['response_count']++;
        } else {
            $questions[$questionId]['invalid_response_count']++;
        }

        if ($isCorrect) {
            $participants[$studentPk]['correct_count']++;
            $questions[$questionId]['correct_count']++;
            $correctTotal++;
        } else {
            $questions[$questionId]['wrong_count']++;
        }
    }

    $participantCount = count($participants);
    $presentedCount = $currentQuestionIndex + 1;
    $totalQuestions = count($questions);
    $studentScoreSum = 0;

    foreach ($participants as &$participant) {
        $participant['score_percent'] = resultPercent(
            $participant['correct_count'],
            $presentedCount
        );
        $studentScoreSum += ($participant['correct_count'] / $presentedCount) * 100;
    }
    unset($participant);

    foreach ($questions as &$question) {
        if (!$question['presented']) {
            continue;
        }

        $question['unanswered_count'] = max(
            0,
            $participantCount - $question['submitted_count']
        );
        $question['wrong_rate'] = resultPercent(
            $question['wrong_count'] + $question['unanswered_count'],
            $participantCount
        );

        foreach ($question['options'] as &$option) {
            $option['response_percent'] = resultPercent(
                $option['response_count'],
                $participantCount
            );
        }
        unset($option);
    }
    unset($question);

    return [
        'session' => [
            'session_id' => (int) $session['session_id'],
            'status' => (string) $session['status'],
            'current_question_id' => $currentQuestionId,
            'started_at' => $session['started_at'],
            'ended_at' => $session['ended_at']
        ],
        'quiz' => [
            'quiz_id' => $quizId,
            'title' => (string) $session['quiz_title']
        ],
        'class' => [
            'class_id' => $classId,
            'name' => (string) $session['class_name'],
            'student_count' => $classStudentCount
        ],
        'participants' => array_values($participants),
        'questions' => array_values($questions),
        'overview' => [
            'average_score' => $participantCount > 0
                ? (int) round($studentScoreSum / $participantCount)
                : 0,
            'participant_count' => $participantCount,
            'class_student_count' => $classStudentCount,
            'presented_question_count' => $presentedCount,
            'total_question_count' => $totalQuestions,
            'submitted_response_count' => $submittedTotal,
            'correct_response_count' => $correctTotal,
            'completion' => resultPercent(
                $submittedTotal,
                $participantCount * $presentedCount
            ),
            'accuracy' => resultPercent($correctTotal, $submittedTotal)
        ]
    ];
}

function loadTeacherResultHistory(
    mysqli $conn,
    int $teacherId,
    ?int $classId = null,
    int $limit = 0
): array {
    $sql = "
        SELECT
            ls.session_id,
            ls.class_id,
            ls.quiz_id,
            ls.started_at,
            ls.ended_at,
            q.title AS quiz_title,
            c.name AS class_name,
            (SELECT COUNT(*) FROM students cs WHERE cs.class_id = ls.class_id)
                AS class_student_count,
            (SELECT COUNT(*) FROM device_sessions ds WHERE ds.session_id = ls.session_id)
                AS participant_count,
            (SELECT COUNT(*) FROM questions tq WHERE tq.quiz_id = ls.quiz_id)
                AS total_question_count,
            (
                SELECT COUNT(*)
                FROM questions pq
                JOIN questions cq
                    ON cq.question_id = ls.current_question_id
                   AND cq.quiz_id = ls.quiz_id
                WHERE pq.quiz_id = ls.quiz_id
                  AND pq.question_order <= cq.question_order
            ) AS presented_question_count,
            (
                SELECT COUNT(*)
                FROM responses r
                JOIN device_sessions ds
                    ON ds.session_id = r.session_id
                   AND ds.student_pk = r.student_pk
                JOIN questions rq
                    ON rq.question_id = r.question_id
                   AND rq.quiz_id = ls.quiz_id
                JOIN questions cq
                    ON cq.question_id = ls.current_question_id
                   AND cq.quiz_id = ls.quiz_id
                JOIN question_options qo
                    ON qo.question_id = r.question_id
                   AND qo.option_order = CASE UPPER(TRIM(r.answer))
                        WHEN 'A' THEN 1
                        WHEN 'B' THEN 2
                        WHEN 'C' THEN 3
                        WHEN 'D' THEN 4
                        ELSE 0
                   END
                   AND qo.is_correct = 1
                WHERE r.session_id = ls.session_id
                  AND rq.question_order <= cq.question_order
            ) AS correct_response_count
        FROM live_sessions ls
        JOIN quizzes q
            ON q.quiz_id = ls.quiz_id
        JOIN classes c
            ON c.class_id = ls.class_id
        WHERE ls.status = 'ENDED'
          AND q.teacher_id = ?
          AND c.teacher_id = ?
    ";

    if ($classId !== null) {
        $sql .= " AND ls.class_id = ?";
    }

    $sql .= " ORDER BY COALESCE(ls.ended_at, ls.started_at) DESC, ls.session_id DESC";

    if ($limit > 0) {
        $sql .= " LIMIT ?";
    }

    $stmt = $conn->prepare($sql);

    if ($classId !== null && $limit > 0) {
        $stmt->bind_param('iiii', $teacherId, $teacherId, $classId, $limit);
    } elseif ($classId !== null) {
        $stmt->bind_param('iii', $teacherId, $teacherId, $classId);
    } elseif ($limit > 0) {
        $stmt->bind_param('iii', $teacherId, $teacherId, $limit);
    } else {
        $stmt->bind_param('ii', $teacherId, $teacherId);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as &$row) {
        $participants = (int) $row['participant_count'];
        $presented = (int) $row['presented_question_count'];
        $correct = (int) $row['correct_response_count'];
        $row['interpretable'] = $presented > 0;
        $row['average_score'] = $presented > 0
            ? resultPercent($correct, $participants * $presented)
            : null;
    }
    unset($row);

    return $rows;
}
