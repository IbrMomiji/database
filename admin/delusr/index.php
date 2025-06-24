<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
if (!defined('DB_PATH')) {
    define('DB_PATH', BASE_PATH . '/db/database.sqlite');
}
if (!defined('USER_DIR_PATH')) {
    define('USER_DIR_PATH', BASE_PATH . '/users');
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

require_once BASE_PATH . '/system/Database.php';


if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'root') {
    die('アクセス権がありません。このページは管理者(root)専用です。');
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$message = '';
$success = false;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete']) && !empty($_POST['username'])) {
    $target_user = $_POST['username'];
    if ($target_user === 'root') {
        $message = 'エラー: 管理者アカウントは削除できません。';
    } else {
        $pdo->beginTransaction();
        try {
           
            $stmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
            $stmt->execute([$target_user]);

           
            $userdir = USER_DIR_PATH . '/' . $target_user;
            if (file_exists($userdir) && is_dir($userdir)) {
                deleteDirectoryRecursively($userdir);
            }

            $pdo->commit();
            $success = true;
            $message = "ユーザー「" . htmlspecialchars($target_user) . "」を正常に削除しました。";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "エラー: " . htmlspecialchars($e->getMessage());
        }
    }
}


$stmt = $pdo->query("SELECT username FROM users WHERE username != 'root' ORDER BY username ASC");
$usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);


function deleteDirectoryRecursively($dir) {
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? deleteDirectoryRecursively("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>管理者ページ - ユーザー削除</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background-color: #0000AA;
            color: #FFFFFF;
            font-family: 'MS Gothic', 'Osaka-mono', 'Courier New', monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 1rem;
            box-sizing: border-box;
            font-size: 16px;
        }
        .bios-screen {
            width: 100%;
            max-width: 800px;
            background: #0000AA;
            border: 2px solid #FFFFFF;
            padding: 0.5rem;
        }
        .bios-header, .bios-footer {
            background-color: #888888;
            color: #FFFFFF;
            text-align: center;
            padding: 0.2rem 0;
            margin-bottom: 1rem;
            font-weight: bold;
        }
        .bios-footer {
            margin-top: 1rem;
            margin-bottom: 0;
        }
        .main-content {
            padding: 1rem 2rem;
        }
        .menu-title {
            text-align: center;
            margin-bottom: 2rem;
            font-size: 1.2rem;
            letter-spacing: 2px;
        }
        .form-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            width: 100%;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        .form-label {
            flex-basis: 40%;
            text-align: left;
        }
        .form-input {
            flex-basis: 60%;
            background: #0000AA;
            border: 1px solid #fff;
            padding: 2px 4px;
        }
        .form-input select {
            width: 100%;
            background: transparent;
            border: none;
            color: #FFFFFF;
            font-size: 1rem;
            font-family: inherit;
            outline: none;
            padding: 0.2rem;
        }
        .form-input select:focus {
            background: #FFFFFF;
            color: #0000AA;
        }
        .action-button {
            display: block;
            margin: 2rem auto 0;
            padding: 0.5rem 2rem;
            background: #888888;
            color: #FFFFFF;
            border: 1px solid #FFFFFF;
            font-size: 1rem;
            cursor: pointer;
            font-family: inherit;
        }
        .action-button:hover, .action-button:focus {
            background: #FFFFFF;
            color: #0000AA;
        }
        .message-box {
            text-align: center;
            margin-bottom: 1.5rem;
            padding: 0.5rem;
            border: 1px solid;
        }
        .message-success { border-color: #55FF55; color: #55FF55; }
        .message-error { border-color: #FF5555; color: #FF5555; }
        .message-info { border-color: #FFFF55; color: #FFFF55; }
        a { color: inherit; text-decoration: none; }
        
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-content {
            background: #0000AA;
            border: 2px solid #FFFFFF;
            padding: 2rem;
            text-align: center;
            min-width: 400px;
        }
        .modal-buttons {
            margin-top: 1.5rem;
        }
        .modal-button {
            margin: 0 1rem;
        }
    </style>
</head>
<body>

<div class="bios-screen">
    <header class="bios-header">
        管理者ページ - ユーザー管理
    </header>

    <main class="main-content">
        <h1 class="menu-title">ユーザーアカウント削除</h1>

        <?php if ($message): ?>
            <div class="message-box <?php echo $success ? 'message-success' : 'message-error'; ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if (count($usernames) > 0): ?>
            <form id="delete-form" method="post">
                <div class="form-row">
                    <label for="username" class="form-label">削除対象ユーザー</label>
                    <div class="form-input">
                        <select name="username" id="username" required>
                            <?php foreach ($usernames as $uname): ?>
                                <option value="<?= htmlspecialchars($uname) ?>">
                                    <?= htmlspecialchars($uname) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="confirm_delete" value="1">
                <button type="button" id="delete-button" class="action-button">実行</button>
            </form>
        <?php else: ?>
            <div class="message-box message-info">削除可能なユーザーがいません。</div>
        <?php endif; ?>
    </main>

    <footer class="bios-footer">
        <a href="../index.php">前のメニューに戻る</a>
    </footer>
</div>

<!-- 確認モーダル -->
<div id="confirm-modal" class="modal-overlay">
    <div class="modal-content">
        <p>ユーザー「<span id="user-to-delete"></span>」を本当に削除しますか？<br>この操作は元に戻せません。</p>
        <div class="modal-buttons">
            <button id="confirm-yes" class="action-button modal-button">はい</button>
            <button id="confirm-no" class="action-button modal-button">いいえ</button>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const deleteButton = document.getElementById('delete-button');
        const deleteForm = document.getElementById('delete-form');
        const confirmModal = document.getElementById('confirm-modal');
        const userToDeleteSpan = document.getElementById('user-to-delete');
        const userSelect = document.getElementById('username');

        if (deleteButton) {
            deleteButton.addEventListener('click', function(e) {
                e.preventDefault();
                const selectedUser = userSelect.options[userSelect.selectedIndex].text;
                userToDeleteSpan.textContent = selectedUser;
                confirmModal.style.display = 'flex';
            });
        }
        
        document.getElementById('confirm-no').addEventListener('click', function() {
            confirmModal.style.display = 'none';
        });

        document.getElementById('confirm-yes').addEventListener('click', function() {
            deleteForm.submit();
        });
    });
</script>

</body>
</html>
