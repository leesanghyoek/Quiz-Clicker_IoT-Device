<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/results_data.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$session_id = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
$error = '';
$result_data = null;
$history = [];

function resultDifficultyClass(?int $wrongRate): string
{
    if ($wrongRate === null) {
        return 'difficulty-not-presented';
    }

    return match (true) {
        $wrongRate <= 20 => 'difficulty-very-low',
        $wrongRate <= 40 => 'difficulty-low',
        $wrongRate <= 60 => 'difficulty-medium',
        $wrongRate <= 80 => 'difficulty-high',
        default => 'difficulty-very-high',
    };
}

function resultDateLabel(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return 'Date unavailable';
    }

    try {
        return (new DateTime($date))->format('d M Y, H:i');
    } catch (Throwable) {
        return 'Date unavailable';
    }
}

if ($session_id > 0) {
    try {
        $result_data = loadSessionResultData($conn, $teacher_id, $session_id);
    } catch (UnexpectedValueException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (DomainException $exception) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(403);
        $error = 'Session not found or access denied.';
    } catch (Throwable $exception) {
        error_log(
            'Results page failed for teacher '
            . $teacher_id
            . ': '
            . $exception->getMessage()
        );
        http_response_code(500);
        $error = 'Unable to load the session results.';
    }
} else {
    try {
        $history = loadTeacherResultHistory($conn, $teacher_id);
    } catch (Throwable $exception) {
        error_log(
            'Results history failed for teacher '
            . $teacher_id
            . ': '
            . $exception->getMessage()
        );
        http_response_code(500);
        $error = 'Unable to load results history.';
    }
}

