<?php
/*
 * The assistant window's endpoint: the browser posts the conversation, the
 * answer comes back as Server-Sent Events while the AI writes it.
 *
 * In:  {csrf_token, page, messages: [{role: "user"|"assistant", content}]}
 * Out: event stream of  data: {"text": "..."}  pieces, then  data: {"done": true}
 *      or  data: {"error": "message for the teacher"}
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/security.php';
stemSessionStart();

require_once __DIR__ . '/../includes/ai_chat.php';

function aiChatEvent(array $payload): void
{
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');

if (empty($_SESSION['teacher_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    aiChatEvent(['error' => 'Phiên đăng nhập đã hết, hãy đăng nhập lại.']);
    exit;
}

$teacher_id = (int) $_SESSION['teacher_id'];
$input = json_decode((string) file_get_contents('php://input'), true);
$token = is_array($input) ? ($input['csrf_token'] ?? '') : '';
$expected = $_SESSION['ai_chat_csrf_token'] ?? '';

// The answer may take a while; do not hold the session lock meanwhile.
session_write_close();

if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
    aiChatEvent(['error' => 'Trang đã mở quá lâu, hãy tải lại trang.']);
    exit;
}

$config = aiQuizConfig();
$messages = aiChatMessages($input['messages'] ?? null);

if ($config === null) {
    aiChatEvent(['error' => 'Trợ lý AI chưa được cài đặt.']);
    exit;
}
if (empty($messages)) {
    aiChatEvent(['error' => 'Hãy nhập tin nhắn trước.']);
    exit;
}
if ($config['provider'] === 'claude' && !aiQuizTakeQuota($teacher_id, $config['daily_limit'] * 5, 'chat')) {
    aiChatEvent(['error' => 'Đã dùng hết lượt AI hôm nay, hãy thử lại vào ngày mai.']);
    exit;
}

while (ob_get_level() > 0) {
    ob_end_flush();
}
set_time_limit(300);

$page = aiQuizClean(preg_replace('/[^\pL\pN .:\/_?=&-]/u', '', (string) ($input['page'] ?? '')) ?? '', 120);

try {
    aiChatStream($config, aiChatSystemPrompt($page), $messages, static function (string $piece): void {
        aiChatEvent(['text' => $piece]);
    });
    aiChatEvent(['done' => true]);
} catch (\Anthropic\Core\Exceptions\APIStatusException $exception) {
    error_log('AI chat API error: ' . $exception->getMessage());
    $detail = (string) ($exception->body['error']['message'] ?? '');
    aiChatEvent(['error' => str_contains($detail, 'credit balance')
        ? 'Tài khoản Anthropic đã hết tiền (credit).'
        : 'Dịch vụ AI không trả lời được, hãy thử lại.']);
} catch (RuntimeException $exception) {
    aiChatEvent(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('AI chat error: ' . get_class($exception) . ': ' . $exception->getMessage());
    aiChatEvent(['error' => 'AI không trả lời được, hãy thử lại.']);
}
