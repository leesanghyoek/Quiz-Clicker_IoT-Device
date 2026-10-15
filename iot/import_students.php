<?php
/*
 * Import a class list: upload a CSV file or paste the columns from Excel,
 * check every row in a preview, then save the students in one go.
 *
 * The expected columns are STT, MSSV and Họ tên (a header row is optional):
 *   STT     the two-digit number the student types on the answer device;
 *   MSSV    the school's student code (optional);
 *   Họ tên  the full name, or separate "Họ đệm" and "Tên" columns.
 *
 * New STTs are added. An STT already in the class is updated with the
 * list's name and MSSV when the teacher keeps "update" ticked, so a list can
 * be imported again to correct it.
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/student_rules.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$preview_session_key = 'import_students_preview';
$max_rows = 200;
$max_bytes = 512 * 1024;
$error = '';

if (empty($_SESSION['import_students_csrf_token'])) {
    $_SESSION['import_students_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['import_students_csrf_token'];

// A ready-made file teachers can fill in and upload.
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students-template.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . "STT,MSSV,Họ tên\r\n"
        . "1,23134001,Nguyễn Văn An\r\n"
        . "2,23134002,Trần Thị Bình\r\n";
    exit;
}

/* ------------------------------------------------------------------
 * Reading the list
 * ---------------------------------------------------------------- */

// UTF-8 text with \n line endings. Excel's plain "CSV" on Vietnamese
// Windows is saved in Windows-1258, so anything that is not UTF-8 is
// read as that.
function studentImportText(string $raw): string
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    if (!preg_match('//u', $text)) {
        $converted = @iconv('CP1258', 'UTF-8//IGNORE', $text);
        $text = $converted === false ? '' : $converted;
    }

    return str_replace(["\r\n", "\r"], "\n", $text);
}

// Tab when pasted from a spreadsheet, otherwise whichever separator the first line uses most.
function studentImportDelimiter(string $line): string
{
    $counts = [
        "\t" => substr_count($line, "\t"),
        ';' => substr_count($line, ';'),
        ',' => substr_count($line, ',')
    ];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);

    return $counts[$delimiter] > 0 ? $delimiter : ',';
}

/** @return array<int, array{line: int, cells: array<int, string>}> */
function studentImportLines(string $text): array
{
    $delimiter = null;
    $rows = [];

    foreach (explode("\n", $text) as $index => $line) {
        if (trim($line) === '') {
            continue;
        }

        $delimiter ??= studentImportDelimiter($line);
        $cells = array_map(
            static fn ($cell) => normalizeStudentName((string) $cell),
            str_getcsv($line, $delimiter, '"', '\\')
        );

        $rows[] = ['line' => $index + 1, 'cells' => $cells];
    }

    return $rows;
}

/*
 * Columns named in a header row (English or Vietnamese labels).
 *
 * @return array{number: ?int, mssv: ?int, name: array<int, int>}|null
 */
function studentImportHeader(array $cells): ?array
{
    $patterns = [
        'number' => '/^(stt|tt|no\.?|#|s[oố]\s*(th[uứ]\s*t[uự]|tt)|student[\s_-]*(id|no\.?)|id)$/iu',
        'mssv' => '/^(mssv|mshs|msv|mhs|m[aã]\s*(s[oố]\s*)?(sinh\s*vi[eê]n|h[oọ]c\s*sinh|sv|hs)|student\s*code|code)$/iu',
        'full' => '/^(name|full\s*name|student\s*name|h[oọ]\s*(v[aà]\s*)?t[eê]n(\s*(h[oọ]c\s*sinh|sinh\s*vi[eê]n|sv|hs))?)$/iu',
        'family' => '/^(h[oọ](\s*(v[aà]\s*)?(t[eê]n\s*)?đ[eệ]m)?|last\s*name|family\s*name|surname)$/iu',
        'given' => '/^(t[eê]n|first\s*name|given\s*name)$/iu'
    ];
    $found = array_fill_keys(array_keys($patterns), null);

    foreach ($cells as $index => $cell) {
        foreach ($patterns as $role => $pattern) {
            if ($found[$role] === null && preg_match($pattern, $cell)) {
                $found[$role] = $index;
                break;
            }
        }
    }

    $name = $found['full'] !== null
        ? [$found['full']]
        : array_values(array_filter([$found['family'], $found['given']], static fn ($c) => $c !== null));

    if (empty($name) || ($found['number'] === null && $found['mssv'] === null)) {
        return null;
    }

    // A list with a single code column (an older "Mã HS" list) uses it as the device number.
    if ($found['number'] === null) {
        return ['number' => $found['mssv'], 'mssv' => null, 'name' => $name];
    }

    return ['number' => $found['number'], 'mssv' => $found['mssv'], 'name' => $name];
}

