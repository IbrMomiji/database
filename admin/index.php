<?php
define('BASE_PATH', dirname(__DIR__));
if (!defined('DB_PATH')) {
    define('DB_PATH', BASE_PATH . '/database.db');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function login($username, $password) {
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            return true;
        } else {
            return false;
        }
    } catch (Exception $e) {
        return false;
    }
}

function is_login() {
    return isset($_SESSION['user_id']);
}

function display_login_page($login_error, $is_logged_in_as_other_user) {
?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title>管理者ログイン</title>
        <link rel="stylesheet" href="../style/main.css">
        <style>
            body {
                background-color: #1a1a1a;
                color: #e0e0e0;
                font-family: 'MS Gothic', 'Osaka-Mono', monospace;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            .login-container {
                width: 360px;
                padding: 40px;
                background-color: #222;
                border: 1px solid #444;
                box-shadow: 0 0 15px rgba(0,0,0,0.7);
                text-align: center;
            }
            .login-container h1 {
                color: #e0e0e0;
                margin-bottom: 20px;
                font-size: 1.8em;
            }
            .login-container .error {
                color: #ff6666;
                background-color: rgba(255, 102, 102, 0.1);
                border: 1px solid #ff6666;
                padding: 10px;
                margin-bottom: 20px;
                border-radius: 4px;
                text-align: left;
            }
             .login-container .info {
                color: #66aaff;
                background-color: rgba(102, 170, 255, 0.1);
                border: 1px solid #66aaff;
                padding: 10px;
                margin-bottom: 20px;
                border-radius: 4px;
            }
            .login-container input {
                width: 100%;
                padding: 12px;
                margin-bottom: 15px;
                background-color: #333;
                border: 1px solid #555;
                color: #e0e0e0;
                font-family: inherit;
                box-sizing: border-box;
            }
            .login-container button {
                width: 100%;
                padding: 12px;
                background-color: #4CAF50;
                border: none;
                color: white;
                font-family: inherit;
                font-size: 1em;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            .login-container button:hover {
                background-color: #45a049;
            }
            .login-container a {
                color: #66aaff;
                text-decoration: none;
                display: block;
                margin-top: 20px;
            }
            .login-container a:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h1>管理者ログイン</h1>

            <?php if ($is_logged_in_as_other_user): ?>
                <div class="info">
                    <p>このページへのアクセスは `root` ユーザーのみに許可されています。</p>
                    <p>現在 `<?php echo htmlspecialchars($_SESSION['username']); ?>` としてログインしています。</p>
                </div>
            <?php endif; ?>

            <?php if ($login_error): ?>
                <div class="error"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>

            <form action="index.php" method="POST">
                <input type="text" name="username" placeholder="ユーザー名" value="root" required>
                <input type="password" name="password" placeholder="パスワード" required>
                <button type="submit">ログイン</button>
            </form>

            <a href="../index.php">メインページに戻る</a>
        </div>
    </body>
    </html>
<?php
}

$login_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === 'root' && login($username, $password)) {
        header("Location: index.php");
        exit;
    } else {
        $login_error = "管理者（root）のユーザー名またはパスワードが正しくありません。";
    }
}

if (is_login() && isset($_SESSION['username']) && $_SESSION['username'] === 'root') {
    require_once BASE_PATH . '/admin/management.php';
} else {
    $is_logged_in_as_other_user = is_login();
    display_login_page($login_error, $is_logged_in_as_other_user);
}
