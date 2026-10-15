<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$session_id = (int) ($_GET['session_id'] ?? 0);
$error = '';
$live_session = null;
$current_question = null;
$options = [];
$question_number = 0;
$total_questions = 0;
$joined_count = 0;
$answered_count = 0;
$waiting_count = 0;
$is_last_question = false;
$action_error = '';

if (empty($_SESSION['next_question_csrf_token'])) {
    $_SESSION['next_question_csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['finish_quiz_csrf_token'])) {
    $_SESSION['finish_quiz_csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['end_quiz_csrf_token'])) {
    $_SESSION['end_quiz_csrf_token'] = bin2hex(random_bytes(32));
}

$next_question_csrf_token = $_SESSION['next_question_csrf_token'];
$finish_quiz_csrf_token = $_SESSION['finish_quiz_csrf_token'];
$end_quiz_csrf_token = $_SESSION['end_quiz_csrf_token'];

if ($session_id <= 0) {
    http_response_code(400);
    $error = 'Invalid live session.';
} else {
    try {
        $stmt = $conn->prepare("
            SELECT
                ls.session_id,
                ls.quiz_id,
                ls.status,
                ls.current_question_id,
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
        ");
        $stmt->bind_param('iii', $session_id, $teacher_id, $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows !== 1) {
            http_response_code(403);
            $error = 'Live session not found or access denied.';
        } else {
            $live_session = $result->fetch_assoc();

            if ($live_session['status'] !== 'ACTIVE') {
                http_response_code(409);
                $error = 'This live session is not active.';
                $live_session = null;
            }
        }

        if ($live_session !== null) {
            $quiz_id = (int) $live_session['quiz_id'];
            $current_question_id = (int) $live_session['current_question_id'];

            if ($current_question_id <= 0) {
                http_response_code(409);
                $error = 'No current question is open for this session.';
                $live_session = null;
            } else {
                $question_stmt = $conn->prepare("
                    SELECT question_id, question_order, content
                    FROM questions
                    WHERE quiz_id = ?
                    ORDER BY question_order ASC, question_id ASC
                ");
                $question_stmt->bind_param('i', $quiz_id);
                $question_stmt->execute();
                $question_result = $question_stmt->get_result();
                $ordered_questions = [];

                while ($question = $question_result->fetch_assoc()) {
                    $ordered_questions[] = $question;
                }

                $total_questions = count($ordered_questions);

                foreach ($ordered_questions as $index => $question) {
                    if ((int) $question['question_id'] === $current_question_id) {
                        $current_question = $question;
                        $question_number = $index + 1;
                        break;
                    }
                }

                if ($current_question === null) {
                    http_response_code(409);
                    $error = 'The current question does not belong to this quiz.';
                    $live_session = null;
                } else {
                    $current_question_order = (int) $current_question['question_order'];
                    $has_next_question = false;

                    foreach ($ordered_questions as $question) {
                        if ((int) $question['question_order'] > $current_question_order) {
                            $has_next_question = true;
                            break;
                        }
                    }

                    $is_last_question = !$has_next_question;
                }
            }
        }

        if ($live_session !== null && $current_question !== null) {
            $current_question_id = (int) $current_question['question_id'];

            $option_stmt = $conn->prepare("
                SELECT option_order, content
                FROM question_options
                WHERE question_id = ?
                ORDER BY option_order ASC, option_id ASC
            ");
            $option_stmt->bind_param('i', $current_question_id);
            $option_stmt->execute();
            $option_result = $option_stmt->get_result();

            while ($option = $option_result->fetch_assoc()) {
                $options[] = $option;
            }

            $joined_stmt = $conn->prepare("
                SELECT COUNT(DISTINCT student_pk) AS joined_count
                FROM device_sessions
                WHERE session_id = ?
            ");
            $joined_stmt->bind_param('i', $session_id);
            $joined_stmt->execute();
            $joined_count = (int) $joined_stmt
                ->get_result()
                ->fetch_assoc()['joined_count'];

            $answered_stmt = $conn->prepare("
                SELECT COUNT(DISTINCT r.student_pk) AS answered_count
                FROM responses r
                JOIN device_sessions ds
                    ON ds.session_id = r.session_id
                   AND ds.student_pk = r.student_pk
                WHERE r.session_id = ?
                  AND r.question_id = ?
            ");
            $answered_stmt->bind_param(
                'ii',
                $session_id,
                $current_question_id
            );
            $answered_stmt->execute();
            $answered_count = (int) $answered_stmt
                ->get_result()
                ->fetch_assoc()['answered_count'];
            $waiting_count = max(0, $joined_count - $answered_count);
        }
    } catch (Throwable $exception) {
        error_log(
            'Quiz run load failed for teacher '
            . $teacher_id
            . ': '
            . $exception->getMessage()
        );
        http_response_code(500);
        $error = 'Unable to load the live session.';
        $live_session = null;
    }
}

if ($error === '') {
    $next_error = $_GET['error'] ?? '';

    if ($next_error === 'csrf') {
        $action_error = 'Your form session expired. Please try again.';
    } elseif ($next_error === 'status') {
        $action_error = 'Only an active session can move to the next question.';
    } elseif ($next_error === 'question') {
        $action_error = 'The current question is invalid for this quiz.';
    } elseif ($next_error === 'final') {
        $action_error = 'This quiz is already on its final question.';
    } elseif ($next_error === 'stale') {
        $action_error = 'The question already changed. No additional question was skipped.';
    } elseif ($next_error === 'not_final') {
        $action_error = 'The quiz can only be finished from its final question.';
    } elseif ($next_error === 'next') {
        $action_error = 'Unable to open the next question. Please try again.';
    } elseif ($next_error === 'finish') {
        $action_error = 'Unable to finish the quiz. Please try again.';
    } elseif ($next_error === 'end') {
        $action_error = 'Unable to end the quiz. Please try again.';
    }
}

$questions_not_presented = max(0, $total_questions - $question_number);

$option_labels = [
    1 => 'A',
    2 => 'B',
    3 => 'C',
    4 => 'D',
];
?>

<?php
$page_title = 'Live Quiz';
$page_styles = ['assets/css/quiz.css?v=20261007-15'];
$layout_body_class = 'quiz-run-page';
$layout_show_navigation = false;
include 'includes/layout_start.php';
?>

<?php if ($error !== ''): ?>
    <main class="quiz-run-error-shell">
        <section class="quiz-run-error-card">
            <span class="quiz-run-kicker">Live quiz</span>
            <h1>Unable to show this session</h1>
            <div class="error-message">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <a href="quizzes.php" class="cancel-button">
                Back to Quiz Library
            </a>
        </section>
    </main>
<?php else: ?>
    <main class="quiz-run-screen">
        <header class="quiz-run-header">
            <div>
                <span class="quiz-run-kicker">Live quiz</span>
                <h1>
                    <?= htmlspecialchars(
                        $live_session['quiz_title'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </h1>
            </div>
            <div class="quiz-run-class">
                <span>Class</span>
                <strong>
                    <?= htmlspecialchars(
                        $live_session['class_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>
            </div>
        </header>

        <section class="quiz-run-question" aria-labelledby="current-question" data-sky-content>
            <?php if ($action_error !== ''): ?>
                <div class="error-message quiz-run-action-error">
                    <?= htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="quiz-run-progress">
                Question <?= $question_number ?> of <?= $total_questions ?>
            </div>

            <h2 id="current-question">
                <?= htmlspecialchars(
                    $current_question['content'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </h2>

            <div class="quiz-run-options">
                <?php foreach ($options as $option): ?>
                    <?php
                    $option_order = (int) $option['option_order'];
                    $option_label = $option_labels[$option_order]
                        ?? (string) $option_order;
                    ?>
                    <article class="quiz-run-option">
                        <span aria-hidden="true">
                            <?= htmlspecialchars(
                                $option_label,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </span>
                        <p>
                            <?= htmlspecialchars(
                                $option['content'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="lake-waterline" data-waterline-anchor></div>

        <footer class="quiz-run-footer" data-lantern-floor>
            <div class="quiz-run-response-status" aria-label="Response status">
                <div>
                    <span>Answered</span>
                    <strong id="answered-summary">
                        <?= $answered_count ?> / <?= $joined_count ?>
                    </strong>
                </div>
                <div>
                    <span>Waiting</span>
                    <strong id="waiting-count"><?= $waiting_count ?></strong>
                </div>
            </div>

            <div class="quiz-run-actions">
                <form
                    method="POST"
                    action="end_quiz.php"
                    class="quiz-run-end-form"
                    id="end-quiz-form"
                >
                    <input
                        type="hidden"
                        name="session_id"
                        value="<?= (int) $live_session['session_id'] ?>"
                    >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $end_quiz_csrf_token,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >
                    <button type="button" class="quiz-run-end-button" id="end-quiz-button">
                        <i class="ti ti-player-stop-filled" aria-hidden="true"></i>
                        End Quiz
                    </button>
                </form>

                <?php if ($is_last_question): ?>
                    <form
                        method="POST"
                        action="finish_quiz.php"
                        class="quiz-run-next-form"
                        onsubmit="return confirm('Finish this quiz? Students will no longer be able to submit answers.');"
                    >
                        <input
                            type="hidden"
                            name="session_id"
                            value="<?= (int) $live_session['session_id'] ?>"
                        >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $finish_quiz_csrf_token,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >
                        <button type="submit" class="quiz-run-next-button">
                            <i class="ti ti-flag-check" aria-hidden="true"></i>
                            Finish Quiz
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="next_question.php" class="quiz-run-next-form">
                        <input
                            type="hidden"
                            name="session_id"
                            value="<?= (int) $live_session['session_id'] ?>"
                        >
                        <input
                            type="hidden"
                            name="expected_question_id"
                            value="<?= (int) $current_question['question_id'] ?>"
                        >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $next_question_csrf_token,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >
                        <button type="submit" class="quiz-run-next-button">
                            Next Question
                            <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </footer>
    </main>

    <p
        class="quiz-run-polling-status"
        id="quiz-run-polling-status"
        role="status"
        aria-live="polite"
    ></p>

    <script>
    const quizRunSessionId = <?= (int) $live_session['session_id'] ?>;
    const quizRunQuestionId = <?= (int) $current_question['question_id'] ?>;
    const answeredSummary = document.getElementById('answered-summary');
    const waitingCount = document.getElementById('waiting-count');
    const pollingStatus = document.getElementById('quiz-run-polling-status');
    const endQuizForm = document.getElementById('end-quiz-form');
    const endQuizButton = document.getElementById('end-quiz-button');
    const quizQuestionNumber = <?= $question_number ?>;
    const quizTotalQuestions = <?= $total_questions ?>;
    const quizJoinedParticipants = <?= $joined_count ?>;
    const quizQuestionsNotPresented = <?= $questions_not_presented ?>;
    let pollingTimer = null;

    endQuizButton.addEventListener('click', async function () {
        const currentWaiting = Number.parseInt(waitingCount.textContent, 10) || 0;
        const result = await window.AppUI.confirm({
            title: 'End Quiz?',
            icon: 'warning',
            html: `
                <div class="end-quiz-confirmation">
                    <p>You are currently on <strong>Question ${quizQuestionNumber} of ${quizTotalQuestions}</strong>.</p>
                    <p><strong>${quizJoinedParticipants}</strong> participants joined this session.</p>
                    <p><strong>${currentWaiting}</strong> students have not answered this question.</p>
                    <p><strong>${quizQuestionsNotPresented}</strong> questions have not been presented.</p>
                    <p class="end-quiz-preserved">Existing responses will be preserved.</p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'End Quiz',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d63939',
            focusCancel: true,
            reverseButtons: true
        });

        if (result.isConfirmed) {
            endQuizButton.disabled = true;
            endQuizForm.submit();
        }
    });

    function scheduleQuestionStatusPoll(delay) {
        window.clearTimeout(pollingTimer);
        pollingTimer = window.setTimeout(pollQuestionStatus, delay);
    }

    async function pollQuestionStatus() {
        try {
            const response = await fetch(
                `api/question_status.php?session_id=${encodeURIComponent(quizRunSessionId)}`,
                {
                    headers: {Accept: 'application/json'},
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            );

            if (!response.ok) {
                throw new Error('Question status request failed');
            }

            const data = await response.json();

            if (data.question_id !== quizRunQuestionId) {
                window.location.reload();
                return;
            }

            answeredSummary.textContent = `${data.answered} / ${data.joined}`;
            waitingCount.textContent = data.waiting;
            pollingStatus.textContent = '';

            if (data.status === 'ACTIVE') {
                scheduleQuestionStatusPoll(1500);
            } else {
                pollingStatus.textContent = 'Live updates paused because this session is no longer active.';
            }
        } catch (error) {
            pollingStatus.textContent = 'Live counter temporarily unavailable. Retrying…';
            scheduleQuestionStatusPoll(2500);
        }
    }

    scheduleQuestionStatusPoll(1500);
    </script>
<?php endif; ?>

<?php include 'includes/layout_end.php'; ?>