/*
 * Without a header, columns are told apart by what they hold: one- or
 * two-digit numbers are the STT, longer codes with digits are the MSSV,
 * and text with letters is the name. STT, MSSV, Họ tên is the default.
 *
 * @return array{number: int, mssv: ?int, name: array<int, int>}
 */
function studentImportGuessColumns(array $lines): array
{
    $sample = array_slice($lines, 0, 8);
    $width = 0;

    foreach ($sample as $line) {
        $width = max($width, count($line['cells']));
    }

    $scores = [];

    for ($column = 0; $column < $width; $column++) {
        $scores[$column] = ['number' => 0, 'mssv' => 0, 'name' => 0];

        foreach ($sample as $line) {
            $cell = $line['cells'][$column] ?? '';

            if (preg_match('/^\d{1,2}(\.0+)?$/', $cell)) {
                $scores[$column]['number']++;
            } elseif (preg_match('/^[A-Za-z0-9._\/-]{3,20}$/', $cell) && preg_match('/\d/', $cell)) {
                $scores[$column]['mssv']++;
            } elseif (preg_match('/\p{L}/u', $cell)) {
                $scores[$column]['name']++;
            }
        }
    }

    $pick = static function (string $role, array $taken) use ($scores, $sample): ?int {
        $best = null;

        foreach ($scores as $column => $score) {
            if (!in_array($column, $taken, true) && $score[$role] * 2 > count($sample)
                && ($best === null || $score[$role] > $scores[$best][$role])) {
                $best = $column;
            }
        }

        return $best;
    };

    $number = $pick('number', []);
    $name = $pick('name', [$number]);
    $mssv = $pick('mssv', [$number, $name]);

    if ($number === null || $name === null) {
        return $width >= 3
            ? ['number' => 0, 'mssv' => 1, 'name' => [2]]
            : ['number' => 0, 'mssv' => null, 'name' => [1]];
    }

    return ['number' => $number, 'mssv' => $mssv, 'name' => [$name]];
}

/**
 * Checks every row against the list itself and the students already in the class.
 *
 * @param array<string, array{name: string, mssv: string}> $existing STT => student
 * @return array{columns: array, rows: array<int, array>}
 */
