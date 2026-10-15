<?php
/*
 * AI quiz generation: a teacher gives a document (PDF, Word or text) and an
 * AI writes multiple-choice questions from it; the teacher can ask for
 * changes, reviews every question, then saves the set as a normal quiz.
 *
 * Two providers, chosen with 'provider' in ai_config.php:
 *   'claude'  Anthropic's Claude API (needs an API key with credit; reads PDFs itself)
 *   'local'   a model on this computer behind llama.cpp's OpenAI-style server
 *             (~/llm/start.sh); free and offline, but text only and a smaller model
 *
 * Everything private lives outside the web root, in AI_PRIVATE_DIR:
 *   ai_config.php   returns ['provider' => ..., 'api_key' => 'sk-ant-...', ...] (see aiQuizConfig)
 *   vendor/         the official SDK (composer require anthropic-ai/sdk guzzlehttp/guzzle)
 *   ai-uploads/     documents being worked on (deleted after a day) and
 *                   .usage.json, generations per teacher per day for the daily limit;
 *                   the only folder the web server writes to
 */

const AI_PRIVATE_DIR = '/var/www/iot-private';
const AI_MAX_UPLOAD_BYTES = 20 * 1024 * 1024;   // PDFs up to 20 MB (the API takes up to 32 MB per request)
const AI_MAX_TEXT_CHARS = 400000;               // pasted text / .txt
const AI_MAX_QUESTIONS = 99;                    // the answer device shows 2 digits
const AI_UPLOAD_TTL_SECONDS = 86400;
const AI_LOCAL_MAX_DOC_CHARS = 60000;           // ~17k tokens of Vietnamese; the local model has 32k of context

/** The raw ai_config.php array, or null when it is missing. */
function aiQuizConfigFile(): ?array
{
    $file = AI_PRIVATE_DIR . '/ai_config.php';
    $loaded = is_readable($file) ? require $file : null;

    return is_array($loaded) ? $loaded : null;
}

/** null when the feature is not set up yet; otherwise the settings with defaults. */
function aiQuizConfig(): ?array
{
    static $config = false;

    if ($config !== false) {
        return $config;
    }

    $loaded = aiQuizConfigFile();
    $provider = ($loaded['provider'] ?? 'claude') === 'local' ? 'local' : 'claude';

    if ($loaded === null || ($provider === 'claude' && (trim((string) ($loaded['api_key'] ?? '')) === ''
        || !is_readable(AI_PRIVATE_DIR . '/vendor/autoload.php')))) {
        return $config = null;
    }

    return $config = [
        'provider' => $provider,
        'api_key' => trim((string) ($loaded['api_key'] ?? '')),
        'model' => (string) ($loaded['model'] ?? 'claude-opus-5-5'),
        'effort' => (string) ($loaded['effort'] ?? 'high'),
        'daily_limit' => (int) ($loaded['daily_limit'] ?? 40),
        'fallback' => (bool) ($loaded['fallback'] ?? true),
        'base_url' => isset($loaded['base_url']) ? (string) $loaded['base_url'] : null,
        'local_url' => rtrim((string) ($loaded['local_url'] ?? 'http://127.0.0.1:8081'), '/'),
        'local_model' => (string) ($loaded['local_model'] ?? 'qwen3-4b'),
    ];
}

/** What is missing, for the setup notice. */
function aiQuizSetupProblems(): array
{
    $problems = [];

    if (!is_dir(AI_PRIVATE_DIR)) {
        $problems[] = 'Folder ' . AI_PRIVATE_DIR . ' does not exist.';
        return $problems;
    }

    $loaded = aiQuizConfigFile();

    if ($loaded === null) {
        $problems[] = 'The settings file ' . AI_PRIVATE_DIR . '/ai_config.php is missing.';
    } elseif (($loaded['provider'] ?? 'claude') !== 'local') {
        if (!is_readable(AI_PRIVATE_DIR . '/vendor/autoload.php')) {
            $problems[] = 'The Anthropic PHP SDK is not installed in ' . AI_PRIVATE_DIR . '/vendor.';
        }
        if (trim((string) ($loaded['api_key'] ?? '')) === '') {
            $problems[] = 'The API key in ' . AI_PRIVATE_DIR . "/ai_config.php is still empty (or set 'provider' => 'local' to use the AI on this computer).";
        }
    }
    if (!is_writable(AI_PRIVATE_DIR . '/ai-uploads')) {
        $problems[] = 'The web server cannot write to ' . AI_PRIVATE_DIR . '/ai-uploads.';
    }

    return $problems;
}

