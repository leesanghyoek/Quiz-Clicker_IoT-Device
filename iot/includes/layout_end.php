<script src="assets/js/motion.js?v=20261012-stem-1"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.7/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.6.1/dist/js/tabler.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.21/dist/sweetalert2.min.js"></script>
<script src="assets/js/ui.js?v=20261008-2"></script>
<?php
// AI assistant window on teacher pages, but not on the screens shown to the class.
if (
    !empty($layout_show_navigation)
    && isset($_SESSION['teacher_id'])
    && !in_array($layout_body_class ?? '', ['lobby-page', 'quiz-run-page'], true)
) {
    include __DIR__ . '/ai_chat_widget.php';
}
?>
</body>
</html>
