<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/quiz_lock.php';

$teacher_id = $_SESSION['teacher_id'];
$error = '';

$question_id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($question_id <= 0) {
    die("Invalid question ID.");
}

$stmt = $conn->prepare("
    SELECT
        q.question_id,
        q.quiz_id,
        q.question_order,
        q.content,
        qu.title AS quiz_title
    FROM questions q
    JOIN quizzes qu
        ON q.quiz_id = qu.quiz_id
    WHERE q.question_id = ?
      AND qu.teacher_id = ?
");
$stmt->bind_param("ii", $question_id, $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Question not found or access denied.");
}

$question = $result->fetch_assoc();
$quiz_id = (int) $question['quiz_id'];
$quiz_state = loadOwnedQuizEditState($conn, $quiz_id, (int) $teacher_id);

if ($quiz_state === null) {
    die("Question not found or access denied.");
}

if ($quiz_state['is_locked']) {
    header('Location: quiz.php?id=' . $quiz_id . '&error=locked');
    exit;
}

$stmt = $conn->prepare("
    SELECT
        option_id,
        option_order,
        content,
        is_correct
    FROM question_options
    WHERE question_id = ?
    ORDER BY option_order
");
$stmt->bind_param("i", $question_id);
$stmt->execute();
$options_result = $stmt->get_result();

$options = [];

while ($option = $options_result->fetch_assoc()) {
    $options[] = [
        'option_id' => (int) $option['option_id'],
        'option_order' => (int) $option['option_order'],
        'content' => $option['content'],
        'is_correct' => (int) $option['is_correct'] === 1
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = trim($_POST['content'] ?? '');
    $options_data = $_POST['options'] ?? [];
    $correct_option_raw = $_POST['correct_option'] ?? null;

    try {
        if ($content === '') {
            throw new Exception("Question content is required.");
        }

        if (!is_array($options_data)) {
            throw new Exception("Answer options are invalid.");
        }

        $option_keys = array_keys($options_data);
        $option_count = count($options_data);

        if ($option_count < 2 || $option_count > 4) {
            throw new Exception(
                "Provide between 2 and 4 answer options."
            );
        }

        $correct_option_key = filter_var(
            $correct_option_raw,
            FILTER_VALIDATE_INT
        );

        if ($correct_option_key === false) {
            throw new Exception(
                "Select exactly one correct answer."
            );
        }

        $correct_option = array_search(
            $correct_option_key,
            $option_keys,
            false
        );

        if ($correct_option === false) {
            throw new Exception("The correct answer is invalid.");
        }

        $normalized_options = [];

        foreach (array_values($options_data) as $option_index => $option_data) {
            if (!is_array($option_data)) {
                throw new Exception(
                    "Answer option "
                    . ($option_index + 1)
                    . " is invalid."
                );
            }

            $option_content = trim($option_data['content'] ?? '');
            $option_id = isset($option_data['option_id'])
                ? (int) $option_data['option_id']
                : 0;

            if ($option_content === '') {
                throw new Exception(
                    "All answer options are required."
                );
            }

            $normalized_options[] = [
                'option_id' => $option_id,
                'content' => $option_content,
                'is_correct' => $option_index === $correct_option
            ];
        }

        $existing_option_ids = [];

        foreach ($options as $existing_option) {
            $existing_option_ids[
                $existing_option['option_id']
            ] = true;
        }

        $submitted_existing_ids = [];

        foreach ($normalized_options as $option_data) {
            $option_id = $option_data['option_id'];

            if ($option_id <= 0) {
                continue;
            }

            if (
                !isset($existing_option_ids[$option_id])
                || isset($submitted_existing_ids[$option_id])
            ) {
                throw new Exception(
                    "One or more answer options are invalid."
                );
            }

            $submitted_existing_ids[$option_id] = true;
        }

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
                UPDATE questions
                SET content = ?
                WHERE question_id = ?
                  AND quiz_id = ?
            ");
            $stmt->bind_param(
                "sii",
                $content,
                $question_id,
                $quiz_id
            );
            $stmt->execute();

            $stmt = $conn->prepare("
                UPDATE question_options
                SET option_order = option_order + 100
                WHERE question_id = ?
            ");
            $stmt->bind_param("i", $question_id);
            $stmt->execute();

            $update_option_stmt = $conn->prepare("
                UPDATE question_options
                SET
                    content = ?,
                    option_order = ?,
                    is_correct = ?
                WHERE option_id = ?
                  AND question_id = ?
            ");

            $insert_option_stmt = $conn->prepare("
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

            $kept_option_ids = [];

            foreach ($normalized_options as $option_index => $option_data) {
                $option_order = $option_index + 1;
                $is_correct = $option_data['is_correct'] ? 1 : 0;
                $option_content = $option_data['content'];
                $option_id = $option_data['option_id'];

                if ($option_id > 0) {
                    $update_option_stmt->bind_param(
                        "siiii",
                        $option_content,
                        $option_order,
                        $is_correct,
                        $option_id,
                        $question_id
                    );
                    $update_option_stmt->execute();
                    $kept_option_ids[$option_id] = true;
                } else {
                    $insert_option_stmt->bind_param(
                        "iisi",
                        $question_id,
                        $option_order,
                        $option_content,
                        $is_correct
                    );
                    $insert_option_stmt->execute();
                }
            }

            $delete_option_stmt = $conn->prepare("
                DELETE FROM question_options
                WHERE option_id = ?
                  AND question_id = ?
            ");

            foreach ($existing_option_ids as $option_id => $_unused) {
                if (!isset($kept_option_ids[$option_id])) {
                    $delete_option_stmt->bind_param(
                        "ii",
                        $option_id,
                        $question_id
                    );
                    $delete_option_stmt->execute();
                }
            }

            $conn->commit();

            header(
                "Location: quiz.php?id="
                . $quiz_id
                . "&updated=1"
            );
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$form_content = $_POST['content'] ?? $question['content'];
$form_options = [];
$correct_option = 0;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['options'])
    && is_array($_POST['options'])
) {
    $raw_options = $_POST['options'];
    $raw_option_keys = array_keys($raw_options);

    foreach (array_slice(array_values($raw_options), 0, 4) as $option_data) {
        $form_options[] = [
            'option_id' => is_array($option_data)
                ? (int) ($option_data['option_id'] ?? 0)
                : 0,
            'content' => is_array($option_data)
                ? ($option_data['content'] ?? '')
                : '',
            'is_correct' => false
        ];
    }

    $correct_key = isset($_POST['correct_option'])
        ? (int) $_POST['correct_option']
        : 0;

    $correct_position = array_search(
        $correct_key,
        $raw_option_keys,
        false
    );

    if (
        $correct_position !== false
        && $correct_position < count($form_options)
    ) {
        $correct_option = $correct_position;
    }
} else {
    $form_options = $options;

    foreach ($form_options as $option_index => $option) {
        if ($option['is_correct']) {
            $correct_option = $option_index;
            break;
        }
    }
}

while (count($form_options) < 2) {
    $form_options[] = [
        'option_id' => 0,
        'content' => '',
        'is_correct' => false
    ];
}
?>

<?php
$page_title = 'Edit Question';
$page_styles = ['assets/css/quiz.css?v=20261005-4'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <div class="form-card">

        <h1>Edit Question</h1>

        <p>
            Quiz:
            <?= htmlspecialchars($question['quiz_title']) ?>
        </p>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="form-group">
                <label>Question</label>

                <textarea
                    name="content"
                    required
                ><?= htmlspecialchars($form_content) ?></textarea>
            </div>

            <div class="option-editor-list">

                <?php foreach ($form_options as $option_index => $option): ?>

                    <div
                        class="option-editor-row"
                        data-option-index="<?= $option_index ?>"
                    >
                        <input
                            type="hidden"
                            class="option-id-input"
                            name="options[<?= $option_index ?>][option_id]"
                            value="<?= (int) $option['option_id'] ?>"
                        >

                        <label
                            class="correct-option-control"
                            title="Mark as correct answer"
                        >
                            <input
                                type="radio"
                                name="correct_option"
                                value="<?= $option_index ?>"
                                <?= $correct_option === $option_index
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
                            name="options[<?= $option_index ?>][content]"
                            value="<?= htmlspecialchars($option['content']) ?>"
                            placeholder="Answer <?= $option_index + 1 ?>"
                            aria-label="Option <?= chr(65 + $option_index) ?>"
                            required
                        >

                        <button
                            type="button"
                            class="remove-option-button"
                            onclick="removeOption(this)"
                            <?= count($form_options) <= 2
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
                id="add-option-button"
                class="add-option-button"
                <?= count($form_options) >= 4 ? 'hidden' : '' ?>
            >
                + Add answer
            </button>

            <div class="form-actions">
                <a
                    href="quiz.php?id=<?= $quiz_id ?>"
                    class="cancel-button"
                >
                    Cancel
                </a>

                <button type="submit" class="save-button">
                    Save Changes
                </button>
            </div>

        </form>

    </div>

</div>

<script>
const addOptionButton = document.getElementById(
    'add-option-button'
);

addOptionButton.addEventListener('click', function () {
    const list = document.querySelector('.option-editor-list');
    const rows = list.querySelectorAll('.option-editor-row');

    if (rows.length >= 4) {
        return;
    }

    const optionIndex = rows.length;
    const row = document.createElement('div');

    row.className = 'option-editor-row';
    row.dataset.optionIndex = optionIndex;

    row.innerHTML = `
        <input
            type="hidden"
            class="option-id-input"
            name="options[${optionIndex}][option_id]"
            value="0"
        >

        <label
            class="correct-option-control"
            title="Mark as correct answer"
        >
            <input
                type="radio"
                name="correct_option"
                value="${optionIndex}"
                required
            >
            <span class="sr-only">
                Mark as correct answer
            </span>
        </label>

        <span class="option-letter">
            ${String.fromCharCode(65 + optionIndex)}
        </span>

        <input
            type="text"
            class="option-content-input"
            name="options[${optionIndex}][content]"
            placeholder="Answer ${optionIndex + 1}"
            aria-label="Option ${String.fromCharCode(65 + optionIndex)}"
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
    `;

    list.appendChild(row);
    updateOptionControls();
});

function removeOption(button) {
    const rows = document.querySelectorAll('.option-editor-row');

    if (rows.length <= 2) {
        return;
    }

    const row = button.closest('.option-editor-row');
    const removedCorrect = row.querySelector(
        'input[type="radio"]'
    ).checked;

    row.remove();
    renumberOptions();

    if (removedCorrect) {
        const firstRadio = document.querySelector(
            '.option-editor-row input[type="radio"]'
        );

        if (firstRadio) {
            firstRadio.checked = true;
        }
    }

    updateOptionControls();
}

function renumberOptions() {
    const rows = document.querySelectorAll('.option-editor-row');

    rows.forEach((row, index) => {
        const optionIdInput = row.querySelector(
            '.option-id-input'
        );
        const radio = row.querySelector(
            'input[type="radio"]'
        );
        const letter = row.querySelector('.option-letter');
        const contentInput = row.querySelector(
            'input[type="text"]'
        );

        row.dataset.optionIndex = index;
        optionIdInput.name = 'options['
            + index
            + '][option_id]';
        radio.value = index;
        contentInput.name = 'options['
            + index
            + '][content]';
        const optionLetter = String.fromCharCode(65 + index);

        letter.textContent = optionLetter;
        contentInput.placeholder = 'Answer ' + (index + 1);
        contentInput.setAttribute(
            'aria-label',
            'Option ' + optionLetter
        );
    });
}

function updateOptionControls() {
    const rows = document.querySelectorAll('.option-editor-row');
    const removeButtons = document.querySelectorAll(
        '.remove-option-button'
    );

    removeButtons.forEach(button => {
        button.disabled = rows.length <= 2;
    });

    addOptionButton.hidden = rows.length >= 4;
}
</script>

<?php include 'includes/layout_end.php'; ?>