function studentImportCheck(array $lines, array $existing): array
{
    if (empty($lines)) {
        return ['columns' => null, 'rows' => []];
    }

    // School exports often put a title ("DANH SÁCH LỚP 10A1") above the header row.
    $columns = null;

    foreach (array_slice($lines, 0, 5) as $index => $line) {
        $columns = studentImportHeader($line['cells']);

        if ($columns !== null) {
            $lines = array_slice($lines, $index + 1);
            break;
        }
    }

    $columns ??= studentImportGuessColumns($lines);

    $mssv_owner = [];

    foreach ($existing as $number => $student) {
        if ($student['mssv'] !== '') {
            // Array keys like "10" come back as integers; compare STTs as strings.
            $mssv_owner[$student['mssv']] = (string) $number;
        }
    }

    $seen_numbers = [];
    $seen_mssv = [];
    $rows = [];

    foreach ($lines as $line) {
        $cells = $line['cells'];
        $raw_number = (string) ($cells[$columns['number']] ?? '');
        $number = normalizeStudentNumber($raw_number);
        $mssv = $columns['mssv'] !== null ? normalizeMssv((string) ($cells[$columns['mssv']] ?? '')) : '';
        $name_parts = [];

        foreach ($columns['name'] as $column) {
            if (($cells[$column] ?? '') !== '') {
                $name_parts[] = $cells[$column];
            }
        }

        $name = implode(' ', $name_parts);
        $errors = [];

        if ($raw_number === '') {
            $errors[] = 'The STT is missing.';
        } elseif (!isValidStudentNumber($number)) {
            $errors[] = 'The STT must be ' . STUDENT_NUMBER_HINT . '.';
        } elseif (isset($seen_numbers[$number])) {
            $errors[] = 'STT ' . $number . ' is already used on line ' . $seen_numbers[$number] . ' of this list.';
        }

        if (!isValidMssv($mssv)) {
            $errors[] = 'The MSSV can only hold letters, digits and . _ - / (at most 20).';
        } elseif ($mssv !== '' && isset($seen_mssv[$mssv])) {
            $errors[] = 'MSSV ' . $mssv . ' is already used on line ' . $seen_mssv[$mssv] . ' of this list.';
        } elseif ($mssv !== '' && isset($mssv_owner[$mssv]) && $mssv_owner[$mssv] !== $number) {
            $errors[] = 'MSSV ' . $mssv . ' already belongs to STT ' . $mssv_owner[$mssv] . ' in this class.';
        }

        if ($name === '') {
            $errors[] = 'The name is missing.';
        } elseif (studentNameIsTooLong($name)) {
            $errors[] = 'The name is longer than 100 characters.';
        }

        if (empty($errors)) {
            $seen_numbers[$number] = $line['line'];

            if ($mssv !== '') {
                $seen_mssv[$mssv] = $line['line'];
            }
        }

        $current = $existing[$number] ?? null;
        $status = 'new';

        if (!empty($errors)) {
            $status = 'invalid';
        } elseif ($current !== null) {
            $status = $current['name'] === $name && $current['mssv'] === $mssv ? 'same' : 'update';
        }

        $rows[] = [
            'line' => $line['line'],
            'number' => $number !== '' ? $number : $raw_number,
            'mssv' => $mssv,
            'name' => $name,
            'status' => $status,
            'current' => $current,
            'errors' => $errors
        ];
    }

    return ['columns' => $columns, 'rows' => $rows];
}

/* ------------------------------------------------------------------
 * Classes this teacher can import into
 * ---------------------------------------------------------------- */
$classes = [];
$stmt = $conn->prepare("
    SELECT c.class_id, c.name, COUNT(s.id) AS total_students
    FROM classes c
    LEFT JOIN students s ON s.class_id = c.class_id
    WHERE c.teacher_id = ?
    GROUP BY c.class_id, c.name
    ORDER BY c.name
");
$stmt->bind_param('i', $teacher_id);
$stmt->execute();

foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $class_row) {
    $classes[(int) $class_row['class_id']] = $class_row;
}

$class_id = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);

if (!isset($classes[$class_id])) {
    $class_id = count($classes) === 1 ? (int) array_key_first($classes) : 0;
}

/** @return array<string, array{name: string, mssv: string}> STT => student */
function studentImportExisting(mysqli $conn, int $class_id, bool $lock = false): array
{
    $stmt = $conn->prepare(
        'SELECT student_id, mssv, name FROM students WHERE class_id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->bind_param('i', $class_id);
    $stmt->execute();

    $existing = [];

    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $student) {
        $existing[(string) $student['student_id']] = [
            'name' => (string) $student['name'],
            'mssv' => (string) ($student['mssv'] ?? '')
        ];
    }

    return $existing;
}

/* ------------------------------------------------------------------
 * Requests
 * ---------------------------------------------------------------- */
$preview = $_SESSION[$preview_session_key] ?? null;