/* ------------------------------------------------------------------
 * Documents
 * ---------------------------------------------------------------- */

function aiQuizUploadDir(): string
{
    return AI_PRIVATE_DIR . '/ai-uploads';
}

/** Remove documents left behind by old drafts. */
function aiQuizCleanUploads(): void
{
    foreach (glob(aiQuizUploadDir() . '/*') ?: [] as $path) {
        if (is_file($path) && filemtime($path) < time() - AI_UPLOAD_TTL_SECONDS) {
            @unlink($path);
        }
    }
}

/**
 * Store an uploaded file as PDF or plain text for the model.
 * Word, PowerPoint and OpenDocument files are converted to PDF with LibreOffice.
 *
 * @return array{file: string, name: string, kind: 'pdf'|'text'}
 */
function aiQuizStoreUpload(array $upload): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new InvalidArgumentException(($upload['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($upload['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
            ? 'The file is larger than the server accepts (' . ini_get('upload_max_filesize') . ').'
            : 'The file upload failed. Please choose the file again.');
    }
    if ((int) $upload['size'] > AI_MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException('The file is larger than 20 MB.');
    }

    $original = basename((string) $upload['name']);
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $id = bin2hex(random_bytes(12));
    $dir = aiQuizUploadDir();

    if ($extension === 'pdf' && $mime === 'application/pdf') {
        $target = $dir . '/' . $id . '.pdf';
        if (!move_uploaded_file($upload['tmp_name'], $target)) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
        return ['file' => basename($target), 'name' => $original, 'kind' => 'pdf'];
    }

    if (in_array($extension, ['txt', 'md'], true) && str_starts_with($mime, 'text/')) {
        $text = (string) file_get_contents($upload['tmp_name']);
        return aiQuizStoreText($text, $original);
    }

    if (in_array($extension, ['docx', 'doc', 'odt', 'rtf', 'pptx', 'ppt', 'odp'], true)) {
        $source = $dir . '/' . $id . '.' . $extension;
        if (!move_uploaded_file($upload['tmp_name'], $source)) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
        $pdf = aiQuizConvertToPdf($source);
        @unlink($source);
        return ['file' => basename($pdf), 'name' => $original, 'kind' => 'pdf'];
    }

    throw new InvalidArgumentException('Use a PDF, Word (.docx/.doc), PowerPoint (.pptx), OpenDocument or .txt file.');
}

/** @return array{file: string, name: string, kind: 'text'} */
function aiQuizStoreText(string $text, string $name): array
{
    $text = trim(str_replace("\r\n", "\n", $text));

    if ($text === '' || !preg_match('//u', $text)) {
        throw new InvalidArgumentException('The text is empty or is not UTF-8.');
    }
    if (iconv_strlen($text, 'UTF-8') > AI_MAX_TEXT_CHARS) {
        throw new InvalidArgumentException('The text is too long (over ' . number_format(AI_MAX_TEXT_CHARS) . ' characters). Split it into parts.');
    }

    $target = aiQuizUploadDir() . '/' . bin2hex(random_bytes(12)) . '.txt';
    if (file_put_contents($target, $text) === false) {
        throw new RuntimeException('Could not store the text.');
    }

    return ['file' => basename($target), 'name' => $name, 'kind' => 'text'];
}

