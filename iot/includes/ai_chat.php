<?php
/*
 * The teacher's AI assistant window (bottom-right on every teacher page).
 * It uses the same AI as AI Quiz (includes/ai_quiz.php: Claude or the model on
 * this computer) and streams its answer while it is being written.
 */
require_once __DIR__ . '/ai_quiz.php';

const AI_CHAT_MAX_MESSAGES = 20;        // the last 20 turns are sent; older ones drop off
const AI_CHAT_MAX_CHARS = 4000;         // per message

function aiChatSystemPrompt(string $page): string
{
    return <<<PROMPT
You are the assistant inside STEM Quiz, a live classroom quiz system a teacher runs on their laptop. Students answer on ESP32 keypads (buttons A-D) or on their phones; the teacher shows the quiz on a projector.

Help the teacher with: using STEM Quiz, writing and improving quiz questions, explaining topics, and making sense of class results. Answer in the teacher's language (usually Vietnamese), briefly and concretely, and use only that language: never Chinese characters. Use short paragraphs or lists; **bold** for key words is fine. Write formulas as plain text (I = U/R, 6CO2 + 6H2O → C6H12O6 + 6O2), never LaTeX or $ signs, because the chat window cannot show them.

What STEM Quiz can do, so you can point to it (do not invent other features):
- Dashboard: open or running quizzes ("Not ended yet"), continue or cancel them.
- Classes: classes and students (STT = 2-digit number typed on the device, MSSV, name); import a class list from CSV or pasted from Excel.
- Quizzes: create quizzes, questions with 2-4 options and one correct answer; import from CSV with the header Question,A,B,C,D,Correct; "Create with AI" writes questions from lesson material (PDF, Word, text); assign a quiz to classes.
- Running a quiz: Launch gives a 2-digit join code; students join on the device (STT, then join code); the teacher presses Start, Next Question, End Quiz.
- Results: score matrix per student and question, answer distribution per question.
- Progress: class score over time, students who need attention (average under 50% or attendance under 50%).
- Review: the questions a class misses most, turned into a review quiz.
- Devices: 2-digit device codes, lock/unlock, QR code for phones.
- Device codes on the keypad: E1 no server, E2 device not registered, E3 wrong join code, E4 quiz already started, E5 student not in class, E6 already joined, E7 already answered, E8 left the room. Pressing * twice switches the device between Wi-Fi and Bluetooth.

When the teacher asks for quiz questions, you may write them; to use them, suggest "Create with AI" or a CSV in the Question,A,B,C,D,Correct format for Import Quiz. You cannot see or change the teacher's data; say so if asked to.

The teacher is on the page: {$page}
PROMPT;
}

/** Clean the conversation sent by the browser: roles alternate, the last one is the teacher. */
function aiChatMessages(mixed $raw): array
{
    $messages = [];

    foreach (is_array($raw) ? array_slice($raw, -AI_CHAT_MAX_MESSAGES) : [] as $item) {
        $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = trim((string) ($item['content'] ?? ''));

        if ($text === '') {
            continue;
        }
        if (iconv_strlen($text, 'UTF-8') > AI_CHAT_MAX_CHARS) {
            $text = iconv_substr($text, 0, AI_CHAT_MAX_CHARS, 'UTF-8');
        }
        if (!empty($messages) && end($messages)['role'] === $role) {
            $messages[count($messages) - 1]['content'] .= "\n\n" . $text;
            continue;
        }
        $messages[] = ['role' => $role, 'content' => $text];
    }

    while (!empty($messages) && $messages[0]['role'] !== 'user') {
        array_shift($messages);
    }

    return !empty($messages) && end($messages)['role'] === 'user' ? $messages : [];
}

/**
 * Stream the answer: $emit is called with each new piece of text.
 * Throws RuntimeException with a message for the teacher when the AI cannot answer.
 */
function aiChatStream(array $config, string $system, array $messages, callable $emit): void
{
    if ($config['provider'] === 'local') {
        aiChatStreamLocal($config, $system, $messages, $emit);
    } else {
        aiChatStreamClaude($config, $system, $messages, $emit);
    }
}

function aiChatStreamClaude(array $config, string $system, array $messages, callable $emit): void
{
    require_once AI_PRIVATE_DIR . '/vendor/autoload.php';

    $client = new \Anthropic\Client(apiKey: $config['api_key'], baseUrl: $config['base_url']);
    $fallback = $config['fallback']
        ? ['fallbacks' => 'default', 'betas' => ['server-side-fallback-2026-07-01']]
        : [];

    $stream = $client->beta->messages->createStream(
        ...$fallback,
        model: $config['model'],
        maxTokens: 8000,
        system: $system,
        messages: $messages,
        // A chat answer does not need deep thinking; keep it quick.
        outputConfig: ['effort' => 'low'],
    );

    foreach ($stream as $event) {
        if ($event instanceof \Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent
            && $event->delta instanceof \Anthropic\Beta\Messages\BetaTextDelta) {
            $emit($event->delta->text);
        }
    }
}

function aiChatStreamLocal(array $config, string $system, array $messages, callable $emit): void
{
    $body = [
        'model' => $config['local_model'],
        'stream' => true,
        'temperature' => 0.7,
        'max_tokens' => 3000,
        'chat_template_kwargs' => ['enable_thinking' => false],
        'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
    ];

    // The server answers in Server-Sent Events; pass each text piece on as it arrives.
    $pending = '';
    $curl = curl_init($config['local_url'] . '/v1/chat/completions');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$pending, $emit): int {
            $pending .= $chunk;
            while (($end = strpos($pending, "\n")) !== false) {
                $line = trim(substr($pending, 0, $end));
                $pending = substr($pending, $end + 1);
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = json_decode(trim(substr($line, 5)), true);
                $piece = $data['choices'][0]['delta']['content'] ?? '';
                if (is_string($piece) && $piece !== '') {
                    $emit($piece);
                }
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($ok === false || $status !== 200) {
        error_log('AI chat local model: ' . ($error ?: 'HTTP ' . $status));
        throw new RuntimeException($ok === false && !str_contains($error, 'timed out')
            ? 'AI trên máy chưa chạy. Bật bằng lệnh: systemctl --user start stem-quiz-llm'
            : 'AI trên máy không trả lời được, hãy thử lại.');
    }
}
