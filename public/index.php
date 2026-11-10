<?php

declare(strict_types=1);

require __DIR__ . '/../app/session.php';

openChatSession();
$token = $_SESSION['token'] ??= bin2hex(random_bytes(32));
session_write_close();

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="chat-token" content="<?= $token ?>">
    <title>Онлайн-чат</title>
    <link rel="stylesheet" href="chat.css">
    <script defer src="assets/vue.js"></script>
    <script defer src="assets/template.js"></script>
    <script defer src="chat.js"></script>
</head>
<body>
    <div id="app"></div>
    <noscript>Для работы чата включите JavaScript.</noscript>
</body>
</html>
