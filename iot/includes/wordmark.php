<?php
/*
 * The product name with its mark. Set $wordmark_href before including this
 * file to make it a link, and $wordmark_class to add classes.
 */
$wordmark_classes = trim('wordmark ' . ($wordmark_class ?? ''));
$wordmark_tag = !empty($wordmark_href) ? 'a' : 'span';
?>
<<?= $wordmark_tag ?>
    class="<?= htmlspecialchars($wordmark_classes, ENT_QUOTES, 'UTF-8') ?>"
    <?= !empty($wordmark_href) ? 'href="' . htmlspecialchars($wordmark_href, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
>
    <span class="wordmark-mark" aria-hidden="true"><i class="ti ti-atom-2"></i></span>
    STEM Quiz
</<?= $wordmark_tag ?>>