function aiQuizConvertToPdf(string $source): string
{
    $dir = dirname($source);
    // LibreOffice needs a writable HOME for its profile; the web server user has none.
    $command = 'HOME=' . escapeshellarg($dir) . ' timeout 90 soffice --headless --norestore --convert-to pdf --outdir '
        . escapeshellarg($dir) . ' ' . escapeshellarg($source) . ' 2>&1';
    exec($command, $output, $status);
    $pdf = $dir . '/' . pathinfo($source, PATHINFO_FILENAME) . '.pdf';

    if ($status !== 0 || !is_file($pdf)) {
        error_log('AI quiz: LibreOffice conversion failed (' . $status . '): ' . implode(' ', $output));
        throw new RuntimeException('Could not read this Word/PowerPoint file. Save it as PDF and upload the PDF.');
    }

    return $pdf;
}

/** Path of a stored document, refusing anything that is not one of ours. */
function aiQuizDocumentPath(array $doc): string
{
    $file = (string) ($doc['file'] ?? '');

    if (!preg_match('/^[a-f0-9]{24}\.(pdf|txt)$/', $file) || !is_file(aiQuizUploadDir() . '/' . $file)) {
        throw new InvalidArgumentException('The document is no longer available. Upload it again.');
    }

    return aiQuizUploadDir() . '/' . $file;
}

/* ------------------------------------------------------------------
 * Daily limit
 * ---------------------------------------------------------------- */

/** Count one AI request for the teacher; false when today's limit is reached. $bucket: 'quiz' or 'chat'. */
function aiQuizTakeQuota(int $teacherId, int $limit, string $bucket = 'quiz'): bool
{
    $handle = fopen(aiQuizUploadDir() . '/.usage.json', 'c+');
    if ($handle === false) {
        return true; // no bookkeeping possible: do not block the teacher
    }

    flock($handle, LOCK_EX);
    $usage = json_decode((string) stream_get_contents($handle), true);
    $today = date('Y-m-d');
    $usage = is_array($usage) && ($usage['date'] ?? '') === $today ? $usage : ['date' => $today, 'teachers' => []];
    $key = $bucket === 'quiz' ? (string) $teacherId : $bucket . ':' . $teacherId;
    $used = (int) ($usage['teachers'][$key] ?? 0);
    $allowed = $used < $limit;

    if ($allowed) {
        $usage['teachers'][$key] = $used + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($usage));
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $allowed;
}

/* ------------------------------------------------------------------
 * Talking to Claude
 * ---------------------------------------------------------------- */

const AI_LANGUAGES = ['vi' => 'Vietnamese', 'en' => 'English'];
const AI_LEVELS = ['mixed' => 'a mix of easy, medium and hard', 'easy' => 'easy', 'medium' => 'medium', 'hard' => 'hard'];

function aiQuizSystemPrompt(string $language): string
{
    return <<<PROMPT
You write multiple-choice questions for a live classroom quiz. Students answer on a keypad with the buttons A, B, C and D, and the questions are shown on a projector.

Rules for every question:
- Base it on the attached document. Do not ask about facts the document does not support.
- 2 to 4 options and exactly one correct option. Wrong options should be believable to a student who did not understand the material, and clearly wrong to one who did. If two options could both be defended from the document, rewrite one of them.
- Prefer questions that check understanding and application over recalling the exact wording.
- Keep the question under about 300 characters and each option under about 120 characters, so they read well on a projector. No "all of the above" or "none of the above".
- Write formulas as plain text (I = U/R), never LaTeX or $ signs. Use only the requested language, never Chinese characters.
- Spread the correct answer over A, B, C and D.
- `explanation`: one sentence on why the answer is correct. `source`: where in the document it comes from (page, section or heading).

The teacher may send the current set of questions with a change request. Then apply the request and return the whole updated set, keeping every question the teacher did not ask to change exactly as it was. In `reply`, tell the teacher briefly what you did.

Write the questions, options, explanations, title and reply in {$language}.
PROMPT;
}

function aiQuizSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string', 'description' => 'Short quiz title based on the document topic'],
            'reply' => ['type' => 'string', 'description' => 'One or two sentences to the teacher about what was done'],
            'questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '2 to 4 options, in order A, B, C, D'],
                        'correct' => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D']],
                        'explanation' => ['type' => 'string'],
                        'source' => ['type' => 'string'],
                    ],
                    'required' => ['question', 'options', 'correct', 'explanation', 'source'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['title', 'reply', 'questions'],
        'additionalProperties' => false,
    ];
}

