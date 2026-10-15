<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$error = '';
$success = '';
$title = trim($_POST['title'] ?? '');
$parsed_questions = [];
$total_rows = 0;
$valid_rows = 0;
$invalid_rows = 0;
$validation_complete = false;
$preview_session_key = 'import_quiz_preview';
$result_session_key = 'import_quiz_result';
$import_result = null;

if (empty($_SESSION['import_quiz_csrf_token'])) {
    $_SESSION['import_quiz_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['import_quiz_csrf_token'];
$request_action = $_POST['action'] ?? 'upload';
$csrf_is_valid = $_SERVER['REQUEST_METHOD'] !== 'POST'
    || (
        isset($_POST['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals($csrf_token, $_POST['csrf_token'])
    );

$load_preview = static function (array $preview) use (
    &$title,
    &$parsed_questions,
    &$total_rows,
    &$valid_rows,
    &$invalid_rows,
    &$validation_complete
): void {
    $title = (string) ($preview['title'] ?? '');
    $parsed_questions = is_array($preview['questions'] ?? null)
        ? $preview['questions']
        : [];
    $total_rows = (int) ($preview['total_rows'] ?? 0);
    $valid_rows = (int) ($preview['valid_rows'] ?? 0);
    $invalid_rows = (int) ($preview['invalid_rows'] ?? 0);
    $validation_complete = true;
};

$validate_preview = static function (array $preview): bool {
    $preview_title = trim((string) ($preview['title'] ?? ''));
    $questions = $preview['questions'] ?? null;
    $total = (int) ($preview['total_rows'] ?? 0);
    $valid = (int) ($preview['valid_rows'] ?? 0);
    $invalid = (int) ($preview['invalid_rows'] ?? 0);

    if (
        $preview_title === ''
        || !is_array($questions)
        || $total <= 0
        || count($questions) !== $total
        || $valid !== $total
        || $invalid !== 0
    ) {
        return false;
    }

    foreach ($questions as $question) {
        if (
            !is_array($question)
            || ($question['is_valid'] ?? false) !== true
            || !empty($question['errors'])
            || trim((string) ($question['question'] ?? '')) === ''
            || !is_array($question['options'] ?? null)
        ) {
            return false;
        }

        $options = $question['options'];
        $option_contents = [];
        $empty_option_found = false;

        foreach (['A', 'B', 'C', 'D'] as $label) {
            $content = trim((string) ($options[$label] ?? ''));

            if ($content === '') {
                $empty_option_found = true;
                continue;
            }

            if ($empty_option_found) {
                return false;
            }

            $option_contents[$label] = $content;
        }

        if (
            count($option_contents) < 2
            || !isset($option_contents['A'], $option_contents['B'])
        ) {
            return false;
        }

        $correct = strtoupper(
            trim((string) ($question['correct'] ?? ''))
        );

        if (!isset($option_contents[$correct])) {
            return false;
        }
    }

    return true;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['imported'] ?? '') === '1') {
        $stored_result = $_SESSION[$result_session_key] ?? null;
        unset($_SESSION[$result_session_key]);

        if (
            is_array($stored_result)
            && (int) ($stored_result['teacher_id'] ?? 0) === $teacher_id
            && (int) ($stored_result['quiz_id'] ?? 0) > 0
            && trim((string) ($stored_result['title'] ?? '')) !== ''
            && (int) ($stored_result['question_count'] ?? 0) > 0
        ) {
            $import_result = [
                'quiz_id' => (int) $stored_result['quiz_id'],
                'title' => (string) $stored_result['title'],
                'question_count' => (int) $stored_result['question_count']
            ];
        }
    }

    $stored_preview = $_SESSION[$preview_session_key] ?? null;

    if ($import_result === null && is_array($stored_preview)) {
        $load_preview($stored_preview);
    }
} elseif (!$csrf_is_valid) {
    $error = 'Your form session expired. Please try again.';
} elseif ($request_action === 'cancel') {
    unset($_SESSION[$preview_session_key]);
    header('Location: quizzes.php');
    exit;
} elseif ($request_action === 'confirm') {
    $stored_preview = $_SESSION[$preview_session_key] ?? null;

    if (
        !is_array($stored_preview)
        || !$validate_preview($stored_preview)
    ) {
        unset($_SESSION[$preview_session_key]);
        $validation_complete = false;
        $error = 'The import preview has expired. Please upload the CSV again.';
    } else {
        $load_preview($stored_preview);
        $transaction_started = false;

        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('Could not start the import transaction.');
            }

            $transaction_started = true;

            $quiz_stmt = $conn->prepare("
                INSERT INTO quizzes
                    (teacher_id, title)
                VALUES
                    (?, ?)
            ");

            if (
                $quiz_stmt === false
                || !$quiz_stmt->bind_param('is', $teacher_id, $title)
                || !$quiz_stmt->execute()
            ) {
                throw new RuntimeException('Could not create the imported quiz.');
            }

            $quiz_id = (int) $conn->insert_id;

            if ($quiz_id <= 0) {
                throw new RuntimeException('The imported quiz ID was not created.');
            }

            $question_stmt = $conn->prepare("
                INSERT INTO questions
                    (quiz_id, question_order, content)
                VALUES
                    (?, ?, ?)
            ");

            if ($question_stmt === false) {
                throw new RuntimeException('Could not prepare question import.');
            }

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

            if ($option_stmt === false) {
                throw new RuntimeException('Could not prepare answer import.');
            }

            foreach (array_values($parsed_questions) as $question_index => $question) {
                $question_order = $question_index + 1;
                $question_content = trim((string) $question['question']);

                if (
                    !$question_stmt->bind_param(
                        'iis',
                        $quiz_id,
                        $question_order,
                        $question_content
                    )
                    || !$question_stmt->execute()
                ) {
                    throw new RuntimeException(
                        'Could not import question ' . $question_order . '.'
                    );
                }

                $question_id = (int) $conn->insert_id;

                if ($question_id <= 0) {
                    throw new RuntimeException(
                        'Question ID was not created for question '
                        . $question_order
                        . '.'
                    );
                }

                $correct = strtoupper(
                    trim((string) $question['correct'])
                );

                foreach (['A', 'B', 'C', 'D'] as $option_index => $label) {
                    $option_order = $option_index + 1;
                    $option_content = trim(
                        (string) ($question['options'][$label] ?? '')
                    );
                    $is_correct = $correct === $label ? 1 : 0;

                    if (
                        !$option_stmt->bind_param(
                            'iisi',
                            $question_id,
                            $option_order,
                            $option_content,
                            $is_correct
                        )
                        || !$option_stmt->execute()
                    ) {
                        throw new RuntimeException(
                            'Could not import option '
                            . $label
                            . ' for question '
                            . $question_order
                            . '.'
                        );
                    }
                }
            }

            if (!$conn->commit()) {
                throw new RuntimeException('Could not commit the quiz import.');
            }

            $transaction_started = false;

            unset($_SESSION[$preview_session_key]);

            $_SESSION[$result_session_key] = [
                'teacher_id' => $teacher_id,
                'quiz_id' => $quiz_id,
                'title' => $title,
                'question_count' => $total_rows
            ];

            header('Location: import_quiz.php?imported=1');
            exit;
        } catch (Throwable $exception) {
            if ($transaction_started) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollback_exception) {
                    error_log(
                        'Quiz import rollback failed for teacher '
                        . $teacher_id
                        . ': '
                        . $rollback_exception->getMessage()
                    );
                }
            }

            error_log(
                'Quiz import failed for teacher '
                . $teacher_id
                . ': '
                . $exception->getMessage()
            );
            $error = 'Import failed. No quiz was created. Please try again.';
        }
    }
} elseif ($request_action !== 'upload') {
    $error = 'Invalid import action.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $request_action === 'upload'
    && $csrf_is_valid
) {
    unset($_SESSION[$preview_session_key]);

    $expected_header = [
        'Question',
        'A',
        'B',
        'C',
        'D',
        'Correct'
    ];

    if ($title === '') {
        $error = 'Quiz title is required.';
    } elseif (
        !isset($_FILES['csv_file'])
        || !is_array($_FILES['csv_file'])
    ) {
        $error = 'A CSV file is required.';
    } else {
        $file = $_FILES['csv_file'];
        $upload_error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($upload_error === UPLOAD_ERR_NO_FILE) {
            $error = 'A CSV file is required.';
        } elseif ($upload_error !== UPLOAD_ERR_OK) {
            $error = 'The file upload failed. Please choose the CSV file again.';
        } else {
            $temporary_path = $file['tmp_name'] ?? '';
            $original_name = $file['name'] ?? '';
            $extension = strtolower(
                pathinfo($original_name, PATHINFO_EXTENSION)
            );

            if (
                $temporary_path === ''
                || !is_uploaded_file($temporary_path)
            ) {
                $error = 'The uploaded file could not be verified.';
            } elseif ($extension !== 'csv') {
                $error = 'Only CSV files are accepted.';
            } elseif (
                !is_file($temporary_path)
                || filesize($temporary_path) <= 0
            ) {
                $error = 'The uploaded CSV file is empty.';
            } else {
                $allowed_mime_types = [
                    'text/csv',
                    'text/plain',
                    'application/csv',
                    'application/vnd.ms-excel'
                ];

                $file_info = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $file_info->file($temporary_path);

                if (
                    $mime_type === false
                    || !in_array(
                        $mime_type,
                        $allowed_mime_types,
                        true
                    )
                ) {
                    $error = 'The uploaded file is not a valid CSV text file.';
                } else {
                    $handle = fopen($temporary_path, 'rb');

                    if ($handle === false) {
                        $error = 'The uploaded CSV file could not be read.';
                    } else {
                        $header = fgetcsv(
                            $handle,
                            0,
                            ',',
                            '"',
                            '\\'
                        );

                        if ($header === false) {
                            $error = 'The CSV header row is missing.';
                        } else {
                            $header = array_map(
                                static fn ($value) => trim(
                                    (string) $value
                                ),
                                $header
                            );

                            if (isset($header[0])) {
                                $header[0] = preg_replace(
                                    '/^\xEF\xBB\xBF/',
                                    '',
                                    $header[0]
                                );
                            }

                            if ($header !== $expected_header) {
                                $error = 'Invalid CSV header. Expected: '
                                    . 'Question,A,B,C,D,Correct';
                            } else {
                                $raw_rows = [];
                                $source_row_number = 2;

                                while (($row = fgetcsv(
                                    $handle,
                                    0,
                                    ',',
                                    '"',
                                    '\\'
                                )) !== false) {
                                    $raw_rows[] = [
                                        'row_number' => $source_row_number,
                                        'values' => $row
                                    ];
                                    $source_row_number++;
                                }

                                // Ignore completely blank rows only at the end.
                                while (!empty($raw_rows)) {
                                    $last_row = end($raw_rows);
                                    $has_value = false;

                                    foreach ($last_row['values'] as $value) {
                                        if (trim((string) $value) !== '') {
                                            $has_value = true;
                                            break;
                                        }
                                    }

                                    if ($has_value) {
                                        break;
                                    }

                                    array_pop($raw_rows);
                                }

                                foreach ($raw_rows as $raw_row) {
                                    $values = $raw_row['values'];
                                    $row_errors = [];

                                    if (count($values) !== 6) {
                                        $row_errors[] = 'Row must contain exactly 6 columns.';
                                    }

                                    $values = array_pad(
                                        array_slice($values, 0, 6),
                                        6,
                                        ''
                                    );
                                    $values = array_map(
                                        static fn ($value) => trim(
                                            (string) $value
                                        ),
                                        $values
                                    );

                                    [
                                        $question,
                                        $option_a,
                                        $option_b,
                                        $option_c,
                                        $option_d,
                                        $correct
                                    ] = $values;
                                    $correct = strtoupper($correct);

                                    if ($question === '') {
                                        $row_errors[] = 'Question is required.';
                                    }

                                    if ($option_a === '') {
                                        $row_errors[] = 'Option A is required.';
                                    }

                                    if ($option_b === '') {
                                        $row_errors[] = 'Option B is required.';
                                    }

                                    $option_values = [
                                        'A' => $option_a,
                                        'B' => $option_b,
                                        'C' => $option_c,
                                        'D' => $option_d
                                    ];
                                    $options = [];
                                    $empty_option_found = false;
                                    $has_gap = false;

                                    foreach ($option_values as $label => $value) {
                                        if ($value === '') {
                                            $empty_option_found = true;
                                            continue;
                                        }

                                        if ($empty_option_found) {
                                            $has_gap = true;
                                        }

                                        $options[$label] = $value;
                                    }

                                    if (count($options) < 2) {
                                        $row_errors[] = 'At least two answer options are required.';
                                    }

                                    if ($has_gap) {
                                        $row_errors[] = 'Answer options must not contain gaps.';
                                    }

                                    if (!in_array(
                                        $correct,
                                        ['A', 'B', 'C', 'D'],
                                        true
                                    )) {
                                        $row_errors[] = 'Correct must be A, B, C, or D.';
                                    } elseif (!array_key_exists($correct, $options)) {
                                        $row_errors[] = 'Correct points to an answer option that does not exist.';
                                    }

                                    $row_errors = array_values(
                                        array_unique($row_errors)
                                    );
                                    $is_valid = empty($row_errors);

                                    $parsed_questions[] = [
                                        'row_number' => $raw_row['row_number'],
                                        'question' => $question,
                                        'options' => $options,
                                        'correct' => $correct,
                                        'is_valid' => $is_valid,
                                        'errors' => $row_errors
                                    ];

                                    if ($is_valid) {
                                        $valid_rows++;
                                    } else {
                                        $invalid_rows++;
                                    }
                                }

                                $total_rows = count($parsed_questions);
                                $validation_complete = true;

                                if ($total_rows === 0) {
                                    $error = 'The CSV file must contain at least one question row.';
                                } else {
                                    $_SESSION[$preview_session_key] = [
                                        'title' => $title,
                                        'questions' => $parsed_questions,
                                        'total_rows' => $total_rows,
                                        'valid_rows' => $valid_rows,
                                        'invalid_rows' => $invalid_rows
                                    ];

                                    if ($invalid_rows > 0) {
                                        $error = 'CSV contains '
                                            . $invalid_rows
                                            . ' invalid question row(s). Review the preview below.';
                                    } else {
                                        $success = 'CSV validation passed for '
                                            . $valid_rows
                                            . ' question row(s). No data has been imported yet.';
                                    }
                                }
                            }
                        }

                        fclose($handle);
                    }
                }
            }
        }
    }
}
?>