if (
    !is_array($preview)
    || (int) ($preview['teacher_id'] ?? 0) !== $teacher_id
    || !isset($classes[(int) ($preview['class_id'] ?? 0)])
) {
    $preview = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $posted_token = $_POST['csrf_token'] ?? '';

    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        $error = 'Your form session expired. Please try again.';
    } elseif ($action === 'cancel') {
        unset($_SESSION[$preview_session_key]);
        header('Location: import_students.php?class_id=' . (int) ($preview['class_id'] ?? $class_id));
        exit;
    } elseif ($action === 'preview') {
        // A new check replaces whatever was being previewed before.
        unset($_SESSION[$preview_session_key]);
        $preview = null;
        $raw = '';
        $source = '';
        $file = $_FILES['list_file'] ?? null;
        $upload_error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $pasted = (string) ($_POST['pasted_list'] ?? '');

        if ($class_id <= 0) {
            $error = 'Choose the class to add the students to.';
        } elseif ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
            $error = 'The file is too large. Class lists should be well under 512 KB.';
        } elseif ($upload_error !== UPLOAD_ERR_NO_FILE && $upload_error !== UPLOAD_ERR_OK) {
            $error = 'The file could not be uploaded. Choose it again.';
        } elseif ($upload_error === UPLOAD_ERR_OK) {
            $path = (string) ($file['tmp_name'] ?? '');
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $mime = $path !== '' && is_file($path) ? (new finfo(FILEINFO_MIME_TYPE))->file($path) : false;

            if ($path === '' || !is_uploaded_file($path)) {
                $error = 'The uploaded file could not be verified.';
            } elseif (in_array($extension, ['xlsx', 'xls'], true)) {
                $error = 'Excel files cannot be read directly. In Excel choose File, Save As, "CSV UTF-8", '
                    . 'or copy the columns and paste them into the box below.';
            } elseif (!in_array($extension, ['csv', 'txt'], true)) {
                $error = 'Upload a .csv or .txt file.';
            } elseif (filesize($path) > $max_bytes) {
                $error = 'The file is too large. Class lists should be well under 512 KB.';
            } elseif (
                $mime === false
                || !in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)
            ) {
                $error = 'The file is not a text CSV file.';
            } else {
                $raw = (string) file_get_contents($path);
                $source = basename((string) $file['name']);
            }
        } elseif (trim($pasted) !== '') {
            if (strlen($pasted) > $max_bytes) {
                $error = 'The pasted list is too long.';
            } else {
                $raw = $pasted;
                $source = 'Pasted list';
            }
        } else {
            $error = 'Upload a CSV file or paste the list.';
        }

        if ($error === '') {
            $checked = studentImportCheck(
                studentImportLines(studentImportText($raw)),
                studentImportExisting($conn, $class_id)
            );
            $rows = $checked['rows'];

            if (empty($rows)) {
                $error = 'No students were found. Each line needs an STT and a name.';
            } elseif (count($rows) > $max_rows) {
                $error = 'The list has ' . count($rows) . ' rows; a class list can have at most ' . $max_rows . '.';
            } else {
                $preview = [
                    'teacher_id' => $teacher_id,
                    'class_id' => $class_id,
                    'source' => $source,
                    'has_mssv' => $checked['columns']['mssv'] !== null,
                    'rows' => $rows
                ];
                $_SESSION[$preview_session_key] = $preview;
            }
        }
    } elseif ($action === 'confirm' && $preview === null) {
        $error = 'The checked list has expired. Upload or paste it again.';
    } elseif ($action === 'confirm') {
        $import_class_id = (int) $preview['class_id'];
        $update_existing = ($_POST['update_existing'] ?? '') === '1';
        $added = 0;
        $updated = 0;
        $skipped = 0;

        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $conn->begin_transaction();

            // Check again under a lock: the class may have changed since the preview.
            $existing = studentImportExisting($conn, $import_class_id, true);
            $mssv_owner = [];

            foreach ($existing as $number => $student) {
                if ($student['mssv'] !== '') {
                    $mssv_owner[$student['mssv']] = (string) $number;
                }
            }

            $insert = $conn->prepare('INSERT INTO students (class_id, student_id, mssv, name) VALUES (?, ?, ?, ?)');
            $update = $conn->prepare('UPDATE students SET mssv = ?, name = ? WHERE class_id = ? AND student_id = ?');

            foreach ($preview['rows'] as $row) {
                $number = $row['number'];
                $mssv = $row['mssv'] !== '' ? $row['mssv'] : null;
                $mssv_taken = $mssv !== null && isset($mssv_owner[$mssv]) && $mssv_owner[$mssv] !== $number;

                if (in_array($row['status'], ['invalid', 'same'], true) || $mssv_taken) {
                    $skipped++;
                    continue;
                }

                if (isset($existing[$number])) {
                    if (!$update_existing) {
                        $skipped++;
                        continue;
                    }

                    $update->bind_param('ssis', $mssv, $row['name'], $import_class_id, $number);
                    $update->execute();
                    $updated++;
                } else {
                    $insert->bind_param('isss', $import_class_id, $number, $mssv, $row['name']);
                    $insert->execute();
                    $added++;
                }

                $existing[$number] = ['name' => $row['name'], 'mssv' => (string) $mssv];

                if ($mssv !== null) {
                    $mssv_owner[$mssv] = $number;
                }
            }

            $conn->commit();
            unset($_SESSION[$preview_session_key]);
            header(
                'Location: class.php?id=' . $import_class_id . '&tab=students'
                . '&imported=' . $added . '&updated_count=' . $updated . '&skipped=' . $skipped
            );
            exit;
        } catch (Throwable $exception) {
            try {
                $conn->rollback();
            } catch (Throwable $rollback_exception) {
                error_log('Student import rollback failed: ' . $rollback_exception->getMessage());
            }

            error_log('Student import failed for teacher ' . $teacher_id . ': ' . $exception->getMessage());
            $error = 'The students could not be imported. Nothing was changed; please try again.';
        }
    }
}

