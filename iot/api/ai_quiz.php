<?php
/*
 * Ask the AI to write questions from the material in the teacher's AI Quiz
 * draft, or to change the current set. JSON in, JSON out; used by ai_quiz.php.
 *
 * In:  {csrf_token, request, questions: [...current set as edited...], settings: {count, level, language}}
 * Out: {status: "OK", title, reply, questions, dropped} or {status: "ERROR", message}
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/security.php';
stemSessionStart();

function aiQuizReply(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['teacher_id'])) {
    aiQuizReply(401, ['status' => 'ERROR', 'message' => 'Please sign in again.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aiQuizReply(405, ['status' => 'ERROR', 'message' => 'POST only.']);
}

require_once __DIR__ . '/../includes/ai_quiz.php';

$teacher_id = (int) $_SESSION['teacher_id'];
$input = json_decode((string) file_get_contents('php://input'), true);
$token = is_array($input) ? ($input['csrf_token'] ?? '') : '';
$expected = $_SESSION['ai_quiz_csrf_token'] ?? '';

if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
    aiQuizReply(403, ['status' => 'ERROR', 'message' => 'Your page session expired. Reload the page.']);
}

$config = aiQuizConfig();
$draft = $_SESSION['ai_quiz_draft'] ?? null;

if ($config === null) {
    aiQuizReply(503, ['status' => 'ERROR', 'message' => 'The AI is not set up yet.']);
}
if (!is_array($draft) || !is_array($draft['doc'] ?? null)) {
    aiQuizReply(409, ['status' => 'ERROR', 'message' => 'Upload the material first.']);
}

$request = aiQuizClean((string) ($input['request'] ?? ''), 2000);
$current = aiQuizNormalizeQuestions(is_array($input['questions'] ?? null) ? $input['questions'] : []);
$settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];

if (!empty($current) && $request === '') {
    aiQuizReply(422, ['status' => 'ERROR', 'message' => 'Write what should change first.']);
}

// The session is not needed while waiting for the AI; release it so other pages keep working.
session_write_close();

// The daily limit guards the API bill; the model on this computer costs nothing.
if ($config['provider'] === 'claude' && !aiQuizTakeQuota($teacher_id, $config['daily_limit'])) {
    aiQuizReply(429, ['status' => 'ERROR', 'message' => 'You have used today\'s AI limit (' . $config['daily_limit'] . ' requests). Try again tomorrow.']);
}

set_time_limit(300);

try {
    $result = aiQuizAsk($config, $draft['doc'], $settings, $current, $request);
} catch (\Anthropic\Core\Exceptions\AuthenticationException|\Anthropic\Core\Exceptions\PermissionDeniedException $exception) {
    error_log('AI quiz: API key rejected: ' . $exception->getMessage());
    aiQuizReply(502, ['status' => 'ERROR', 'message' => 'The AI API key is not valid. Ask the administrator to check it.']);
} catch (\Anthropic\Core\Exceptions\NotFoundException $exception) {
    error_log('AI quiz: model not found: ' . $exception->getMessage());
    aiQuizReply(502, ['status' => 'ERROR', 'message' => 'The AI model "' . $config['model'] . '" does not exist or this API key cannot use it. Check \'model\' in ai_config.php.']);
} catch (\Anthropic\Core\Exceptions\RateLimitException $exception) {
    error_log('AI quiz: rate limited: ' . $exception->getMessage());
    aiQuizReply(502, ['status' => 'ERROR', 'message' => 'The AI service is busy (rate limit). Wait a minute and try again.']);
} catch (\Anthropic\Core\Exceptions\BadRequestException $exception) {
    error_log('AI quiz: request rejected: ' . $exception->getMessage());
    // The API says why (no credit left, PDF too large or protected, ...): show its own words.
    $detail = (string) ($exception->body['error']['message'] ?? '');
    $message = str_contains($detail, 'credit balance')
        ? 'The Anthropic account has no credit left. Add credit at console.anthropic.com → Plans & Billing, then try again.'
        : 'The AI rejected the request' . ($detail !== '' ? ': ' . $detail : '.') . ' Try a smaller file or paste the text.';
    aiQuizReply(502, ['status' => 'ERROR', 'message' => $message]);
} catch (\Anthropic\Core\Exceptions\APIConnectionException|\Anthropic\Core\Exceptions\APIStatusException $exception) {
    // Overloaded, server errors, timeouts, no Internet: the SDK has already retried.
    error_log('AI quiz: API unavailable: ' . get_class($exception) . ': ' . $exception->getMessage());
    aiQuizReply(502, ['status' => 'ERROR', 'message' => 'The AI service did not answer. Check the Internet connection and try again.']);
} catch (InvalidArgumentException|RuntimeException $exception) {
    // Our own checks (document gone, refusal, unreadable answer): the message is meant for the teacher.
    error_log('AI quiz for teacher ' . $teacher_id . ': ' . $exception->getMessage());
    aiQuizReply(502, ['status' => 'ERROR', 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('AI quiz unexpected error: ' . get_class($exception) . ': ' . $exception->getMessage());
    aiQuizReply(500, ['status' => 'ERROR', 'message' => 'Something went wrong. Please try again.']);
}

// Keep the new set in the draft so it survives a reload.
require_once __DIR__ . '/../includes/security.php';
stemSessionStart();
if (isset($_SESSION['ai_quiz_draft'])) {
    $_SESSION['ai_quiz_draft']['questions'] = $result['questions'];
    $_SESSION['ai_quiz_draft']['title'] = $_SESSION['ai_quiz_draft']['title'] ?: $result['title'];
    if ($request !== '') {
        $_SESSION['ai_quiz_draft']['chat'][] = ['who' => 'teacher', 'text' => $request];
    }
    $_SESSION['ai_quiz_draft']['chat'][] = ['who' => 'ai', 'text' => $result['reply'] ?: 'Done.'];
}

aiQuizReply(200, ['status' => 'OK'] + $result);
