<?php
define('BASE_PATH', dirname(__DIR__));
defined('DB_PATH') || define('DB_PATH', BASE_PATH . '/db/database.sqlite');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
    session_regenerate_id(true);
}

require_once BASE_PATH . '/system/Database.php';
require_once BASE_PATH . '/system/auth.php';

$auth = new Auth();
$login_error = null;
$is_logged_in_as_other_user = false;

function process_admin_login(Auth $auth) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['username'], $_POST['password'])) {
        return null;
    }

    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username !== 'root') {
        return "管理者(root)アカウントのみログイン可能です";
    }

    $result = $auth->login($username, $password);
    if (!$result['success']) {
        return "認証に失敗しました";
    }

    if ($_SESSION['username'] === 'root') {
        header("Location: index.php");
        exit;
    }

    return "管理者権限が確認できません";
}

$login_error = process_admin_login($auth);

if ($auth->isLoggedIn() && ($_SESSION['username'] ?? '') === 'root') {
    require_once BASE_PATH . '/admin/management.php';
} else {
    $is_logged_in_as_other_user = $auth->isLoggedIn();
    display_login_page($login_error, $is_logged_in_as_other_user);
}

function display_login_page(?string $error, bool $is_other_user) {
    $current_user = htmlspecialchars($_SESSION['username'] ?? '');
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>管理者ログイン</title>
        <style>
            body {
                background-color: #1a1a1a;
                color: #e0e0e0;
                font-family: 'SF Mono', 'Consolas', monospace;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
                box-sizing: border-box;
            }
            .login-container {
                width: 100%;
                max-width: 400px;
                padding: 2.5rem;
                background: #222;
                border: 1px solid #444;
                box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.5);
                border-radius: 8px;
            }
            .login-title {
                color: #4CAF50;
                text-align: center;
                margin-bottom: 1.8rem;
                font-size: 1.8rem;
                font-weight: 500;
            }
            .alert-box {
                padding: 0.9rem;
                margin-bottom: 1.5rem;
                border-radius: 6px;
                font-size: 0.95rem;
            }
            .alert-error {
                background: rgba(255, 80, 80, 0.15);
                border: 1px solid #ff5050;
                color: #ff9999;
            }
            .alert-info {
                background: rgba(80, 150, 255, 0.15);
                border: 1px solid #4d8af0;
                color: #a0c8ff;
            }
            .login-form input {
                width: 100%;
                padding: 0.9rem;
                margin-bottom: 1.2rem;
                background: #2d2d2d;
                border: 1px solid #444;
                color: #f0f0f0;
                border-radius: 4px;
                font-size: 1rem;
                outline: none;
                transition: border 0.2s;
            }
            .login-form input:focus {
                border-color: #4CAF50;
            }
            .login-button {
                width: 100%;
                padding: 0.9rem;
                background: #2e7d32;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 1.05rem;
                cursor: pointer;
                transition: background 0.3s;
            }
            .login-button:hover {
                background: #388e3c;
            }
            .back-link {
                display: block;
                text-align: center;
                margin-top: 1.5rem;
                color: #64b5f6;
                text-decoration: none;
                font-size: 0.95rem;
            }
            .back-link:hover {
                text-decoration: underline;
            }
            .username-display {
                font-family: monospace;
                background: #333;
                padding: 0.2rem 0.4rem;
                border-radius: 3px;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h1 class="login-title">管理者ログイン</h1>
            
            <?php if ($is_other_user): ?>
                <div class="alert-box alert-info">
                    <p>このページは <strong>root</strong> ユーザー専用です</p>
                    <p>現在 <span class="username-display"><?= $current_user ?></span> としてログイン中</p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert-box alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form class="login-form" action="index.php" method="POST">
                <input type="text" 
                       name="username" 
                       placeholder="ユーザー名" 
                       value="root" 
                       required 
                       autocomplete="off"
                       autocorrect="off"
                       spellcheck="false">
                
                <input type="password" 
                       name="password" 
                       placeholder="パスワード" 
                       required
                       autocomplete="current-password">
                
                <button type="submit" class="login-button">ログイン</button>
            </form>

            <a href="../index.php" class="back-link">メインページに戻る</a>
        </div>
    </body>
    </html>
    <?php
}
