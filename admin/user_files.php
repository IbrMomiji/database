<?php
define('BASE_PATH', dirname(__DIR__));
session_start();

// 管理者以外は閲覧不可
if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'root') {
    header('HTTP/1.1 403 Forbidden');
    die('ACCESS DENIED');
}

$user = $_GET['user'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $user)) {
    die('INVALID USERNAME');
}

$user_dir = BASE_PATH . '/users/' . $user;
if (!is_dir($user_dir)) {
    die('USER DIRECTORY NOT FOUND');
}
$files = array_diff(scandir($user_dir), ['.', '..']);

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>FILES: <?= htmlspecialchars($user) ?></title>
    <style>
        body {
            background-color: #000;
            color: #0f0;
            font-family: 'Courier New', Courier, monospace;
            padding: 20px;
        }
        .container {
            margin: 20px auto;
            width: 90%;
            max-width: 600px;
            border: 1px solid #0f0;
            padding: 20px;
        }
        h1 {
            font-size: 1.2rem;
            margin-bottom: 18px;
            border-bottom: 1px solid #0f0;
            padding-bottom: 10px;
            font-weight: normal;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            margin: 0;
            padding-left: 10px;
        }
        a {
            color: #0f0;
            text-decoration: underline;
        }
        a:hover {
            background: #0f0;
            color: #000;
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>C:\> DIR C:\USERS\<?= htmlspecialchars($user) ?></h1>
    <?php if (count($files) === 0): ?>
        <pre>File Not Found</pre>
    <?php else: ?>
        <pre>
<?php foreach ($files as $fname): ?>
<?= htmlspecialchars($fname) . "\n" ?>
<?php endforeach; ?>
        </pre>
    <?php endif; ?>
    <div style="margin-top:20px;">
        <a href="management.php">[ BACK TO USER LIST ]</a>
    </div>
</div>
</body>
</html>
