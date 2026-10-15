<?php
/*
 * Review: which questions a class misses most across the quizzes it has
 * taken, and a review quiz built from the ones the teacher picks.
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/review_data.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$error = '';

if (empty($_SESSION['review_csrf_token'])) {
    $_SESSION['review_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['review_csrf_token'];

$stmt = $conn->prepare('SELECT class_id, name FROM classes WHERE teacher_id = ? ORDER BY name');
$stmt->bind_param('i', $teacher_id);
$stmt->execute();
$classes = [];

foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $class_row) {
    $classes[(int) $class_row['class_id']] = $class_row;
}

$class_id = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);

if (!isset($classes[$class_id])) {
    $class_id = (int) (array_key_first($classes) ?? 0);
}

$min_attempts_options = [1, 3, 5, 10];
$min_attempts = (int) ($_GET['min'] ?? 3);

if (!in_array($min_attempts, $min_attempts_options, true)) {
    $min_attempts = 3;
}

/* ------------------------------------------------------------------
 * Create a review quiz from the chosen questions
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    $title = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['title'] ?? '')));
    $question_ids = is_array($_POST['question_ids'] ?? null) ? $_POST['question_ids'] : [];

    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        $error = 'Your form session expired. Please try again.';
    } elseif ($class_id <= 0) {
        $error = 'Choose a class.';
    } elseif (empty($question_ids)) {
        $error = 'Tick at least one question for the review quiz.';
    } elseif ($title === '' || iconv_strlen($title, 'UTF-8') > 255) {
        $error = 'Give the review quiz a title (up to 255 characters).';
    } elseif (count($question_ids) > 99) {
        $error = 'A quiz can hold at most 99 questions, the most the answer device can show.';
    } else {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $new_quiz_id = createReviewQuiz($conn, $teacher_id, $class_id, $title, $question_ids);
            header('Location: quiz.php?id=' . $new_quiz_id . '&review_created=1');
            exit;
        } catch (Throwable $exception) {
            error_log('Review quiz creation failed for teacher ' . $teacher_id . ': ' . $exception->getMessage());
            $error = 'The review quiz could not be created. Nothing was changed; please try again.';
        }
    }
}

/* ------------------------------------------------------------------
 * Statistics for the chosen quizzes
 * ---------------------------------------------------------------- */
$quizzes_taken = $class_id > 0 ? loadReviewQuizzes($conn, $teacher_id, $class_id) : [];
$taken_ids = array_map(static fn ($quiz) => (int) $quiz['quiz_id'], $quizzes_taken);
$chosen_quiz_ids = isset($_GET['quiz_ids']) && is_array($_GET['quiz_ids'])
    ? array_values(array_intersect($taken_ids, array_map('intval', $_GET['quiz_ids'])))
    : $taken_ids;

$stats = loadReviewStats($conn, $teacher_id, $class_id, $chosen_quiz_ids);
$shown = array_values(array_filter($stats['questions'], static fn ($q) => $q['attempts'] >= $min_attempts));
$hidden_count = count($stats['questions']) - count($shown);

$total_attempts = 0;
$total_correct = 0;
$mostly_missed = 0;

foreach ($shown as $question) {
    $total_attempts += $question['attempts'];
    $total_correct += $question['correct'];
    $mostly_missed += $question['missed_rate'] >= 0.5 ? 1 : 0;
}

$correct_share = $total_attempts > 0 ? (int) round($total_correct / $total_attempts * 100) : 0;
$class_name = $classes[$class_id]['name'] ?? '';
$default_title = 'Review: ' . $class_name . ' ' . date('d/m');

function reviewPercent(int $part, int $whole): int
{
    return $whole > 0 ? (int) round($part / $whole * 100) : 0;
}

function reviewPlural(int $count, string $one, string $many): string
{
    return $count . ' ' . ($count === 1 ? $one : $many);
}

