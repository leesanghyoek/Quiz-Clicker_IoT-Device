<?php
declare(strict_types=1);

require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/quiz_lock.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: quizzes.php');
    exit;
}

$teacher_id = (int) $_SESSION['teacher_id'];
$quiz_id = filter_input(INPUT_POST, 'quiz_id', FILTER_VALIDATE_INT);
$posted_token = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['quiz_builder_csrf_token'] ?? '';
$title = trim((string) ($_POST['title'] ?? ''));
$questions_data = $_POST['questions'] ?? [];

$fail = static function (int $quiz_id, string $message): never {
    $_SESSION['quiz_builder_error'] = $message;
    header('Location: quiz.php?id=' . $quiz_id . '&error=save');
    exit;
};

if ($quiz_id === false || $quiz_id <= 0) {
    header('Location: quizzes.php?error=access');
    exit;
}

if (
    !is_string($posted_token)
    || !is_string($session_token)
    || $session_token === ''
    || !hash_equals($session_token, $posted_token)
) {
    $fail($quiz_id, 'The editor session expired. Refresh the page and try again.');
}

if ($title === '' || strlen($title) > 255) {
    $fail($quiz_id, 'Quiz title is required and must not exceed 255 characters.');
}

if (!is_array($questions_data)) {
    $fail($quiz_id, 'The submitted question list is invalid.');
}

$normalized_questions = [];
$submitted_question_ids = [];

