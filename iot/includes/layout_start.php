<?php
$page_title = isset($page_title) && is_string($page_title)
    ? $page_title
    : 'STEM Quiz';
$page_styles = isset($page_styles) && is_array($page_styles)
    ? $page_styles
    : [];
$layout_body_class = isset($layout_body_class) && is_string($layout_body_class)
    ? trim($layout_body_class)
    : '';
$layout_show_navigation = !isset($layout_show_navigation)
    || $layout_show_navigation !== false;

require_once __DIR__ . '/places.php';

$body_classes = trim(
    $layout_body_class
    . ($layout_show_navigation ? ' has-horizon' : ' no-horizon')
    . ' page-' . preg_replace('/[^a-z0-9_-]/', '', basename($current_page, '.php'))
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Alegreya:ital,wght@0,400..800;1,400..800&family=Alegreya+Sans:ital,wght@0,400;0,500;0,700;1,400&family=Lexend:wght@300..800&display=swap"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/@tabler/core@1.6.1/dist/css/tabler.min.css"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.49.0/dist/tabler-icons.min.css"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.21/dist/sweetalert2.min.css"
    >
    <link rel="stylesheet" href="assets/css/dashboard.css?v=20261005-2">
    <?php foreach ($page_styles as $page_style): ?>
        <link
            rel="stylesheet"
            href="<?= htmlspecialchars($page_style, ENT_QUOTES, 'UTF-8') ?>"
        >
    <?php endforeach; ?>
    <link rel="stylesheet" href="assets/css/theme.css?v=20261008-6">
    <link rel="stylesheet" href="assets/css/world.css?v=20261014-chat-2">
    <script src="assets/js/world-style-vangogh.js?v=20261012-stem-1"></script>
    <script src="assets/js/world-style-anime.js?v=20261012-stem-1"></script>
    <script src="assets/js/world.js?v=20261012-stem-2"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/three@0.149.0/build/three.min.js"></script>
    <script defer src="assets/js/stem-world.js?v=20261014-layer-2"></script>
</head>
<body
    class="<?= htmlspecialchars($body_classes, ENT_QUOTES, 'UTF-8') ?>"
    data-place="<?= htmlspecialchars((string) $current_place, ENT_QUOTES, 'UTF-8') ?>"
>
<script>
    // STEM style: show the scene as the previous page left it until this page's
    // own scene draws (assets/js/stem-world.js), so the objects never blink out.
    (function () {
        try {
            if (document.documentElement.dataset.worldStyle !== 'stem') {
                return;
            }
            var shot = JSON.parse(sessionStorage.getItem('ar-stem-snapshot') || 'null');

            if (!shot || shot.w !== window.innerWidth || shot.h !== window.innerHeight
                || Date.now() - shot.at > 15000 || !/^data:image\/(webp|png);base64,[A-Za-z0-9+\/=]+$/.test(shot.url)) {
                return;
            }
            var picture = document.createElement('div');

            picture.id = 'world-snapshot';
            picture.className = 'world-snapshot';
            picture.setAttribute('aria-hidden', 'true');
            picture.style.backgroundImage = 'url("' + shot.url + '")';
            document.body.appendChild(picture);
        } catch (error) {
            // Nothing to show: the scene fades in by itself.
        }
    })();
</script>

<?php if ($layout_show_navigation): ?>
    <?php include __DIR__ . '/topbar.php'; ?>
    <?php include __DIR__ . '/sidebar.php'; ?>
<?php endif; ?>

<?php if (!empty($flash_messages)): ?>
    <div class="lake-notes" role="status">
        <?php include __DIR__ . '/flash.php'; ?>
    </div>
<?php endif; ?>