/**
 * Ask the AI for a new set, or for changes to the current one.
 *
 * Every request is self-contained (document + current set + request), so
 * there is no conversation history to keep in step. With Claude the document
 * is marked for prompt caching so follow-up requests on it cost less.
 *
 * @return array{title: string, reply: string, questions: list<array>, dropped: int}
 */
function aiQuizAsk(array $config, array $doc, array $settings, array $currentQuestions, string $request): array
{
    $language = AI_LANGUAGES[$settings['language'] ?? 'vi'] ?? 'Vietnamese';
    $level = AI_LEVELS[$settings['level'] ?? 'mixed'] ?? AI_LEVELS['mixed'];
    $count = max(1, min(30, (int) ($settings['count'] ?? 10)));

    if (empty($currentQuestions)) {
        $instruction = "Write {$count} questions, difficulty: {$level}."
            . ($request !== '' ? "\n\nThe teacher adds: {$request}" : '');
    } else {
        $instruction = "Current questions:\n" . json_encode(array_values($currentQuestions), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nThe teacher's change request: {$request}";
    }

    $json = $config['provider'] === 'local'
        ? aiQuizAskLocal($config, $doc, $language, $instruction)
        : aiQuizAskClaude($config, $doc, $language, $instruction);

    if (!is_array($json) || !is_array($json['questions'] ?? null)) {
        throw new RuntimeException('The AI answer could not be read. Please try again.');
    }

    $questions = aiQuizNormalizeQuestions($json['questions']);

    // The small local model puts most correct answers on A or B; reshuffle a fresh set.
    // (Not on change requests: the teacher's kept questions must stay as they were.)
    if ($config['provider'] === 'local' && empty($currentQuestions)) {
        $questions = array_map('aiQuizShuffleOptions', $questions);
    }

    return [
        'title' => aiQuizClean((string) ($json['title'] ?? ''), 255),
        'reply' => aiQuizClean((string) ($json['reply'] ?? ''), 1000),
        'questions' => $questions,
        'dropped' => count($json['questions']) - count($questions),
    ];
}

/** Claude reads the PDF (text, pictures and tables) itself. Returns the decoded JSON answer. */
function aiQuizAskClaude(array $config, array $doc, string $language, string $instruction): ?array
{
    require_once AI_PRIVATE_DIR . '/vendor/autoload.php';

    $path = aiQuizDocumentPath($doc);
    $source = $doc['kind'] === 'pdf'
        ? ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($path))]
        : ['type' => 'text', 'mediaType' => 'text/plain', 'data' => (string) file_get_contents($path)];

    $client = new \Anthropic\Client(apiKey: $config['api_key'], baseUrl: $config['base_url']);

    // With 'fallback' on, a request the model declines for policy reasons is retried
    // by the API on its default fallback model; off, the model in the config is the only one used.
    $fallback = $config['fallback']
        ? ['fallbacks' => 'default', 'betas' => ['server-side-fallback-2026-07-01']]
        : [];

    $message = $client->beta->messages->create(
        ...$fallback,
        model: $config['model'],
        maxTokens: 16000,
        system: aiQuizSystemPrompt($language),
        messages: [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'document',
                    'source' => $source,
                    'title' => (string) $doc['name'],
                    'cacheControl' => ['type' => 'ephemeral'],
                ],
                ['type' => 'text', 'text' => $instruction],
            ],
        ]],
        outputConfig: [
            'effort' => $config['effort'],
            'format' => ['type' => 'json_schema', 'schema' => aiQuizSchema()],
        ],
    );

    if ($message->stopReason === 'refusal') {
        throw new RuntimeException('The AI declined to write questions from this document.');
    }
    if ($message->stopReason === 'max_tokens') {
        throw new RuntimeException('The answer was too long. Ask for fewer questions at a time.');
    }

    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            return json_decode($block->text, true);
        }
    }

    return null;
}

