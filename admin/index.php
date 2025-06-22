<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
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
    // rootとしてログインしている場合は管理ページを表示
    require_once BASE_PATH . '/admin/management.php';
} else {
    // それ以外の場合はログインページを表示
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
        <title>管理者ページ - セキュリティ</title>
        <style>
            body {
                background-color: #0000AA; /* BIOSの青 */
                color: #FFFFFF;
                font-family: 'MS Gothic', 'Osaka-mono', 'Courier New', monospace;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 1rem;
                box-sizing: border-box;
                font-size: 16px; /* クラシックなターミナルのフォントサイズ */
            }
            .bios-screen {
                width: 100%;
                max-width: 800px; /* 古いモニターのように幅を広げる */
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
                flex-basis: 50%;
                text-align: left;
            }
            .form-input {
                flex-basis: 50%;
                background: #0000AA;
                border: 1px solid #fff;
                padding: 2px 4px;
            }
            .form-input input {
                width: 100%;
                background: transparent;
                border: none;
                color: #FFFFFF;
                font-size: 1rem;
                font-family: inherit;
                outline: none;
                padding: 0.2rem;
            }
            .form-input input:focus {
                background: #FFFFFF;
                color: #0000AA;
            }
            .login-button {
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
            .login-button:hover, .login-button:focus {
                background: #FFFFFF;
                color: #0000AA;
            }
            .alert-box {
                text-align: center;
                margin-bottom: 1.5rem;
                padding: 0.5rem;
                border: 1px solid #FFFF55;
                color: #FFFF55;
            }
            .alert-error {
                border-color: #FF5555;
                color: #FF5555;
            }
            .info-text {
                font-size: 0.8rem;
                color: #CCCCCC;
            }
            a {
                color: inherit;
                text-decoration: none;
            }
        </style>
    </head>
    <body>
        <div class="bios-screen">
            <header class="bios-header">
                管理者ページ
            </header>

            <main class="main-content">
                <h1 class="menu-title">管理者認証</h1>

                <?php if ($is_other_user): ?>
                    <div class="alert-box alert-info">
                        警告: root権限が必要です。<br>
                        現在のユーザー: <?= $current_user ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert-box alert-error">
                        認証に失敗しました: <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form action="index.php" method="POST">
                    <div class="form-row">
                        <label for="username" class="form-label">ユーザー名</label>
                        <div class="form-input">
                            <input type="text" id="username" name="username" value="root" required autocomplete="off">
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="password" class="form-label">パスワード</label>
                        <div class="form-input">
                             <input type="password" id="password" name="password" required autocomplete="current-password" autofocus>
                        </div>
                    </div>
                    
                    <button type="submit" class="login-button">続行</button>
                </form>
            </main>

            <footer class="bios-footer">
                <a href="../index.php">メインページに戻る</a>
            </footer>
        </div>
    </body>
    </html>
    <?php
}
?>
