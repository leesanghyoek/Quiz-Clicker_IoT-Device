<?php
require_once __DIR__ . '/security.php';
stemSessionStart();

if (!isset($_SESSION['teacher_id'])) {
    header("Location: /iot/login.php");
    exit;
}
?>

