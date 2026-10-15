<?php
/*
 * The AI assistant window: a button in the bottom-right corner of every
 * teacher page that opens a chat with the AI (assets/js/ai-chat.js, which
 * talks to api/ai_chat.php). Left out of the projector screens (lobby, quiz
 * run) and when the AI is not set up.
 */
require_once __DIR__ . '/ai_chat.php';

$ai_chat_config = aiQuizConfig();

if ($ai_chat_config !== null):
    if (empty($_SESSION['ai_chat_csrf_token'])) {
        $_SESSION['ai_chat_csrf_token'] = bin2hex(random_bytes(32));
    }

    $ai_chat_engine = $ai_chat_config['provider'] === 'local'
        ? 'Gemma 4 · chạy trên máy này'
        : 'Claude · ' . $ai_chat_config['model'];

    $ai_chat_suggestions = [
        ['ti-help-hexagon', 'Hướng dẫn', 'Làm sao để học sinh vào phòng bằng ESP32?'],
        ['ti-list-check', 'Soạn câu hỏi', 'Soạn 3 câu trắc nghiệm về định luật Ôm'],
        ['ti-alert-triangle', 'Mã lỗi', 'Thiết bị báo E6 là lỗi gì, xử lý thế nào?'],
        ['ti-bulb', 'Giải thích', 'Giải thích quang hợp thật dễ hiểu cho học sinh lớp 6'],
    ];
?>
<button type="button" class="ai-chat-launcher" id="ai-chat-launcher" aria-expanded="false" aria-controls="ai-chat-panel">
    <span class="ai-chat-mark" aria-hidden="true"><i class="ti ti-atom-2"></i></span>
    <span class="ai-chat-launcher-text">Hỏi trợ lý</span>
</button>

<section
    class="ai-chat-panel"
    id="ai-chat-panel"
    role="dialog"
    aria-labelledby="ai-chat-title"
    data-csrf="<?= htmlspecialchars($_SESSION['ai_chat_csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
    hidden
>
    <header class="ai-chat-head">
        <span class="ai-chat-mark ai-chat-mark-lg" aria-hidden="true"><i class="ti ti-atom-2"></i></span>
        <div class="ai-chat-who">
            <strong id="ai-chat-title">Trợ lý STEM</strong>
            <small><span class="ai-chat-online" aria-hidden="true"></span><?= htmlspecialchars($ai_chat_engine, ENT_QUOTES, 'UTF-8') ?></small>
        </div>
        <button type="button" class="ai-chat-icon" id="ai-chat-clear" title="Cuộc trò chuyện mới" aria-label="Cuộc trò chuyện mới">
            <i class="ti ti-message-plus" aria-hidden="true"></i>
        </button>
        <button type="button" class="ai-chat-icon ai-chat-only-wide" id="ai-chat-wide" title="Phóng to" aria-label="Phóng to" aria-pressed="false">
            <i class="ti ti-arrows-diagonal" aria-hidden="true"></i>
        </button>
        <button type="button" class="ai-chat-icon" id="ai-chat-close" title="Đóng (Esc)" aria-label="Đóng">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    </header>

    <div class="ai-chat-body" id="ai-chat-body">
        <div class="ai-chat-welcome" id="ai-chat-welcome">
            <span class="ai-chat-mark ai-chat-mark-xl" aria-hidden="true"><i class="ti ti-atom-2"></i></span>
            <h2>Chào thầy cô! <mark>Mình giúp được gì?</mark></h2>
            <p>Hỏi cách dùng STEM Quiz, nhờ soạn câu hỏi, hoặc giải thích một bài học.</p>
            <div class="ai-chat-suggestions">
                <?php foreach ($ai_chat_suggestions as [$icon, $label, $prompt]): ?>
                    <button type="button" data-prompt="<?= htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="ti <?= $icon ?>" aria-hidden="true"></i>
                        <span><small><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></small><?= htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <ol class="ai-chat-log" id="ai-chat-log" aria-live="polite"></ol>
    </div>

    <button type="button" class="ai-chat-jump" id="ai-chat-jump" hidden>
        <i class="ti ti-arrow-down" aria-hidden="true"></i> Tin mới
    </button>

    <form class="ai-chat-form" id="ai-chat-form">
        <div class="ai-chat-field">
            <textarea id="ai-chat-input" rows="1" maxlength="4000" placeholder="Nhắn cho trợ lý…" aria-label="Tin nhắn"></textarea>
            <button type="submit" class="ai-chat-send" id="ai-chat-send" aria-label="Gửi" title="Gửi (Enter)">
                <i class="ti ti-arrow-up" aria-hidden="true"></i>
            </button>
        </div>
        <p class="ai-chat-note">Enter để gửi · Shift+Enter xuống dòng · AI có thể sai, hãy kiểm tra lại.</p>
    </form>
</section>
<script src="assets/js/ai-chat.js?v=20261014-6"></script>
<?php endif; ?>
