<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/results_data.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$session_id = (int) ($_GET['session_id'] ?? 0);
$question_id = (int) ($_GET['question_id'] ?? 0);
$error = '';
$result_data = null;
$question = null;

if ($session_id <= 0 || $question_id <= 0) {
    http_response_code(400);
    $error = 'A valid session and question are required.';
} else {
    try {
        $result_data = loadSessionResultData($conn, $teacher_id, $session_id);

        foreach ($result_data['questions'] as $candidate) {
            if ((int) $candidate['question_id'] === $question_id) {
                $question = $candidate;
                break;
            }
        }

        if ($question === null) {
            http_response_code(404);
            $error = 'Question not found for this quiz session.';
        } elseif (!$question['presented']) {
            http_response_code(409);
            $error = 'This question was not presented during the session.';
            $question = null;
        }
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
            'Question result failed for teacher '
            . $teacher_id
            . ': '
            . $exception->getMessage()
        );
        http_response_code(500);
        $error = 'Unable to load question results.';
    }
}

$page_title = 'Question Results';
$layout_body_class = 'results-page';
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page question-result-page">
    <?php if ($error !== ''): ?>
        <section class="results-error-card">
            <span class="results-title-icon" aria-hidden="true"><i class="ti ti-alert-circle"></i></span>
            <h1>Unable to show question results</h1>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php if ($session_id > 0): ?>
                <a href="results.php?session_id=<?= $session_id ?>" class="secondary-button">Back to Results</a>
            <?php else: ?>
                <a href="results.php" class="secondary-button">Back to Results</a>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php
        $overview = $result_data['overview'];
        $participant_count = (int) $overview['participant_count'];
        $correct_percent = resultPercent($question['correct_count'], $participant_count);
        $wrong_percent = resultPercent($question['wrong_count'], $participant_count);
        $unanswered_percent = resultPercent($question['unanswered_count'], $participant_count);
        ?>

        <a href="results.php?session_id=<?= $session_id ?>" class="results-back-link">
            <i class="ti ti-arrow-left" aria-hidden="true"></i>
            Back to Results
        </a>

        <header class="question-result-header">
            <span class="question-counter">Question <?= (int) $question['number'] ?> of <?= (int) $overview['total_question_count'] ?></span>
            <h1><?= htmlspecialchars($question['content'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p>
                <?= htmlspecialchars($result_data['quiz']['title'], ENT_QUOTES, 'UTF-8') ?>
                · <?= htmlspecialchars($result_data['class']['name'], ENT_QUOTES, 'UTF-8') ?>
            </p>
        </header>

        <section class="question-result-options" aria-label="Answer options">
            <?php foreach ($question['options'] as $option): ?>
                <article class="question-result-option <?= $option['is_correct'] ? 'correct-option' : '' ?>">
                    <span class="question-option-label"><?= htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <p><?= htmlspecialchars($option['content'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if ($option['is_correct']): ?>
                        <strong><i class="ti ti-circle-check-filled"></i>Correct</strong>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="question-result-summary" aria-label="Question response summary">
            <article class="summary-correct"><span>Correct</span><strong><?= (int) $question['correct_count'] ?></strong><small><?= $correct_percent ?>%</small></article>
            <article class="summary-wrong"><span>Wrong</span><strong><?= (int) $question['wrong_count'] ?></strong><small><?= $wrong_percent ?>%</small></article>
            <article class="summary-unanswered"><span>Unanswered</span><strong><?= (int) $question['unanswered_count'] ?></strong><small><?= $unanswered_percent ?>%</small></article>
        </section>

        <section class="answer-distribution-card" aria-labelledby="answer-distribution-title">
            <div class="answer-distribution-heading">
                <div><span class="section-kicker">Question analysis</span><h2 id="answer-distribution-title">Answer Distribution</h2></div>
                <span><?= $participant_count ?> participants</span>
            </div>

            <div class="answer-distribution-list">
                <?php foreach ($question['options'] as $option): ?>
                    <div class="answer-distribution-row <?= $option['is_correct'] ? 'correct-distribution' : '' ?>">
                        <span class="distribution-label"><?= htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <div class="distribution-answer">
                            <strong><?= htmlspecialchars($option['content'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="distribution-track"><span style="width: <?= (int) $option['response_percent'] ?>%"></span></div>
                        </div>
                        <span class="distribution-count"><?= (int) $option['response_count'] ?></span>
                        <span class="distribution-percent"><?= (int) $option['response_percent'] ?>%</span>
                        <?php if ($option['is_correct']): ?><span class="correct-answer-badge"><i class="ti ti-check"></i>Correct</span><?php else: ?><span></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ((int) $question['invalid_response_count'] > 0): ?>
                    <div class="answer-distribution-row invalid-distribution">
                        <span class="distribution-label">?</span>
                        <div class="distribution-answer"><strong>Invalid response value</strong><div class="distribution-track"><span style="width: <?= resultPercent($question['invalid_response_count'], $participant_count) ?>%"></span></div></div>
                        <span class="distribution-count"><?= (int) $question['invalid_response_count'] ?></span>
                        <span class="distribution-percent"><?= resultPercent($question['invalid_response_count'], $participant_count) ?>%</span>
                        <span></span>
                    </div>
                <?php endif; ?>

                <div class="answer-distribution-row unanswered-distribution">
                    <span class="distribution-label"><i class="ti ti-minus"></i></span>
                    <div class="distribution-answer"><strong>Unanswered</strong><div class="distribution-track"><span style="width: <?= $unanswered_percent ?>%"></span></div></div>
                    <span class="distribution-count"><?= (int) $question['unanswered_count'] ?></span>
                    <span class="distribution-percent"><?= $unanswered_percent ?>%</span>
                    <span></span>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php include 'includes/layout_end.php'; ?>