$counts = ['new' => 0, 'update' => 0, 'same' => 0, 'invalid' => 0];

foreach ($preview['rows'] ?? [] as $row) {
    $counts[$row['status']]++;
}

$preview_class = $preview !== null ? $classes[(int) $preview['class_id']] : null;
$back_href = $class_id > 0 ? 'class.php?id=' . $class_id . '&tab=students' : 'students.php';

function studentImportPlural(int $count, string $one, string $many): string
{
    return $count . ' ' . ($count === 1 ? $one : $many);
}
?>

<?php
$page_title = 'Import Students';
$page_styles = ['assets/css/quizzes.css?v=20261006-2'];
$layout_show_navigation = true;
include 'includes/layout_start.php';
?>

<div class="main-content management-page">

    <div class="form-card import-card student-import<?= $preview !== null ? ' has-preview' : '' ?>">

        <h1>Import Students</h1>

        <?php if (isset($_GET['created']) && $class_id > 0 && $preview === null): ?>
            <div class="success-message">
                Class <?= htmlspecialchars($classes[$class_id]['name'], ENT_QUOTES, 'UTF-8') ?> created. Add its student list below.
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error-message"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (empty($classes)): ?>
            <div class="empty-state">
                <h3>Create a class first</h3>
                <p>Students are imported into a class.</p>
                <a href="add_class.php" class="add-button">Create Class</a>
            </div>
        <?php elseif ($preview === null): ?>
            <p class="import-intro">
                Add a whole class list at once. Upload a CSV file, or select the columns in Excel,
                copy them and paste them below.
            </p>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="import-class">Class</label>
                    <select id="import-class" name="class_id" required>
                        <?php if ($class_id <= 0): ?>
                            <option value="" selected disabled>Choose a class</option>
                        <?php endif; ?>
                        <?php foreach ($classes as $class_option): ?>
                            <option
                                value="<?= (int) $class_option['class_id'] ?>"
                                <?= (int) $class_option['class_id'] === $class_id ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($class_option['name'], ENT_QUOTES, 'UTF-8') ?>
                                (<?= studentImportPlural((int) $class_option['total_students'], 'student', 'students') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="import-file">CSV file</label>
                    <input type="file" id="import-file" name="list_file" accept=".csv,.txt,text/csv,text/plain">
                </div>

                <div class="form-group">
                    <label for="import-paste">Or paste from Excel</label>
                    <textarea
                        id="import-paste"
                        name="pasted_list"
                        rows="6"
                        placeholder="1&#9;23134001&#9;Nguyễn Văn An&#10;2&#9;23134002&#9;Trần Thị Bình"
                    ></textarea>
                </div>

                <div class="import-format">
                    <p>
                        Columns: <strong>STT</strong>, <strong>MSSV</strong>, <strong>Họ tên</strong>.
                        The STT is the number the student types on the answer device (1 to 99; "1" is saved
                        as "01"). A header row is optional, and separate "Họ đệm" and "Tên" columns are
                        joined into one name. Importing the list again updates the names and MSSV of
                        students already in the class.
                    </p>
                    <a href="import_students.php?template=1">
                        <i class="ti ti-download" aria-hidden="true"></i>
                        Download a template
                    </a>
                </div>

                <div class="form-actions">
                    <a href="<?= htmlspecialchars($back_href, ENT_QUOTES, 'UTF-8') ?>" class="cancel-button">Cancel</a>
                    <button type="submit" class="save-button">Check list</button>
                </div>
            </form>
        <?php else: ?>
            <section class="import-preview" aria-labelledby="preview-title">
                <div class="import-preview-header">
                    <div>
                        <p class="import-preview-label">
                            <?= htmlspecialchars($preview['source'], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <h2 id="preview-title">
                            Into <?= htmlspecialchars($preview_class['name'], ENT_QUOTES, 'UTF-8') ?>
                        </h2>
                    </div>

                    <div class="import-summary" aria-label="Import summary">
                        <div class="import-summary-item is-valid">
                            <span>New</span>
                            <strong><?= $counts['new'] ?></strong>
                        </div>
                        <div class="import-summary-item">
                            <span>To update</span>
                            <strong><?= $counts['update'] ?></strong>
                        </div>
                        <div class="import-summary-item">
                            <span>Unchanged</span>
                            <strong><?= $counts['same'] ?></strong>
                        </div>
                        <div class="import-summary-item<?= $counts['invalid'] > 0 ? ' is-invalid' : '' ?>">
                            <span>With problems</span>
                            <strong><?= $counts['invalid'] ?></strong>
                        </div>
                    </div>
                </div>

                <?php if ($counts['invalid'] > 0): ?>
                    <p class="import-fix-notice">
                        Rows with problems will be skipped. Fix them in the file and import it again
                        to add those students too.
                    </p>
                <?php endif; ?>

                <?php if (!$preview['has_mssv']): ?>
                    <p class="import-fix-notice import-note">
                        No MSSV column was found, so only the STT and the name will be saved.
                    </p>
                <?php endif; ?>

                <div class="import-table-wrap">
                    <table class="import-preview-table">
                        <thead>
                            <tr>
                                <th scope="col">Line</th>
                                <th scope="col">STT</th>
                                <th scope="col">MSSV</th>
                                <th scope="col">Họ tên</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview['rows'] as $row): ?>
                                <tr class="<?= $row['status'] === 'invalid' ? 'invalid-row' : 'valid-row' ?>">
                                    <td><?= (int) $row['line'] ?></td>
                                    <td><?= htmlspecialchars($row['number'] !== '' ? $row['number'] : '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['mssv'] !== '' ? $row['mssv'] : '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['name'] !== '' ? $row['name'] : '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'new'): ?>
                                            <span class="import-status valid">New</span>
                                        <?php elseif ($row['status'] === 'update'): ?>
                                            <span class="import-status update">Update</span>
                                            <ul class="import-errors import-notes">
                                                <?php if ($row['current']['name'] !== $row['name']): ?>
                                                    <li>Name now: <?= htmlspecialchars($row['current']['name'], ENT_QUOTES, 'UTF-8') ?></li>
                                                <?php endif; ?>
                                                <?php if ($row['current']['mssv'] !== $row['mssv']): ?>
                                                    <li>MSSV now: <?= htmlspecialchars($row['current']['mssv'] !== '' ? $row['current']['mssv'] : 'empty', ENT_QUOTES, 'UTF-8') ?></li>
                                                <?php endif; ?>
                                            </ul>
                                        <?php elseif ($row['status'] === 'same'): ?>
                                            <span class="import-status existing">Already in class</span>
                                        <?php else: ?>
                                            <span class="import-status invalid">Problem</span>
                                            <ul class="import-errors">
                                                <?php foreach ($row['errors'] as $row_error): ?>
                                                    <li><?= htmlspecialchars($row_error, ENT_QUOTES, 'UTF-8') ?></li>
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
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="cancel-button">Choose another list</button>
                    </form>

                    <form method="POST" class="import-confirm-form">
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                        <?php if ($counts['update'] > 0): ?>
                            <label class="import-update-option">
                                <input type="checkbox" name="update_existing" value="1" checked>
                                Update <?= studentImportPlural($counts['update'], 'student', 'students') ?>
                                already in the class
                            </label>
                        <?php endif; ?>

                        <button
                            type="submit"
                            class="save-button"
                            <?= $counts['new'] + $counts['update'] === 0 ? 'disabled' : '' ?>
                        >
                            <?php if ($counts['new'] + $counts['update'] === 0): ?>
                                Nothing to save
                            <?php elseif ($counts['new'] > 0): ?>
                                Add <?= studentImportPlural($counts['new'], 'student', 'students') ?><?= $counts['update'] > 0 ? ' and save changes' : '' ?>
                            <?php else: ?>
                                Save changes
                            <?php endif; ?>
                        </button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

    </div>

</div>

<?php include 'includes/layout_end.php'; ?>
