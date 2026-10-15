<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/quiz_lock.php';

$teacher_id = $_SESSION['teacher_id'];
$error = '';

$quiz_id = isset($_GET['quiz_id'])
    ? (int) $_GET['quiz_id']
    : 0;

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

if ($quiz_state['is_locked']) {
    header('Location: quiz.php?id=' . $quiz_id . '&error=locked');
    exit;
}

$questions_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $questions_data = $_POST['questions'] ?? [];

    if (!is_array($questions_data) || empty($questions_data)) {
        $error = "Please add at least one question.";
    } else {
        $conn->begin_transaction();

        try {
            $quiz_state = loadOwnedQuizEditState(
                $conn,
                $quiz_id,
                (int) $teacher_id,
                true
            );

            if ($quiz_state === null) {
                $conn->rollback();
                header('Location: quizzes.php?error=access');
                exit;
            }

            if ($quiz_state['is_locked']) {
                $conn->rollback();
                header('Location: quiz.php?id=' . $quiz_id . '&error=locked');
                exit;
            }

            $stmt = $conn->prepare("
                SELECT COALESCE(MAX(question_order), 0) + 1 AS next_order
                FROM questions
                WHERE quiz_id = ?
                FOR UPDATE
            ");
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();

            $next_order = (int) $stmt
                ->get_result()
                ->fetch_assoc()['next_order'];

            $question_stmt = $conn->prepare("
                INSERT INTO questions
                    (quiz_id, question_order, content)
                VALUES
                    (?, ?, ?)
            ");

            $option_stmt = $conn->prepare("
                INSERT INTO question_options
                    (
                        question_id,
                        option_order,
                        content,
                        is_correct
                    )
                VALUES
                    (?, ?, ?, ?)
            ");

            foreach (array_values($questions_data) as $question_index => $question_data) {
                if (!is_array($question_data)) {
                    throw new Exception(
                        "Question " . ($question_index + 1) . " is invalid."
                    );
                }

                $content = trim($question_data['content'] ?? '');
                $options_data = $question_data['options'] ?? [];
                $correct_option_raw = $question_data['correct_option'] ?? null;

                if ($content === '') {
                    throw new Exception(
                        "Question "
                        . ($question_index + 1)
                        . ": question content is required."
                    );
                }

                if (!is_array($options_data)) {
                    throw new Exception(
                        "Question "
                        . ($question_index + 1)
                        . ": answer options are invalid."
                    );
                }

                $option_keys = array_keys($options_data);
                $option_count = count($options_data);

                if ($option_count < 2 || $option_count > 4) {
                    throw new Exception(
                        "Question "
                        . ($question_index + 1)
                        . ": provide between 2 and 4 answer options."
                    );
                }

                $correct_option_key = filter_var(
                    $correct_option_raw,
                    FILTER_VALIDATE_INT
                );

                if ($correct_option_key === false) {
                    throw new Exception(
                        "Question "
                        . ($question_index + 1)
                        . ": select exactly one correct answer."
                    );
                }

                $correct_option = array_search(
                    $correct_option_key,
                    $option_keys,
                    false
                );

                if ($correct_option === false) {
                    throw new Exception(
                        "Question "
                        . ($question_index + 1)
                        . ": the correct answer is invalid."
                    );
                }

                $option_contents = [];

                foreach (array_values($options_data) as $option_index => $option_data) {
                    if (!is_array($option_data)) {
                        throw new Exception(
                            "Question "
                            . ($question_index + 1)
                            . ": answer option "
                            . ($option_index + 1)
                            . " is invalid."
                        );
                    }

                    $option_content = trim($option_data['content'] ?? '');

                    if ($option_content === '') {
                        throw new Exception(
                            "Question "
                            . ($question_index + 1)
                            . ": all answer options are required."
                        );
                    }

                    $option_contents[] = $option_content;
                }

                $question_stmt->bind_param(
                    "iis",
                    $quiz_id,
                    $next_order,
                    $content
                );
                $question_stmt->execute();

                $question_id = $conn->insert_id;

                foreach ($option_contents as $option_index => $option_content) {
                    $option_order = $option_index + 1;
                    $is_correct = $option_index === $correct_option ? 1 : 0;

                    $option_stmt->bind_param(
                        "iisi",
                        $question_id,
                        $option_order,
                        $option_content,
                        $is_correct
                    );
                    $option_stmt->execute();
                }

                $next_order++;
            }

            $conn->commit();

            header(
                "Location: quiz.php?id="
                . $quiz_id
                . "&added=1"
            );
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$form_questions = [];

if (!empty($questions_data) && is_array($questions_data)) {
    foreach (array_values($questions_data) as $question_data) {
        if (!is_array($question_data)) {
            continue;
        }

        $options = [];

        if (isset($question_data['options']) && is_array($question_data['options'])) {
            $raw_options = $question_data['options'];
            $raw_option_keys = array_keys($raw_options);

            foreach (array_values($raw_options) as $option_data) {
                $options[] = [
                    'content' => is_array($option_data)
                        ? ($option_data['content'] ?? '')
                        : ''
                ];
            }
        }

        $options = array_slice($options, 0, 4);

        while (count($options) < 2) {
            $options[] = ['content' => ''];
        }

        $correct_option_key = isset($question_data['correct_option'])
            ? (int) $question_data['correct_option']
            : 0;

        $correct_option = array_search(
            $correct_option_key,
            $raw_option_keys ?? [],
            false
        );

        if ($correct_option === false || $correct_option >= count($options)) {
            $correct_option = 0;
        }

        $form_questions[] = [
            'content' => $question_data['content'] ?? '',
            'options' => $options,
            'correct_option' => $correct_option
        ];
    }
}

if (empty($form_questions)) {
    $form_questions[] = [
        'content' => '',
        'options' => [
            ['content' => ''],
            ['content' => '']
        ],
        'correct_option' => 0
    ];
}
?>

<?php
$page_title = 'Add Questions';
$page_styles = ['assets/css/quiz.css?v=20261005-4'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <div class="form-card">

        <h1>Add Questions</h1>

        <p>
            Quiz:
            <?= htmlspecialchars($quiz['title']) ?>
        </p>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="questions-form">

            <div id="questions-container">

                <?php foreach ($form_questions as $question_index => $form_question): ?>

                    <div
                        class="question-block"
                        data-question-index="<?= $question_index ?>"
                    >
                        <div class="question-block-header">
                            <h3>Question <?= $question_index + 1 ?></h3>

                            <?php if ($question_index > 0): ?>
                                <button
                                    type="button"
                                    class="remove-question-button"
                                    onclick="removeQuestion(this)"
                                >
                                    Remove question
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Question</label>

                            <textarea
                                name="questions[<?= $question_index ?>][content]"
                                required
                            ><?= htmlspecialchars($form_question['content']) ?></textarea>
                        </div>

                        <div class="option-editor-list">

                            <?php foreach ($form_question['options'] as $option_index => $form_option): ?>

                                <div
                                    class="option-editor-row"
                                    data-option-index="<?= $option_index ?>"
                                >
                                    <label
                                        class="correct-option-control"
                                        title="Mark as correct answer"
                                    >
                                        <input
                                            type="radio"
                                            name="questions[<?= $question_index ?>][correct_option]"
                                            value="<?= $option_index ?>"
                                            <?= $form_question['correct_option'] === $option_index
                                                ? 'checked'
                                                : ''
                                            ?>
                                            required
                                        >

                                        <span class="sr-only">
                                            Mark option
                                            <?= chr(65 + $option_index) ?>
                                            as correct
                                        </span>
                                    </label>

                                    <span class="option-letter">
                                        <?= chr(65 + $option_index) ?>
                                    </span>

                                    <input
                                        type="text"
                                        class="option-content-input"
                                        name="questions[<?= $question_index ?>][options][<?= $option_index ?>][content]"
                                        value="<?= htmlspecialchars($form_option['content']) ?>"
                                        placeholder="Answer <?= $option_index + 1 ?>"
                                        aria-label="Option <?= chr(65 + $option_index) ?>"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="remove-option-button"
                                        onclick="removeOption(this)"
                                        <?= count($form_question['options']) <= 2
                                            ? 'disabled'
                                            : ''
                                        ?>
                                        aria-label="Remove option"
                                    >
                                        ×
                                    </button>
                                </div>

                            <?php endforeach; ?>

                        </div>

                        <button
                            type="button"
                            class="add-option-button"
                            onclick="addOption(this)"
                            <?= count($form_question['options']) >= 4
                                ? 'hidden'
                                : ''
                            ?>
                        >
                            + Add answer
                        </button>
                    </div>

                <?php endforeach; ?>

            </div>

            <button
                type="button"
                id="add-question-button"
                class="secondary-button"
            >
                + Add another question
            </button>

            <div class="form-actions">
                <a
                    href="quiz.php?id=<?= $quiz_id ?>"
                    class="cancel-button"
                >
                    Cancel
                </a>

                <button type="submit" class="save-button">
                    Save All Questions
                </button>
            </div>

        </form>

    </div>

</div>

<script>
let nextQuestionIndex = <?= count($form_questions) ?>;

document
    .getElementById('add-question-button')
    .addEventListener('click', function () {
        const container = document.getElementById(
            'questions-container'
        );
        const questionIndex = nextQuestionIndex++;

        const block = document.createElement('div');
        block.className = 'question-block';
        block.dataset.questionIndex = questionIndex;

        block.innerHTML = `
            <div class="question-block-header">
                <h3>Question</h3>

                <button
                    type="button"
                    class="remove-question-button"
                    onclick="removeQuestion(this)"
                >
                    Remove question
                </button>
            </div>

            <div class="form-group">
                <label>Question</label>

                <textarea
                    name="questions[${questionIndex}][content]"
                    required
                ></textarea>
            </div>

            <div class="option-editor-list">
                ${createOptionRow(questionIndex, 0, true)}
                ${createOptionRow(questionIndex, 1, false)}
            </div>

            <button
                type="button"
                class="add-option-button"
                onclick="addOption(this)"
            >
                + Add answer
            </button>
        `;

        container.appendChild(block);
        renumberQuestions();
        updateOptionControls(block);
    });

function createOptionRow(questionIndex, optionIndex, checked) {
    const optionLabel = String.fromCharCode(65 + optionIndex);

    return `
        <div
            class="option-editor-row"
            data-option-index="${optionIndex}"
        >
            <label
                class="correct-option-control"
                title="Mark as correct answer"
            >
                <input
                    type="radio"
                    name="questions[${questionIndex}][correct_option]"
                    value="${optionIndex}"
                    ${checked ? 'checked' : ''}
                    required
                >
                <span class="sr-only">
                    Mark as correct answer
                </span>
            </label>

            <span class="option-letter">${optionLabel}</span>

            <input
                type="text"
                class="option-content-input"
                name="questions[${questionIndex}][options][${optionIndex}][content]"
                placeholder="Answer ${optionIndex + 1}"
                aria-label="Option ${optionLabel}"
                required
            >

            <button
                type="button"
                class="remove-option-button"
                onclick="removeOption(this)"
                aria-label="Remove option"
            >
                ×
            </button>
        </div>
    `;
}

function addOption(button) {
    const block = button.closest('.question-block');
    const list = block.querySelector('.option-editor-list');
    const rows = list.querySelectorAll('.option-editor-row');

    if (rows.length >= 4) {
        return;
    }

    const questionIndex = block.dataset.questionIndex;
    const usedIndexes = Array.from(rows).map(
        row => Number(row.dataset.optionIndex)
    );
    const optionIndex = usedIndexes.length === 0
        ? 0
        : Math.max(...usedIndexes) + 1;

    list.insertAdjacentHTML(
        'beforeend',
        createOptionRow(questionIndex, optionIndex, false)
    );

    renumberOptions(block);
    updateOptionControls(block);
}

function removeOption(button) {
    const block = button.closest('.question-block');
    const rows = block.querySelectorAll('.option-editor-row');

    if (rows.length <= 2) {
        return;
    }

    const row = button.closest('.option-editor-row');
    const removedCorrect = row.querySelector(
        'input[type="radio"]'
    ).checked;

    row.remove();

    if (removedCorrect) {
        const firstRadio = block.querySelector(
            '.option-editor-row input[type="radio"]'
        );

        if (firstRadio) {
            firstRadio.checked = true;
        }
    }

    renumberOptions(block);
    updateOptionControls(block);
}

function updateOptionControls(block) {
    const rows = block.querySelectorAll('.option-editor-row');
    const removeButtons = block.querySelectorAll(
        '.remove-option-button'
    );
    const addButton = block.querySelector('.add-option-button');

    removeButtons.forEach(button => {
        button.disabled = rows.length <= 2;
    });

    addButton.hidden = rows.length >= 4;
}

function renumberOptions(block) {
    const questionIndex = block.dataset.questionIndex;
    const rows = block.querySelectorAll('.option-editor-row');

    rows.forEach((row, index) => {
        const letter = row.querySelector('.option-letter');
        const radio = row.querySelector('input[type="radio"]');
        const input = row.querySelector('input[type="text"]');

        const optionLetter = String.fromCharCode(65 + index);

        letter.textContent = optionLetter;
        input.placeholder = 'Answer ' + (index + 1);
        input.setAttribute(
            'aria-label',
            'Option ' + optionLetter
        );

        row.dataset.optionIndex = index;
        radio.value = index;
        radio.name = 'questions['
            + questionIndex
            + '][correct_option]';
        input.name = 'questions['
            + questionIndex
            + '][options]['
            + index
            + '][content]';
    });
}

function removeQuestion(button) {
    button.closest('.question-block').remove();
    renumberQuestions();
}

function renumberQuestions() {
    const blocks = document.querySelectorAll('.question-block');

    blocks.forEach((block, index) => {
        const title = block.querySelector(
            '.question-block-header h3'
        );

        if (title) {
            title.textContent = 'Question ' + (index + 1);
        }
    });
}
</script>

<?php include 'includes/layout_end.php'; ?>
