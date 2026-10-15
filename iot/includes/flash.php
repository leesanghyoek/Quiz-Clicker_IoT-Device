<?php
$flash_messages = isset($flash_messages) && is_array($flash_messages)
    ? $flash_messages
    : [];
?>

<?php foreach ($flash_messages as $flash_message): ?>
    <?php
    $flash_type = isset($flash_message['type'])
        && $flash_message['type'] === 'error'
        ? 'error-message'
        : 'success-message';
    $flash_text = isset($flash_message['message'])
        ? (string) $flash_message['message']
        : '';
    ?>
    <?php if ($flash_text !== ''): ?>
        <div class="<?= $flash_type ?>" role="status">
            <?= htmlspecialchars($flash_text, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