/** The text of a stored document; PDFs go through pdftotext (pictures and scanned pages are lost). */
function aiQuizDocumentText(array $doc): string
{
    $path = aiQuizDocumentPath($doc);

    if ($doc['kind'] !== 'pdf') {
        return (string) file_get_contents($path);
    }

    exec('timeout 60 pdftotext -enc UTF-8 -nopgbrk ' . escapeshellarg($path) . ' - 2>/dev/null', $lines, $status);
    $text = trim(implode("\n", $lines));

    if ($status !== 0) {
        throw new RuntimeException('Could not read the text of this PDF.');
    }
    if (iconv_strlen($text, 'UTF-8') < 40) {
        throw new RuntimeException('This PDF has almost no text (a scan or pictures?). The AI on this computer can only read text: paste the text instead.');
    }

    return $text;
}

/**
 * The model on this computer (llama.cpp server, OpenAI-style API) gets the
 * document as text; its answer is held to the same JSON schema by the server.
 */
function aiQuizAskLocal(array $config, array $doc, string $language, string $instruction): ?array
{
    $text = aiQuizDocumentText($doc);
    $length = iconv_strlen($text, 'UTF-8');

    // Never cut the document silently: the questions would quietly skip the rest.
    if ($length > AI_LOCAL_MAX_DOC_CHARS) {
        throw new RuntimeException('The material is too long for the AI on this computer ('
            . number_format($length) . ' characters, at most ' . number_format(AI_LOCAL_MAX_DOC_CHARS)
            . ', about 20-25 pages). Paste only the chapter you want questions about.');
    }

    $body = [
        'model' => $config['local_model'],
        'temperature' => 0.6,
        'max_tokens' => 8000,
        // Qwen3 can think aloud first; off is several times faster and keeps the answer pure JSON.
        'chat_template_kwargs' => ['enable_thinking' => false],
        'messages' => [
            ['role' => 'system', 'content' => aiQuizSystemPrompt($language)],
            ['role' => 'user', 'content' => '<document title="' . htmlspecialchars((string) $doc['name'], ENT_QUOTES) . "\">\n"
                . $text . "\n</document>\n\n" . $instruction],
        ],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => ['name' => 'quiz', 'schema' => aiQuizSchema()],
        ],
    ];

    $curl = curl_init($config['local_url'] . '/v1/chat/completions');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 280,
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        error_log('AI quiz local model unreachable: ' . $error);
        throw new RuntimeException(str_contains($error, 'timed out')
            ? 'The AI on this computer took too long. Ask for fewer questions at a time.'
            : 'The AI on this computer is not running. Start it with ~/llm/start.sh (or: systemctl --user start stem-quiz-llm).');
    }

    $reply = json_decode((string) $raw, true);

    if ($status !== 200) {
        error_log('AI quiz local model error ' . $status . ': ' . substr((string) $raw, 0, 500));
        throw new RuntimeException('The AI on this computer could not answer: ' . ($reply['error']['message'] ?? 'error ' . $status) . '.');
    }
    if (($reply['choices'][0]['finish_reason'] ?? '') === 'length') {
        throw new RuntimeException('The answer was too long. Ask for fewer questions at a time.');
    }

    return json_decode((string) ($reply['choices'][0]['message']['content'] ?? ''), true);
}

/** Put a question's options in random order, keeping track of the correct one. */
function aiQuizShuffleOptions(array $question): array
{
    // An option that points at others ("A and B", "both C and D") only makes sense in place.
    foreach ($question['options'] as $option) {
        if (preg_match('/\b[A-D]\b/u', $option)) {
            return $question;
        }
    }

    $correctText = $question['options'][array_search($question['correct'], ['A', 'B', 'C', 'D'], true)];
    $options = $question['options'];

    for ($i = count($options) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$options[$i], $options[$j]] = [$options[$j], $options[$i]];
    }

    $question['options'] = $options;
    $question['correct'] = ['A', 'B', 'C', 'D'][array_search($correctText, $options, true)];

    return $question;
}

