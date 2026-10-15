<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/quiz_lock.php';

$teacher_id = $_SESSION['teacher_id'];
$quiz_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($quiz_id <= 0) {
    die("Invalid quiz ID.");
}

$stmt = $conn->prepare("
    SELECT quiz_id, title
    FROM quizzes
    WHERE quiz_id = ?
      AND teacher_id = ?
");
$stmt->bind_param("ii", $quiz_id, $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Quiz not found or access denied.");
}

$quiz = $result->fetch_assoc();
$quiz_state = loadOwnedQuizEditState($conn, $quiz_id, (int) $teacher_id);

if ($quiz_state === null) {
    die("Quiz not found or access denied.");
}

$quiz_locked = (bool) $quiz_state['is_locked'];

$count_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM questions
    WHERE quiz_id = ?
");
$count_stmt->bind_param("i", $quiz_id);
$count_stmt->execute();
$total_questions = (int) $count_stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("
    SELECT
        q.question_id,
        q.question_order,
        q.content AS question_content,
        qo.option_id,
        qo.option_order,
        qo.content AS option_content,
        qo.is_correct
    FROM questions q
    LEFT JOIN question_options qo
        ON qo.question_id = q.question_id
    WHERE q.quiz_id = ?
    ORDER BY q.question_order, qo.option_order
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$question_rows = $stmt->get_result();

$questions = [];

while ($row = $question_rows->fetch_assoc()) {
    $question_id = (int) $row['question_id'];

    if (!isset($questions[$question_id])) {
        $questions[$question_id] = [
            'question_id' => $question_id,
            'question_order' => (int) $row['question_order'],
            'content' => $row['question_content'],
            'options' => []
        ];
    }

    if ($row['option_id'] !== null) {
        $questions[$question_id]['options'][] = [
            'option_id' => (int) $row['option_id'],
            'option_order' => (int) $row['option_order'],
            'content' => $row['option_content'],
            'is_correct' => (int) $row['is_correct'] === 1
        ];
    }
}

$stmt = $conn->prepare("
    SELECT c.class_id, c.name
    FROM class_quizzes cq
    JOIN classes c
        ON cq.class_id = c.class_id
    WHERE cq.quiz_id = ?
      AND c.teacher_id = ?
    ORDER BY c.name
");
$stmt->bind_param("ii", $quiz_id, $teacher_id);
$stmt->execute();
$classes_result = $stmt->get_result();

$assigned_classes = [];

while ($class = $classes_result->fetch_assoc()) {
    $assigned_classes[] = $class['name'];
}

if (empty($_SESSION['reorder_questions_csrf_token'])) {
    $_SESSION['reorder_questions_csrf_token'] = bin2hex(random_bytes(32));
}

$reorder_questions_csrf_token = $_SESSION['reorder_questions_csrf_token'];

if (empty($_SESSION['quiz_builder_csrf_token'])) {
    $_SESSION['quiz_builder_csrf_token'] = bin2hex(random_bytes(32));
}

$quiz_builder_csrf_token = $_SESSION['quiz_builder_csrf_token'];
$quiz_builder_error = (string) ($_SESSION['quiz_builder_error'] ?? '');
unset($_SESSION['quiz_builder_error']);
?>

<?php
$page_title = (string) $quiz['title'];
$page_styles = ['assets/css/quiz.css?v=20261008-1'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<main class="main-content management-page">

    <header class="page-header management-page-header quiz-header quiz-header-with-action">
        <div class="quiz-heading-content page-header-main">
            <h1><?= htmlspecialchars($quiz['title']) ?></h1>

            <p class="quiz-meta">
                <span>
                    <?= $total_questions ?>
                    <?= $total_questions === 1
                        ? 'Question'
                        : 'Questions'
                    ?>
                </span>

                <span aria-hidden="true">•</span>

                <span>
                    <?php if (!empty($assigned_classes)): ?>
                        Classes:
                        <?= htmlspecialchars(
                            implode(', ', $assigned_classes)
                        ) ?>
                    <?php else: ?>
                        No classes assigned
                    <?php endif; ?>
                </span>
            </p>
        </div>

        <div class="quiz-page-actions page-header-actions">
            <a
                href="launch_quiz.php?quiz_id=<?= $quiz_id ?>"
                class="save-button"
            >
                <i class="ti ti-player-play" aria-hidden="true"></i>
                Launch Quiz
            </a>

            <a
                href="assign_quiz.php?quiz_id=<?= $quiz_id ?>"
                class="secondary-button"
            >
                <i class="ti ti-school-plus" aria-hidden="true"></i>
                Assign Class
            </a>

            <?php if (!$quiz_locked): ?>
                <div class="quiz-page-menu-wrapper">
                    <button
                        type="button"
                        class="quiz-page-menu-button"
                        onclick="toggleQuizPageMenu()"
                        aria-label="Quiz actions"
                    >
                        <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                    </button>

                    <div
                        class="quiz-page-menu"
                        id="quiz-page-menu"
                    >
                        <button
                            type="button"
                            onclick="openDeleteQuizDialog()"
                        >
                            <i class="ti ti-trash" aria-hidden="true"></i>
                            Delete Quiz
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($quiz_locked): ?>
        <div class="quiz-lock-notice" role="status">
            <i class="ti ti-lock" aria-hidden="true"></i>
            <div>
                <strong>Locked</strong>
                <span>This quiz has already been used in a live session. Its title, questions, answers, order, and deletion are now protected.</span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['assigned'])): ?>
        <div class="success-message">
            Class assignments updated successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['review_created'])): ?>
        <div class="success-message">
            Review quiz created and assigned to the class. Check the questions, then launch it.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['ai_created'])): ?>
        <div class="success-message">
            Quiz saved from the AI draft. Assign it to a class if you have not yet, then launch it.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['added'])): ?>
        <div class="success-message">Question added successfully.</div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])): ?>
        <div class="success-message">Question updated successfully.</div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="success-message">Question deleted successfully.</div>
    <?php endif; ?>

    <?php if (($_GET['error'] ?? '') === 'delete'): ?>
        <div class="error-message">Failed to delete question.</div>
    <?php endif; ?>

    <?php if (($_GET['error'] ?? '') === 'locked' && !$quiz_locked): ?>
        <div class="error-message">This quiz is locked because it has already been used in a live session.</div>
    <?php endif; ?>

    <?php if (isset($_GET['saved'])): ?>
        <div class="success-message">Quiz changes saved successfully.</div>
    <?php endif; ?>

    <?php if ($quiz_builder_error !== ''): ?>
        <div class="error-message"><?= htmlspecialchars($quiz_builder_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" action="save_quiz.php" id="quiz-builder-form" class="quiz-builder">
        <input type="hidden" name="quiz_id" value="<?= $quiz_id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($quiz_builder_csrf_token, ENT_QUOTES, 'UTF-8') ?>">

        <fieldset class="quiz-builder-lockable" <?= $quiz_locked ? 'disabled' : '' ?>>

        <section class="quiz-builder-settings" aria-labelledby="quiz-details-heading">
            <div>
                <span class="section-kicker">Quiz details</span>
                <h2 id="quiz-details-heading">Builder</h2>
                <p>Edit the quiz title, questions and answers from this page.</p>
            </div>

            <div class="quiz-builder-title-field">
                <label for="quiz-title">Quiz title</label>
                <input
                    type="text"
                    id="quiz-title"
                    name="title"
                    class="form-control"
                    value="<?= htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8') ?>"
                    maxlength="255"
                    required
                >
            </div>
        </section>

        <section class="quiz-builder-questions" aria-labelledby="quiz-builder-questions-heading">
            <div class="section-header quiz-builder-section-header">
                <div>
                    <span class="section-kicker">Quiz content</span>
                    <h2 id="quiz-builder-questions-heading">Questions</h2>
                    <p><span id="builder-question-count"><?= $total_questions ?></span> questions · Drag the handle to reorder</p>
                </div>

                <?php if (!$quiz_locked): ?>
                    <button type="button" class="add-button" id="add-builder-question">
                        <i class="ti ti-plus" aria-hidden="true"></i>
                        Add Question
                    </button>
                <?php endif; ?>
            </div>

            <div class="quiz-builder-list" id="quiz-builder-list">
                <?php foreach (array_values($questions) as $question_index => $question): ?>
                    <?php
                    $display_options = $question['options'];

                    while (count($display_options) < 2) {
                        $display_options[] = [
                            'option_id' => 0,
                            'option_order' => count($display_options) + 1,
                            'content' => '',
                            'is_correct' => count($display_options) === 0
                        ];
                    }

                    $has_correct_option = false;
                    foreach ($display_options as $display_option) {
                        if ($display_option['is_correct']) {
                            $has_correct_option = true;
                            break;
                        }
                    }
                    ?>
                    <article
                        class="quiz-builder-question"
                        data-question-id="<?= (int) $question['question_id'] ?>"
                    >
                        <input
                            type="hidden"
                            data-field="question-id"
                            name="questions[<?= $question_index ?>][question_id]"
                            value="<?= (int) $question['question_id'] ?>"
                        >

                        <header class="quiz-builder-question-header">
                            <div class="quiz-builder-question-identity">
                                <?php if (!$quiz_locked): ?>
                                    <button type="button" class="question-drag-handle" aria-label="Drag to reorder question">
                                        <i class="ti ti-grip-vertical" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                                <span class="question-number-badge">Question <strong data-question-number><?= $question_index + 1 ?></strong></span>
                            </div>

                            <?php if (!$quiz_locked): ?>
                                <button
                                    type="button"
                                    class="quiz-builder-delete-question"
                                    data-delete-question
                                    data-question-id="<?= (int) $question['question_id'] ?>"
                                >
                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                    Delete Question
                                </button>
                            <?php endif; ?>
                        </header>

                        <div class="quiz-builder-question-body">
                            <div class="form-group quiz-builder-content-field">
                                <label>Question content</label>
                                <textarea
                                    class="form-control"
                                    data-field="question-content"
                                    name="questions[<?= $question_index ?>][content]"
                                    rows="3"
                                    required
                                ><?= htmlspecialchars($question['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>

                            <div class="quiz-builder-options" data-options-list>
                                <div class="quiz-builder-options-heading">
                                    <div><h3>Answer options</h3><p>Select the one correct answer.</p></div>
                                    <span>2–4 options</span>
                                </div>

                                <?php foreach (array_values($display_options) as $option_index => $option): ?>
                                    <div class="quiz-builder-option" data-option>
                                        <label class="correct-answer-control" title="Mark this option as correct">
                                            <input
                                                type="radio"
                                                data-field="correct-option"
                                                name="questions[<?= $question_index ?>][correct_option]"
                                                value="<?= $option_index ?>"
                                                <?= $option['is_correct'] || (!$has_correct_option && $option_index === 0) ? 'checked' : '' ?>
                                                required
                                            >
                                            <span data-option-letter><?= chr(65 + $option_index) ?></span>
                                        </label>

                                        <input
                                            type="hidden"
                                            data-field="option-id"
                                            name="questions[<?= $question_index ?>][options][<?= $option_index ?>][option_id]"
                                            value="<?= (int) $option['option_id'] ?>"
                                        >
                                        <input
                                            type="text"
                                            class="form-control"
                                            data-field="option-content"
                                            name="questions[<?= $question_index ?>][options][<?= $option_index ?>][content]"
                                            value="<?= htmlspecialchars($option['content'], ENT_QUOTES, 'UTF-8') ?>"
                                            placeholder="Answer <?= chr(65 + $option_index) ?>"
                                            required
                                        >

                                        <button type="button" class="quiz-builder-remove-option" data-remove-option aria-label="Remove answer option">
                                            <i class="ti ti-x" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="button" class="quiz-builder-add-option" data-add-option>
                                <i class="ti ti-plus" aria-hidden="true"></i>
                                Add option
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="quiz-builder-empty<?= !empty($questions) ? ' is-hidden' : '' ?>" id="quiz-builder-empty">
                <span class="card-icon" aria-hidden="true"><i class="ti ti-help"></i></span>
                <h3>No questions yet</h3>
                <p>Add a question to start building this quiz.</p>
            </div>
        </section>

        <footer class="quiz-builder-footer">
            <a href="quizzes.php" class="cancel-button">
                <i class="ti ti-arrow-left" aria-hidden="true"></i>
                Cancel / Back
            </a>
            <?php if (!$quiz_locked): ?>
                <button type="submit" class="save-button">
                    <i class="ti ti-device-floppy" aria-hidden="true"></i>
                    Save Changes
                </button>
            <?php endif; ?>
        </footer>
        </fieldset>
    </form>

    <?php if (false): ?>
        <section class="content-section">
        <div class="section-header">
            <div>
                <h2>Questions</h2>
                <p><?= $total_questions ?> <?= $total_questions === 1 ? 'question' : 'questions' ?> in this quiz</p>
            </div>

            <div class="question-header-actions">
                <a
                    href="add_question.php?quiz_id=<?= $quiz_id ?>"
                    class="add-button"
                >
                    <i class="ti ti-plus" aria-hidden="true"></i>
                    Add Question
                </a>

                <label class="answer-visibility-toggle">
                    <input
                        type="checkbox"
                        id="answer-visibility-toggle"
                    >

                    <span class="toggle-track" aria-hidden="true">
                        <span class="toggle-thumb"></span>
                    </span>

                    <span>Show answers</span>
                </label>
            </div>
        </div>

        <?php if (!empty($questions)): ?>

            <div
                class="question-list"
                id="question-list"
            >

                <?php foreach ($questions as $question): ?>

                    <div
                        class="question-card management-card"
                        data-id="<?= $question['question_id'] ?>"
                    >

                        <button
                            type="button"
                            class="question-menu-button"
                            onclick="toggleQuestionMenu(<?= $question['question_id'] ?>)"
                            aria-label="Question actions"
                        >
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>

                        <div
                            class="question-menu"
                            id="question-menu-<?= $question['question_id'] ?>"
                        >
                            <a href="edit_question.php?id=<?= $question['question_id'] ?>">
                                <i class="ti ti-pencil" aria-hidden="true"></i>
                                Edit
                            </a>

                            <form
                                method="POST"
                                action="delete_question.php"
                                onsubmit="return confirm('Are you sure you want to delete this question?');"
                            >
                                <input
                                    type="hidden"
                                    name="question_id"
                                    value="<?= $question['question_id'] ?>"
                                >

                                <button type="submit"><i class="ti ti-trash" aria-hidden="true"></i>Delete</button>
                            </form>
                        </div>

                        <h3 class="question-order-label">
                            Question <?= $question['question_order'] ?>
                        </h3>

                        <p class="question-content">
                            <?= htmlspecialchars($question['content']) ?>
                        </p>

                        <div class="options">

                            <?php foreach ($question['options'] as $option): ?>
                                <?php
                                $option_label = chr(
                                    64 + $option['option_order']
                                );
                                ?>

                                <p>
                                    <strong>
                                        <?= htmlspecialchars($option_label) ?>.
                                    </strong>

                                    <?= htmlspecialchars($option['content']) ?>

                                    <?php if ($option['is_correct']): ?>
                                        <span class="option-correct-mark">
                                            Correct
                                        </span>
                                    <?php endif; ?>
                                </p>
                            <?php endforeach; ?>

                        </div>

                        <?php if (empty($question['options'])): ?>
                            <p class="error-message">
                                This question has no answer options.
                            </p>
                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">
                <h3>No questions yet</h3>
                <p>Add your first question to this quiz.</p>

                <a
                    href="add_question.php?quiz_id=<?= $quiz_id ?>"
                    class="add-button"
                >
                    Add Question
                </a>
            </div>

        <?php endif; ?>

        </section>
    <?php endif; ?>

</main>

<?php if (!$quiz_locked): ?>
<dialog class="confirmation-dialog" id="delete-quiz-dialog">
    <div class="confirmation-dialog-icon" aria-hidden="true">!</div>

    <h2>Delete this quiz?</h2>

    <p>
        If you delete this quiz, all questions, answers,
        live sessions, responses, class assignments, and
        related information will be permanently deleted.
    </p>

    <div class="confirmation-dialog-actions">
        <button
            type="button"
            class="cancel-button"
            onclick="closeDeleteQuizDialog()"
        >
            Cancel
        </button>

        <form method="POST" action="delete_quiz.php">
            <input
                type="hidden"
                name="quiz_id"
                value="<?= $quiz_id ?>"
            >

            <button type="submit" class="danger-button">
                Yes, Delete Quiz
            </button>
        </form>
    </div>
</dialog>

<script>
function toggleQuizPageMenu() {
    document
        .getElementById('quiz-page-menu')
        .classList
        .toggle('show');
}

function openDeleteQuizDialog() {
    document.getElementById('quiz-page-menu').classList.remove(
        'show'
    );
    document.getElementById('delete-quiz-dialog').showModal();
}

function closeDeleteQuizDialog() {
    document.getElementById('delete-quiz-dialog').close();
}

document.addEventListener('DOMContentLoaded', function () {
const quizBuilderForm = document.getElementById('quiz-builder-form');
const quizBuilderList = document.getElementById('quiz-builder-list');
const quizBuilderEmpty = document.getElementById('quiz-builder-empty');
const builderQuestionCount = document.getElementById('builder-question-count');

function createOptionElement() {
    const option = document.createElement('div');
    option.className = 'quiz-builder-option';
    option.dataset.option = '';
    option.innerHTML = `
        <label class="correct-answer-control" title="Mark this option as correct">
            <input type="radio" data-field="correct-option" required>
            <span data-option-letter></span>
        </label>
        <input type="hidden" data-field="option-id" value="0">
        <input type="text" class="form-control" data-field="option-content" required>
        <button type="button" class="quiz-builder-remove-option" data-remove-option aria-label="Remove answer option">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    `;
    return option;
}

function createQuestionElement() {
    const question = document.createElement('article');
    question.className = 'quiz-builder-question';
    question.dataset.questionId = '0';
    question.innerHTML = `
        <input type="hidden" data-field="question-id" value="0">
        <header class="quiz-builder-question-header">
            <div class="quiz-builder-question-identity">
                <button type="button" class="question-drag-handle" aria-label="Drag to reorder question">
                    <i class="ti ti-grip-vertical" aria-hidden="true"></i>
                </button>
                <span class="question-number-badge">Question <strong data-question-number></strong></span>
            </div>
            <button type="button" class="quiz-builder-delete-question" data-delete-question data-question-id="0">
                <i class="ti ti-trash" aria-hidden="true"></i>
                Delete Question
            </button>
        </header>
        <div class="quiz-builder-question-body">
            <div class="form-group quiz-builder-content-field">
                <label>Question content</label>
                <textarea class="form-control" data-field="question-content" rows="3" required></textarea>
            </div>
            <div class="quiz-builder-options" data-options-list>
                <div class="quiz-builder-options-heading">
                    <div><h3>Answer options</h3><p>Select the one correct answer.</p></div>
                    <span>2–4 options</span>
                </div>
            </div>
            <button type="button" class="quiz-builder-add-option" data-add-option>
                <i class="ti ti-plus" aria-hidden="true"></i>
                Add option
            </button>
        </div>
    `;

    const optionList = question.querySelector('[data-options-list]');
    optionList.append(createOptionElement(), createOptionElement());
    question.querySelector('[data-field="correct-option"]').checked = true;
    return question;
}

function reindexQuizBuilder() {
    const questions = Array.from(
        quizBuilderList.querySelectorAll('.quiz-builder-question')
    );

    questions.forEach(function (question, questionIndex) {
        question.querySelector('[data-question-number]').textContent = questionIndex + 1;
        question.querySelector('[data-field="question-id"]').name =
            `questions[${questionIndex}][question_id]`;
        question.querySelector('[data-field="question-content"]').name =
            `questions[${questionIndex}][content]`;

        const options = Array.from(question.querySelectorAll('[data-option]'));
        const hasCheckedOption = options.some(function (option) {
            return option.querySelector('[data-field="correct-option"]').checked;
        });

        if (!hasCheckedOption && options.length > 0) {
            options[0].querySelector('[data-field="correct-option"]').checked = true;
        }

        options.forEach(function (option, optionIndex) {
            const optionLetter = String.fromCharCode(65 + optionIndex);
            option.querySelector('[data-option-letter]').textContent = optionLetter;
            option.querySelector('[data-field="option-id"]').name =
                `questions[${questionIndex}][options][${optionIndex}][option_id]`;

            const contentInput = option.querySelector('[data-field="option-content"]');
            contentInput.name =
                `questions[${questionIndex}][options][${optionIndex}][content]`;
            contentInput.placeholder = `Answer ${optionLetter}`;

            const correctInput = option.querySelector('[data-field="correct-option"]');
            correctInput.name = `questions[${questionIndex}][correct_option]`;
            correctInput.value = optionIndex;

            option.querySelector('[data-remove-option]').disabled = options.length <= 2;
        });

        const addOptionButton = question.querySelector('[data-add-option]');
        addOptionButton.disabled = options.length >= 4;
        addOptionButton.hidden = options.length >= 4;
    });

    builderQuestionCount.textContent = questions.length;
    quizBuilderEmpty.classList.toggle('is-hidden', questions.length > 0);
}

document.getElementById('add-builder-question').addEventListener('click', function () {
    const question = createQuestionElement();
    quizBuilderList.appendChild(question);
    reindexQuizBuilder();
    question.scrollIntoView({behavior: 'smooth', block: 'center'});
    question.querySelector('[data-field="question-content"]').focus();
});

quizBuilderList.addEventListener('click', async function (event) {
    const addOptionButton = event.target.closest('[data-add-option]');

    if (addOptionButton) {
        const question = addOptionButton.closest('.quiz-builder-question');
        const optionList = question.querySelector('[data-options-list]');

        if (optionList.querySelectorAll('[data-option]').length < 4) {
            optionList.appendChild(createOptionElement());
            reindexQuizBuilder();
        }
        return;
    }

    const removeOptionButton = event.target.closest('[data-remove-option]');

    if (removeOptionButton) {
        const question = removeOptionButton.closest('.quiz-builder-question');
        const options = question.querySelectorAll('[data-option]');

        if (options.length > 2) {
            removeOptionButton.closest('[data-option]').remove();
            reindexQuizBuilder();
        }
        return;
    }

    const deleteQuestionButton = event.target.closest('[data-delete-question]');

    if (!deleteQuestionButton) {
        return;
    }

    const question = deleteQuestionButton.closest('.quiz-builder-question');
    const questionId = Number(deleteQuestionButton.dataset.questionId || 0);
    const result = await window.AppUI.confirm({
        title: 'Delete this question?',
        text: questionId > 0
            ? 'This deletes the saved question immediately. Any other unsaved edits on this page will not be saved.'
            : 'This unsaved question will be removed from the builder.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete Question',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc3545',
        reverseButtons: true
    });

    if (!result.isConfirmed) {
        return;
    }

    if (questionId <= 0) {
        question.remove();
        reindexQuizBuilder();
        return;
    }

    const deleteForm = document.createElement('form');
    deleteForm.method = 'POST';
    deleteForm.action = 'delete_question.php';
    deleteForm.hidden = true;
    const questionInput = document.createElement('input');
    questionInput.type = 'hidden';
    questionInput.name = 'question_id';
    questionInput.value = questionId;
    deleteForm.appendChild(questionInput);
    document.body.appendChild(deleteForm);
    deleteForm.submit();
});

quizBuilderForm.addEventListener('submit', function () {
    reindexQuizBuilder();
});

if (window.Sortable) {
    new window.Sortable(quizBuilderList, {
        animation: 160,
        handle: '.question-drag-handle',
        draggable: '.quiz-builder-question',
        ghostClass: 'quiz-builder-question-ghost',
        chosenClass: 'quiz-builder-question-chosen',
        onEnd: reindexQuizBuilder
    });
}

reindexQuizBuilder();
});
</script>
<?php endif; ?>

<?php include 'includes/layout_end.php'; ?>