<?php
$page_title = 'Import Quiz';
$page_styles = ['assets/css/quizzes.css?v=20261006-2'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <div class="form-card import-card<?= $validation_complete ? ' has-preview' : '' ?>">

        <h1>Import Quiz</h1>

        <?php if ($import_result !== null): ?>
            <div class="success-message">
                Quiz imported successfully.
            </div>

            <section aria-labelledby="import-result-title">
                <h2 id="import-result-title">
                    <?= htmlspecialchars(
                        $import_result['title'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </h2>

                <p>
                    <?= $import_result['question_count'] ?>
                    <?= $import_result['question_count'] === 1
                        ? 'question was'
                        : 'questions were'
                    ?>
                    imported.
                </p>

                <div class="form-actions">
                    <a href="quizzes.php" class="cancel-button">
                        Back to Quiz Library
                    </a>

                    <a
                        href="quiz.php?id=<?= $import_result['quiz_id'] ?>"
                        class="save-button"
                    >
                        Open Imported Quiz
                    </a>
                </div>
            </section>
        <?php else: ?>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="success-message">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form
            method="POST"
            enctype="multipart/form-data"
        >
            <input type="hidden" name="action" value="upload">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
            >
            <div class="form-group">
                <label for="quiz-title">Quiz Title</label>

                <input
                    type="text"
                    id="quiz-title"
                    name="title"
                    placeholder="Enter quiz title"
                    value="<?= htmlspecialchars($title) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="quiz-csv">CSV File</label>

                <input
                    type="file"
                    id="quiz-csv"
                    name="csv_file"
                    accept=".csv,text/csv"
                    required
                >
            </div>

            <div class="form-actions">
                <a
                    href="quizzes.php"
                    class="cancel-button"
                >
                    Back to Quiz Library
                </a>

                <button
                    type="submit"
                    class="save-button"
                >
                    Upload &amp; Preview
                </button>
            </div>
        </form>

        <?php if ($validation_complete): ?>
            <section class="import-preview" aria-labelledby="preview-title">
                <div class="import-preview-header">
                    <div>
                        <p class="import-preview-label">Quiz title</p>
                        <h2 id="preview-title">
                            <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                        </h2>
                    </div>

                    <div class="import-summary" aria-label="CSV validation summary">
                        <div class="import-summary-item">
                            <span>Total rows</span>
                            <strong><?= $total_rows ?></strong>
                        </div>
                        <div class="import-summary-item is-valid">
                            <span>Valid</span>
                            <strong><?= $valid_rows ?></strong>
                        </div>
                        <div class="import-summary-item<?= $invalid_rows > 0 ? ' is-invalid' : '' ?>">
                            <span>Invalid</span>
                            <strong><?= $invalid_rows ?></strong>
                        </div>
                    </div>
                </div>

                <?php if ($invalid_rows > 0): ?>
                    <p class="import-fix-notice">
                        Fix every invalid row in the CSV and upload it again before importing.
                    </p>
                <?php endif; ?>

                <div class="import-table-wrap">
                    <table class="import-preview-table">
                        <thead>
                            <tr>
                                <th scope="col">Row</th>
                                <th scope="col">Question</th>
                                <th scope="col">A</th>
                                <th scope="col">B</th>
                                <th scope="col">C</th>
                                <th scope="col">D</th>
                                <th scope="col">Correct</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parsed_questions as $parsed_question): ?>
                                <tr class="<?= $parsed_question['is_valid'] ? 'valid-row' : 'invalid-row' ?>">
                                    <td><?= (int) $parsed_question['row_number'] ?></td>
                                    <td>
                                        <?= htmlspecialchars(
                                            $parsed_question['question'] !== ''
                                                ? $parsed_question['question']
                                                : '—',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                    <?php foreach (['A', 'B', 'C', 'D'] as $option_label): ?>
                                        <td>
                                            <?= htmlspecialchars(
                                                $parsed_question['options'][$option_label] ?? '—',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>
                                    <?php endforeach; ?>

                                    <td>
                                        <?= htmlspecialchars(
                                            $parsed_question['correct'] !== ''
                                                ? $parsed_question['correct']
                                                : '—',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>
                                    <td>
                                        <?php if ($parsed_question['is_valid']): ?>
                                            <span class="import-status valid">Valid</span>
                                        <?php else: ?>
                                            <span class="import-status invalid">Invalid</span>
                                            <ul class="import-errors">
                                                <?php foreach ($parsed_question['errors'] as $row_error): ?>
                                                    <li>
                                                        <?= htmlspecialchars(
                                                            $row_error,
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="import-preview-actions">
                    <form method="POST">
                        <input type="hidden" name="action" value="cancel">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <button type="submit" class="cancel-button">Cancel</button>
                    </form>

                    <form method="POST">
                        <input type="hidden" name="action" value="confirm">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <button
                            type="submit"
                            class="save-button"
                            <?= $invalid_rows > 0 || $total_rows <= 0 ? 'disabled' : '' ?>
                        >
                            Confirm Import
                        </button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <?php endif; ?>

    </div>

</div>

<?php include 'includes/layout_end.php'; ?>