$page_title = 'Review';
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page review-page">
    <header class="page-header management-page-header">
        <div class="page-header-main">
            <h1>Review</h1>
            <p>Find the questions your class gets wrong most often and turn them into a review quiz.</p>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <div class="error-message"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (empty($classes)): ?>
        <div class="empty-state">
            <h3>No classes yet</h3>
            <p>Create a class and run a quiz with it; its results will show up here.</p>
            <a href="add_class.php" class="add-button">Create Class</a>
        </div>
    <?php else: ?>
        <form method="GET" action="review.php" class="review-filters">
            <div class="form-group">
                <label for="review-class">Class</label>
                <select id="review-class" name="class_id" onchange="this.form.submit()">
                    <?php foreach ($classes as $class_option): ?>
                        <option value="<?= (int) $class_option['class_id'] ?>" <?= (int) $class_option['class_id'] === $class_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($class_option['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($quizzes_taken)): ?>
                <fieldset class="review-quiz-picker">
                    <legend>Quizzes taken</legend>
                    <?php foreach ($quizzes_taken as $quiz): ?>
                        <label class="review-chip">
                            <input
                                type="checkbox"
                                name="quiz_ids[]"
                                value="<?= (int) $quiz['quiz_id'] ?>"
                                <?= in_array((int) $quiz['quiz_id'], $chosen_quiz_ids, true) ? 'checked' : '' ?>
                            >
                            <span>
                                <?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?>
                                <small><?= reviewPlural((int) $quiz['session_count'], 'session', 'sessions') ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <div class="form-group review-min">
                    <label for="review-min">Count a question when at least</label>
                    <select id="review-min" name="min">
                        <?php foreach ($min_attempts_options as $option): ?>
                            <option value="<?= $option ?>" <?= $option === $min_attempts ? 'selected' : '' ?>>
                                <?= reviewPlural($option, 'student has', 'students have') ?> answered it
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="secondary-button">Update</button>
            <?php endif; ?>
        </form>

        <?php if (empty($quizzes_taken)): ?>
            <div class="empty-state">
                <h3><?= htmlspecialchars($class_name, ENT_QUOTES, 'UTF-8') ?> has not finished a quiz yet</h3>
                <p>Run a quiz with this class; once the session ends, its questions are counted here.</p>
                <a href="quizzes.php" class="add-button">Open Quiz Library</a>
            </div>
        <?php elseif (empty($chosen_quiz_ids)): ?>
            <div class="empty-state">
                <h3>No quiz chosen</h3>
                <p>Tick at least one quiz above and press Update.</p>
            </div>
        <?php elseif (empty($shown)): ?>
            <div class="empty-state">
                <h3>Not enough answers yet</h3>
                <p>
                    No question has been answered by <?= reviewPlural($min_attempts, 'student', 'students') ?> or more.
                    Lower the minimum above to see them.
                </p>
            </div>
        <?php else: ?>
            <p class="lake-summary review-summary">
                In <?= reviewPlural($stats['sessions'], 'session', 'sessions') ?>,
                <?= htmlspecialchars($class_name, ENT_QUOTES, 'UTF-8') ?> answered
                <mark><?= $correct_share ?>% correctly</mark>.
                <?php if ($mostly_missed > 0): ?>
                    <mark><?= reviewPlural($mostly_missed, 'question was', 'questions were') ?></mark>
                    missed by half the class or more.
                <?php else: ?>
                    No question was missed by half the class.
                <?php endif; ?>
            </p>

            <form method="POST" action="review.php?class_id=<?= $class_id ?>" class="review-form" id="review-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="class_id" value="<?= $class_id ?>">

                <div class="section-header">
                    <div>
                        <h2>Questions, most missed first</h2>
                        <p>
                            <?= reviewPlural(count($shown), 'question', 'questions') ?>; each bar shows every attempt at it.
                            <?php if ($hidden_count > 0): ?>
                                <?= reviewPlural($hidden_count, 'other question has', 'other questions have') ?>
                                too few answers to count.
                            <?php endif; ?>
                        </p>
                    </div>
                    <ul class="review-legend" aria-label="Legend">
                        <li><span class="review-swatch is-correct" aria-hidden="true"></span>Correct</li>
                        <li><span class="review-swatch is-wrong" aria-hidden="true"></span>Wrong</li>
                        <li><span class="review-swatch is-none" aria-hidden="true"></span>No answer</li>
                    </ul>
                </div>

                <ol class="review-list">
                    <?php foreach ($shown as $question): ?>
                        <?php
                        $attempts = $question['attempts'];
                        $missed = $question['wrong'] + $question['no_answer'];
                        $missed_percent = reviewPercent($missed, $attempts);
                        $segments = [
                            ['is-correct', 'Correct', $question['correct']],
                            ['is-wrong', 'Wrong', $question['wrong']],
                            ['is-none', 'No answer', $question['no_answer']]
                        ];
                        $correct_letter = $question['correct_letter'];
                        $top_wrong = $question['top_wrong'];
                        ?>
                        <li class="review-item" data-missed="<?= $missed_percent ?>">
                            <label class="review-pick">
                                <input
                                    type="checkbox"
                                    name="question_ids[]"
                                    value="<?= (int) $question['question_id'] ?>"
                                    <?= $missed_percent >= 50 ? 'checked' : '' ?>
                                >
                                <span class="visually-hidden">Add to the review quiz</span>
                            </label>

                            <div class="review-body">
                                <p class="review-source">
                                    <?= htmlspecialchars($question['quiz_title'], ENT_QUOTES, 'UTF-8') ?>,
                                    question <?= (int) $question['number'] ?>
                                </p>
                                <h3><?= htmlspecialchars($question['content'], ENT_QUOTES, 'UTF-8') ?></h3>

                                <div class="review-bar" role="img" aria-label="<?= htmlspecialchars(
                                    $question['correct'] . ' correct, ' . $question['wrong'] . ' wrong, '
                                    . $question['no_answer'] . ' no answer, of ' . $attempts,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>">
                                    <?php foreach ($segments as [$class_name_segment, $label, $count]): ?>
                                        <?php if ($count > 0): ?>
                                            <span
                                                class="review-segment <?= $class_name_segment ?>"
                                                style="flex-grow: <?= $count ?>"
                                                title="<?= htmlspecialchars($label . ': ' . $count . ' of ' . $attempts . ' (' . reviewPercent($count, $attempts) . '%)', ENT_QUOTES, 'UTF-8') ?>"
                                            ></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>

                                <p class="review-numbers">
                                    <strong><?= $missed_percent ?>% missed</strong>
                                    <span><?= $question['correct'] ?> correct</span>
                                    <span><?= $question['wrong'] ?> wrong</span>
                                    <span><?= $question['no_answer'] ?> no answer</span>
                                    <span>of <?= reviewPlural($attempts, 'attempt', 'attempts') ?></span>
                                </p>

                                <p class="review-answers">
                                    <?php if ($correct_letter !== null): ?>
                                        <span>
                                            Answer: <strong><?= $correct_letter ?></strong>
                                            <?= htmlspecialchars($question['options'][$correct_letter] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($top_wrong !== null): ?>
                                        <span class="review-trap">
                                            Most chosen wrong answer: <strong><?= htmlspecialchars($top_wrong, ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?= htmlspecialchars($question['options'][$top_wrong] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            (<?= reviewPlural((int) $question['wrong_choices'][$top_wrong], 'time', 'times') ?>)
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <div class="review-create">
                    <div class="review-create-pick">
                        <label for="review-threshold">Tick questions missed by at least</label>
                        <select id="review-threshold">
                            <?php foreach ([30, 40, 50, 60, 70] as $threshold): ?>
                                <option value="<?= $threshold ?>" <?= $threshold === 50 ? 'selected' : '' ?>><?= $threshold ?>%</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="review-create-title">
                        <label for="review-title">Review quiz title</label>
                        <input
                            type="text"
                            id="review-title"
                            name="title"
                            maxlength="255"
                            value="<?= htmlspecialchars($_POST['title'] ?? $default_title, ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <button type="submit" class="save-button" id="review-create-button">
                        Create review quiz
                    </button>
                </div>
            </form>

            <script>
            (function () {
                var form = document.getElementById('review-form');
                var threshold = document.getElementById('review-threshold');
                var button = document.getElementById('review-create-button');

                function count() {
                    var n = form.querySelectorAll('input[name="question_ids[]"]:checked').length;

                    button.textContent = n === 0
                        ? 'Tick questions to review'
                        : 'Create review quiz (' + n + (n === 1 ? ' question)' : ' questions)');
                    button.disabled = n === 0;
                }

                threshold.addEventListener('change', function () {
                    var limit = parseInt(threshold.value, 10);

                    form.querySelectorAll('.review-item').forEach(function (item) {
                        item.querySelector('input[type="checkbox"]').checked =
                            parseInt(item.dataset.missed, 10) >= limit;
                    });
                    count();
                });

                form.addEventListener('change', count);
                count();
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include 'includes/layout_end.php'; ?>
