<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
if (!defined('DB_PATH')) {
    define('DB_PATH', BASE_PATH . '/db/database.sqlite');
}
if (!defined('USER_DIR_PATH')) {
    define('USER_DIR_PATH', BASE_PATH . '/users');
}

session_start();

require_once BASE_PATH . '/system/Database.php';
require_once BASE_PATH . '/system/auth.php';

// 管理者（root）チェック
if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'root') {
    die('このページは管理者(root)専用です。');
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$message = '';
$success = false;

// ユーザー削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['username'])) {
    $target_user = $_POST['username'];
    if ($target_user === 'root') {
        $message = '管理者アカウントは削除できません。';
    } else {
        // DBトランザクション
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
            $stmt->execute([$target_user]);

            // ユーザーディレクトリ削除
            $userdir = USER_DIR_PATH . '/' . $target_user;
            if (is_dir($userdir)) {
                deleteDirectoryRecursively($userdir);
            }

            $pdo->commit();
            $success = true;
            $message = "ユーザー「" . htmlspecialchars($target_user, ENT_QUOTES, 'UTF-8') . "」を削除しました。";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "削除中にエラーが発生しました: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}

// ユーザー一覧取得（root以外）
$stmt = $pdo->query("SELECT username FROM users WHERE username != 'root' ORDER BY username ASC");
$usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 再帰ディレクトリ削除関数
function deleteDirectoryRecursively($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.','..']);
    foreach ($files as $file) {
        $filePath = "$dir/$file";
        is_dir($filePath) ? deleteDirectoryRecursively($filePath) : unlink($filePath);
    }
    rmdir($dir);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ユーザー削除（管理者専用）</title>
    <style>
        body { background: #222; color: #fff; font-family: 'MS Gothic', monospace; }
        .container { margin: 60px auto; width: 370px; background: #333; padding: 30px; border-radius: 8px; }
        h1 { margin: 0 0 18px 0; font-size: 1.6em; }
        .message { margin-bottom: 16px; padding: 10px; border-radius: 4px; }
        .ok { background: #283; color: #dfd; }
        .ng { background: #822; color: #fcc; }
        select, button { width: 100%; padding: 11px; margin: 8px 0; border: none; border-radius: 3px; }
        button { cursor: pointer; background: #c44; color: #fff; font-weight: bold; }
        button:hover { background: #a22; }
        a { color: #6af; }
    </style>
</head>
<body>
<div class="container">
    <h1>ユーザー削除（管理者専用）</h1>
    <?php if ($message): ?>
        <div class="message <?php echo $success ? 'ok' : 'ng'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    <?php if (count($usernames) > 0): ?>
    <form method="post">
        <label for="username">削除するユーザー:</label>
        <select name="username" id="username" required>
            <?php foreach ($usernames as $uname): ?>
                <option value="<?php echo htmlspecialchars($uname, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($uname, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" onclick="return confirm('本当に削除しますか？')">削除</button>
    </form>
    <?php else: ?>
        <div>削除可能なユーザーがいません。</div>
    <?php endif; ?>
    <div style="margin-top:20px;">
        <a href="../index.php">管理ページに戻る</a>
    </div>
</div>
</body>
</html>