function aiQuizClean(string $text, int $max): string
{
    // The small local model now and then slips into Chinese mid-sentence; questions are Vietnamese or English.
    $text = preg_replace('/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]+/u', '', $text) ?? $text;
    $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? '');

    return iconv_strlen($text, 'UTF-8') > $max ? iconv_substr($text, 0, $max, 'UTF-8') : $text;
}

/**
 * Keep only questions the quiz can hold: text, 2-4 non-empty options and a
 * correct letter that points at one of them. Used on AI answers and on the
 * teacher's edited form alike.
 *
 * @return list<array{question: string, options: list<string>, correct: string, explanation: string, source: string}>
 */
function aiQuizNormalizeQuestions(array $raw): array
{
    $questions = [];

    foreach ($raw as $item) {
        if (!is_array($item) || count($questions) >= AI_MAX_QUESTIONS) {
            continue;
        }

        $question = aiQuizClean((string) ($item['question'] ?? ''), 2000);
        // Models sometimes write the letter into the option ("A. Màng kép"); the page adds letters itself.
        $options = array_values(array_filter(
            array_map(
                static fn ($option) => aiQuizClean(preg_replace('/^\s*[A-D]\s*[.):]\s+/u', '', (string) $option) ?? '', 500),
                is_array($item['options'] ?? null) ? $item['options'] : []
            ),
            static fn ($option) => $option !== ''
        ));
        $correct = strtoupper(trim((string) ($item['correct'] ?? '')));
        $correctIndex = array_search($correct, ['A', 'B', 'C', 'D'], true);

        if ($question === '' || count($options) < 2 || count($options) > 4
            || $correctIndex === false || $correctIndex >= count($options)) {
            continue;
        }

        $questions[] = [
            'question' => $question,
            'options' => $options,
            'correct' => $correct,
            'explanation' => aiQuizClean((string) ($item['explanation'] ?? ''), 1000),
            'source' => aiQuizClean((string) ($item['source'] ?? ''), 200),
        ];
    }

    return $questions;
}

/* ------------------------------------------------------------------
 * Saving
 * ---------------------------------------------------------------- */

/**
 * Save the reviewed questions as a new quiz, optionally assigned to classes.
 * One transaction: either the whole quiz is saved or nothing is.
 */
function aiQuizSave(mysqli $conn, int $teacherId, string $title, array $questions, array $classIds): int
{
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare('INSERT INTO quizzes (teacher_id, title) VALUES (?, ?)');
        $stmt->bind_param('is', $teacherId, $title);
        $stmt->execute();
        $quizId = (int) $conn->insert_id;

        $questionStmt = $conn->prepare('INSERT INTO questions (quiz_id, question_order, content) VALUES (?, ?, ?)');
        $optionStmt = $conn->prepare(
            'INSERT INTO question_options (question_id, option_order, content, is_correct) VALUES (?, ?, ?, ?)'
        );

        foreach (array_values($questions) as $index => $question) {
            $order = $index + 1;
            $questionStmt->bind_param('iis', $quizId, $order, $question['question']);
            $questionStmt->execute();
            $questionId = (int) $conn->insert_id;

            foreach ($question['options'] as $optionIndex => $content) {
                $optionOrder = $optionIndex + 1;
                $isCorrect = ['A', 'B', 'C', 'D'][$optionIndex] === $question['correct'] ? 1 : 0;
                $optionStmt->bind_param('iisi', $questionId, $optionOrder, $content, $isCorrect);
                $optionStmt->execute();
            }
        }

        if (!empty($classIds)) {
            $ownStmt = $conn->prepare('SELECT class_id FROM classes WHERE class_id = ? AND teacher_id = ?');
            $assignStmt = $conn->prepare('INSERT INTO class_quizzes (class_id, quiz_id) VALUES (?, ?)');

            foreach (array_unique(array_map('intval', $classIds)) as $classId) {
                $ownStmt->bind_param('ii', $classId, $teacherId);
                $ownStmt->execute();
                if ($ownStmt->get_result()->num_rows === 1) {
                    $assignStmt->bind_param('ii', $classId, $quizId);
                    $assignStmt->execute();
                }
            }
        }

        $conn->commit();
        return $quizId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