try {
    foreach (array_values($questions_data) as $question_index => $question_data) {
        if (!is_array($question_data)) {
            throw new RuntimeException('Question ' . ($question_index + 1) . ' is invalid.');
        }

        $question_id = (int) ($question_data['question_id'] ?? 0);
        $content = trim((string) ($question_data['content'] ?? ''));
        $options_data = $question_data['options'] ?? [];
        $correct_option = filter_var(
            $question_data['correct_option'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($question_id < 0) {
            throw new RuntimeException('Question ' . ($question_index + 1) . ' has an invalid ID.');
        }

        if ($question_id > 0) {
            if (isset($submitted_question_ids[$question_id])) {
                throw new RuntimeException('The submitted question list contains duplicates.');
            }

            $submitted_question_ids[$question_id] = true;
        }

        if ($content === '') {
            throw new RuntimeException('Question ' . ($question_index + 1) . ': content is required.');
        }

        if (!is_array($options_data)) {
            throw new RuntimeException('Question ' . ($question_index + 1) . ': options are invalid.');
        }

        $options_data = array_values($options_data);
        $option_count = count($options_data);

        if ($option_count < 2 || $option_count > 4) {
            throw new RuntimeException(
                'Question ' . ($question_index + 1) . ': provide between 2 and 4 options.'
            );
        }

        if (
            $correct_option === false
            || $correct_option < 0
            || $correct_option >= $option_count
        ) {
            throw new RuntimeException(
                'Question ' . ($question_index + 1) . ': select exactly one correct answer.'
            );
        }

        $normalized_options = [];
        $submitted_option_ids = [];

        foreach ($options_data as $option_index => $option_data) {
            if (!is_array($option_data)) {
                throw new RuntimeException(
                    'Question ' . ($question_index + 1) . ': option ' . ($option_index + 1) . ' is invalid.'
                );
            }

            $option_id = (int) ($option_data['option_id'] ?? 0);
            $option_content = trim((string) ($option_data['content'] ?? ''));

            if ($option_id < 0 || $option_content === '') {
                throw new RuntimeException(
                    'Question ' . ($question_index + 1) . ': every option requires content.'
                );
            }

            if ($option_id > 0) {
                if (isset($submitted_option_ids[$option_id])) {
                    throw new RuntimeException(
                        'Question ' . ($question_index + 1) . ': duplicate option detected.'
                    );
                }

                $submitted_option_ids[$option_id] = true;
            }

            $normalized_options[] = [
                'option_id' => $option_id,
                'content' => $option_content,
                'is_correct' => $option_index === $correct_option ? 1 : 0
            ];
        }

        $normalized_questions[] = [
            'question_id' => $question_id,
            'content' => $content,
            'options' => $normalized_options
        ];
    }
} catch (Throwable $exception) {
    $fail($quiz_id, $exception->getMessage());
}

$transaction_started = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->begin_transaction();
    $transaction_started = true;

    $quiz_state = loadOwnedQuizEditState(
        $conn,
        $quiz_id,
        $teacher_id,
        true
    );

    if ($quiz_state === null) {
        $conn->rollback();
        $transaction_started = false;
        header('Location: quizzes.php?error=access');
        exit;
    }

    if ($quiz_state['is_locked']) {
        $conn->rollback();
        $transaction_started = false;
        $fail(
            $quiz_id,
            'Locked — This quiz has already been used in a live session.'
        );
    }

    $existing_question_stmt = $conn->prepare("
        SELECT question_id
        FROM questions
        WHERE quiz_id = ?
        ORDER BY question_order, question_id
        FOR UPDATE
    ");
    $existing_question_stmt->bind_param('i', $quiz_id);
    $existing_question_stmt->execute();
    $existing_question_result = $existing_question_stmt->get_result();
    $existing_question_ids = [];

    while ($row = $existing_question_result->fetch_assoc()) {
        $existing_question_ids[(int) $row['question_id']] = true;
    }

    if (array_keys($existing_question_ids) !== array_keys($submitted_question_ids)) {
        $existing_ids = array_keys($existing_question_ids);
        $submitted_ids = array_keys($submitted_question_ids);
        sort($existing_ids, SORT_NUMERIC);
        sort($submitted_ids, SORT_NUMERIC);

        if ($existing_ids !== $submitted_ids) {
            throw new RuntimeException(
                'The question list changed in another request. Refresh before saving.'
            );
        }
    }

    $update_quiz_stmt = $conn->prepare("
        UPDATE quizzes
        SET title = ?
        WHERE quiz_id = ? AND teacher_id = ?
    ");
    $update_quiz_stmt->bind_param('sii', $title, $quiz_id, $teacher_id);
    $update_quiz_stmt->execute();

    $existing_options_stmt = $conn->prepare("
        SELECT option_id
        FROM question_options
        WHERE question_id = ?
        ORDER BY option_order, option_id
        FOR UPDATE
    ");
    $shift_options_stmt = $conn->prepare("
        UPDATE question_options
        SET option_order = option_order + 100
        WHERE question_id = ?
    ");
    $update_question_stmt = $conn->prepare("
        UPDATE questions
        SET content = ?, question_order = ?
        WHERE question_id = ? AND quiz_id = ?
    ");
    $insert_question_stmt = $conn->prepare("
        INSERT INTO questions (quiz_id, question_order, content)
        VALUES (?, ?, ?)
    ");
    $update_option_stmt = $conn->prepare("
        UPDATE question_options
        SET option_order = ?, content = ?, is_correct = ?
        WHERE option_id = ? AND question_id = ?
    ");
    $insert_option_stmt = $conn->prepare("
        INSERT INTO question_options
            (question_id, option_order, content, is_correct)
        VALUES (?, ?, ?, ?)
    ");
    $delete_option_stmt = $conn->prepare("
        DELETE FROM question_options
        WHERE option_id = ? AND question_id = ?
    ");

    foreach ($normalized_questions as $question_index => &$question_data) {
        $question_id = $question_data['question_id'];
        $temporary_order = -($question_index + 1);
        $question_content = $question_data['content'];

        if ($question_id > 0) {
            if (!isset($existing_question_ids[$question_id])) {
                throw new RuntimeException('A question does not belong to this quiz.');
            }

            $update_question_stmt->bind_param(
                'siii',
                $question_content,
                $temporary_order,
                $question_id,
                $quiz_id
            );
            $update_question_stmt->execute();

            if ($update_question_stmt->affected_rows !== 1) {
                throw new RuntimeException('A question could not be updated.');
            }
        } else {
            $insert_question_stmt->bind_param(
                'iis',
                $quiz_id,
                $temporary_order,
                $question_content
            );
            $insert_question_stmt->execute();
            $question_id = (int) $conn->insert_id;
            $question_data['question_id'] = $question_id;
        }

        $existing_options_stmt->bind_param('i', $question_id);
        $existing_options_stmt->execute();
        $existing_options_result = $existing_options_stmt->get_result();
        $existing_option_ids = [];

        while ($row = $existing_options_result->fetch_assoc()) {
            $existing_option_ids[(int) $row['option_id']] = true;
        }

        $submitted_existing_option_ids = [];

        foreach ($question_data['options'] as $option_data) {
            $option_id = $option_data['option_id'];

            if ($option_id <= 0) {
                continue;
            }

            if (!isset($existing_option_ids[$option_id])) {
                throw new RuntimeException('An answer option does not belong to its question.');
            }

            $submitted_existing_option_ids[$option_id] = true;
        }

        $shift_options_stmt->bind_param('i', $question_id);
        $shift_options_stmt->execute();

        foreach ($question_data['options'] as $option_index => $option_data) {
            $option_id = $option_data['option_id'];
            $option_order = $option_index + 1;
            $option_content = $option_data['content'];
            $is_correct = $option_data['is_correct'];

            if ($option_id > 0) {
                $update_option_stmt->bind_param(
                    'isiii',
                    $option_order,
                    $option_content,
                    $is_correct,
                    $option_id,
                    $question_id
                );
                $update_option_stmt->execute();

                if ($update_option_stmt->affected_rows !== 1) {
                    throw new RuntimeException('An answer option could not be updated.');
                }
            } else {
                $insert_option_stmt->bind_param(
                    'iisi',
                    $question_id,
                    $option_order,
                    $option_content,
                    $is_correct
                );
                $insert_option_stmt->execute();
            }
        }

        foreach ($existing_option_ids as $option_id => $_unused) {
            if (!isset($submitted_existing_option_ids[$option_id])) {
                $delete_option_stmt->bind_param('ii', $option_id, $question_id);
                $delete_option_stmt->execute();
            }
        }
    }
    unset($question_data);

    $final_order_stmt = $conn->prepare("
        UPDATE questions
        SET question_order = ?
        WHERE question_id = ? AND quiz_id = ?
    ");

    foreach ($normalized_questions as $question_index => $question_data) {
        $final_order = $question_index + 1;
        $question_id = $question_data['question_id'];
        $final_order_stmt->bind_param('iii', $final_order, $question_id, $quiz_id);
        $final_order_stmt->execute();
    }

    $conn->commit();
    $transaction_started = false;
    unset($_SESSION['quiz_builder_error']);

    header('Location: quiz.php?id=' . $quiz_id . '&saved=1');
    exit;
} catch (Throwable $exception) {
    if ($transaction_started) {
        $conn->rollback();
    }

    error_log(
        'Quiz builder save failed for teacher '
        . $teacher_id
        . ': '
        . $exception->getMessage()
    );
    $fail($quiz_id, get_class($exception) === RuntimeException::class
        ? $exception->getMessage()
        : 'Unable to save quiz changes.');
}
