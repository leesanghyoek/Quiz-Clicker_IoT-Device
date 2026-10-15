<?php
require_once __DIR__ . '/includes/security.php';
stemSessionStart();

session_unset();
session_destroy();

header("Location: /iot/login.php");
exit;
?>