$page_title = $session_id > 0 ? 'Quiz Results' : 'Results';
$layout_body_class = 'results-page';
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page results-management-page">
    <?php if ($error !== ''): ?>
        <section class="results-error-card">
            <span class="results-title-icon" aria-hidden="true"><i class="ti ti-alert-circle"></i></span>
            <h1>Unable to show results</h1>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <a href="results.php" class="secondary-button">Back to Results</a>
        </section>
    <?php elseif ($result_data !== null): ?>
        <?php
        $session = $result_data['session'];
        $quiz = $result_data['quiz'];
        $class = $result_data['class'];
        $overview = $result_data['overview'];
        $questions = $result_data['questions'];
        $participants = $result_data['participants'];
        $session_date = $session['ended_at'] ?? $session['started_at'];
        ?>

        <header class="results-detail-header">
            <div class="results-detail-heading">
                <h1><?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="results-class-name">
                    <i class="ti ti-school" aria-hidden="true"></i>
                    <?= htmlspecialchars($class['name'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div class="results-header-meta">
                    <span><i class="ti ti-calendar-time"></i><?= htmlspecialchars(resultDateLabel($session_date), ENT_QUOTES, 'UTF-8') ?></span>
                    <span><i class="ti ti-users"></i><?= (int) $overview['participant_count'] ?> Participants</span>
                    <span><i class="ti ti-list-check"></i><?= (int) $overview['presented_question_count'] ?> / <?= (int) $overview['total_question_count'] ?> Questions Presented</span>
                </div>
            </div>
            <div class="results-header-actions">
                <a href="results.php" class="secondary-button"><i class="ti ti-history"></i>Results History</a>
                <a href="class.php?id=<?= (int) $class['class_id'] ?>&tab=results" class="secondary-button"><i class="ti ti-school"></i>Open Class</a>
            </div>
        </header>

        <section class="results-overview-grid" aria-label="Results overview">
            <article class="results-stat-card">
                <span class="results-stat-icon"><i class="ti ti-percentage"></i></span>
                <div><span>Average Score</span><strong><?= (int) $overview['average_score'] ?>%</strong></div>
            </article>
            <article class="results-stat-card">
                <span class="results-stat-icon"><i class="ti ti-users"></i></span>
                <div><span>Participants</span><strong><?= (int) $overview['participant_count'] ?> / <?= (int) $overview['class_student_count'] ?></strong></div>
            </article>
            <article class="results-stat-card">
                <span class="results-stat-icon"><i class="ti ti-progress-check"></i></span>
                <div><span>Completion</span><strong><?= (int) $overview['completion'] ?>%</strong></div>
            </article>
            <article class="results-stat-card">
                <span class="results-stat-icon"><i class="ti ti-target-arrow"></i></span>
                <div><span>Accuracy</span><strong><?= (int) $overview['accuracy'] ?>%</strong></div>
            </article>
        </section>

        <section class="results-matrix-card" aria-labelledby="performance-matrix-title">
            <div class="results-matrix-heading">
                <div>
                    <span class="section-kicker">Session analysis</span>
                    <h2 id="performance-matrix-title">Performance Matrix</h2>
                    <p>Correctness by participant and question.</p>
                </div>
                <?php if (!empty($participants)): ?>
                    <button type="button" class="secondary-button" id="toggle-scores-button" aria-pressed="false">
                        <i class="ti ti-eye-off" aria-hidden="true"></i>
                        <span>Hide Scores</span>
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($participants)): ?>
                <div class="results-matrix-scroll" id="results-matrix-scroll">
                    <table class="results-matrix" id="results-matrix">
                        <thead>
                            <tr>
                                <th class="matrix-student-column" scope="col">Student</th>
                                <?php foreach ($questions as $question): ?>
                                    <?php if ($question['presented']): ?>
                                        <th
                                            class="matrix-question-header <?= resultDifficultyClass($question['wrong_rate']) ?>"
                                            scope="col"
                                        >
                                            <a
                                                href="result_question.php?session_id=<?= (int) $session['session_id'] ?>&question_id=<?= (int) $question['question_id'] ?>"
                                                aria-label="Open details for Question <?= (int) $question['number'] ?>"
                                            >
                                                <strong>Q<?= (int) $question['number'] ?></strong>
                                                <span><?= (int) $question['wrong_rate'] ?>%</span>
                                            </a>
                                        </th>
                                    <?php else: ?>
                                        <th class="matrix-question-header difficulty-not-presented" scope="col">
                                            <strong>Q<?= (int) $question['number'] ?></strong>
                                            <span>Not presented</span>
                                        </th>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <th class="matrix-score-column score-column" scope="col">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($participants as $participant): ?>
                                <tr>
                                    <th class="matrix-student-column" scope="row">
                                        <strong><?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars($participant['student_id'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </th>
                                    <?php foreach ($questions as $question): ?>
                                        <?php $answer = $participant['answers'][$question['question_id']]; ?>
                                        <td
                                            class="matrix-answer-cell state-<?= htmlspecialchars($answer['state'], ENT_QUOTES, 'UTF-8') ?>"
                                            aria-label="Question <?= (int) $question['number'] ?>: <?= str_replace('_', ' ', htmlspecialchars($answer['state'], ENT_QUOTES, 'UTF-8')) ?>"
                                        >
                                            <?php if ($answer['state'] === 'correct'): ?>
                                                <span aria-hidden="true">✓</span>
                                            <?php elseif ($answer['state'] === 'wrong'): ?>
                                                <span aria-hidden="true">✕</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="matrix-score-column score-column">
                                        <strong><?= (int) $participant['score_percent'] ?>%</strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="results-empty-matrix">
                    <i class="ti ti-users-off" aria-hidden="true"></i>
                    <h3>No participants joined this session</h3>
                    <p>The statistics remain at 0%, and no student result rows are available.</p>
                </div>
            <?php endif; ?>

            <div class="results-legend" aria-label="Performance matrix legend">
                <div class="results-state-legend">
                    <span><i class="legend-swatch state-correct">✓</i>Correct</span>
                    <span><i class="legend-swatch state-wrong">✕</i>Wrong</span>
                    <span><i class="legend-swatch state-unanswered"></i>Gray cell = No response</span>
                    <?php if ($overview['presented_question_count'] < $overview['total_question_count']): ?>
                        <span><i class="legend-swatch state-not_presented"></i>Not presented</span>
                    <?php endif; ?>
                </div>
                <p><strong>Question difficulty:</strong> Green = fewer incorrect/missed · Red = more incorrect/missed</p>
            </div>
        </section>

        <script>
        const scoreToggle = document.getElementById('toggle-scores-button');
        const resultsMatrix = document.getElementById('results-matrix');

        if (scoreToggle && resultsMatrix) {
            scoreToggle.addEventListener('click', function () {
                const hidden = resultsMatrix.classList.toggle('scores-hidden');
                const label = scoreToggle.querySelector('span');
                const icon = scoreToggle.querySelector('i');

                scoreToggle.setAttribute('aria-pressed', hidden ? 'true' : 'false');
                label.textContent = hidden ? 'Show Scores' : 'Hide Scores';
                icon.className = hidden ? 'ti ti-eye' : 'ti ti-eye-off';
            });
        }
        </script>
    <?php else: ?>
        <header class="page-header management-page-header">
            <div class="page-header-main">
                <h1>Results</h1>
                <p>Review completed classroom quiz sessions.</p>
            </div>
        </header>

        <?php if (!empty($history)): ?>
            <section class="results-history-card">
                <div class="results-history-heading">
                    <div><h2>Completed Sessions</h2><p><?= count($history) ?> result sessions</p></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter results-history-table">
                        <thead><tr><th>Quiz</th><th>Class</th><th>Date</th><th>Participants</th><th>Average Score</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($history as $result): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($result['quiz_title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td><?= htmlspecialchars($result['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(resultDateLabel($result['ended_at'] ?? $result['started_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $result['participant_count'] ?> / <?= (int) $result['class_student_count'] ?></td>
                                    <td><?= $result['average_score'] !== null ? (int) $result['average_score'] . '%' : 'Unavailable' ?></td>
                                    <td class="text-end"><a href="results.php?session_id=<?= (int) $result['session_id'] ?>" class="secondary-button">View Results<i class="ti ti-arrow-right"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <section class="empty-state results-history-empty">
                <i class="ti ti-chart-bar-off" aria-hidden="true"></i>
                <h2>No completed sessions yet</h2>
                <p>Results will appear here after a quiz session ends.</p>
                <a href="quizzes.php" class="secondary-button">Open Quiz Library</a>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include 'includes/layout_end.php'; ?>
