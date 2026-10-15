<?php
require_once 'includes/auth.php';

$session_id = (int) ($_GET['session_id'] ?? 0);

if ($session_id > 0) {
    header('Location: results.php?session_id=' . $session_id, true, 302);
} else {
    header('Location: results.php', true, 302);
}
exit;
