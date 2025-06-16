<?php
// =================================================================
// データベースウェブサイト実装 (v13 + Explorer機能)
//
// - v13の全機能を保持
// - ログイン後に 'explorer' コマンドでファイルエクスプローラーを起動する機能を追加
// =================================================================

// --- セッション管理 ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// POSTリクエスト以外でアクセスされた場合はセッションをリセット
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['history'] = [];
    $_SESSION['interaction_state'] = null;
    unset($_SESSION['user_id']);
    unset($_SESSION['username']);
}

// --- エラーハンドリング ---
error_reporting(E_ALL & ~E_WARNING); // 警告は表示しない
ini_set('display_errors', 0);        // エラーを画面に表示しない
ini_set('log_errors', 1);            // エラーをログに出力する

// --- 定数定義 ---
define('DB_PATH', __DIR__ . '/db/database.sqlite');
define('USER_DIR_PATH', __DIR__ . '/user');

// =================================================================
// クラス定義
// =================================================================

/**
 * データベース接続を管理するシングルトンクラス
 */
class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        if (!is_dir(dirname(DB_PATH))) {
            mkdir(dirname(DB_PATH), 0775, true);
        }
        if (!is_writable(dirname(DB_PATH))) {
            throw new Exception("権限エラー: Webサーバーが 'db' ディレクトリに書き込めません。ディレクトリの権限を確認してください。");
        }
        try {
            if (!in_array('sqlite', PDO::getAvailableDrivers())) {
                throw new Exception("PHP設定エラー: SQLite PDOドライバが有効になっていません。php.iniで 'extension=pdo_sqlite' を有効にしてください。");
            }
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->initSchema();
        } catch (PDOException $e) {
            throw new Exception("データベース接続に失敗しました: " . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->pdo;
    }

    private function initSchema()
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );");
    }
}

/**
 * ユーザーからのコマンドを処理するクラス
 */
class CommandHandler
{
    private $db;
    private $output = '';
    private $clearScreen = false;
    private $interactionState = null;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->interactionState = $_SESSION['interaction_state'] ?? null;
    }

    public function handle($input)
    {
        if ($this->interactionState) {
            $this->handleInteraction($input);
        } else {
            $parts = preg_split('/\s+/', trim($input), 2);
            $command = strtolower($parts[0] ?? '');
            $argString = $parts[1] ?? '';
            $args = $this->parseArgs($argString);

            // --- 追加 ---
            // 'explorer' コマンドの処理
            if ($command === 'explorer') {
                if (!isset($_SESSION['user_id'])) {
                    $this->output = "エラー: このコマンドを実行するにはログインが必要です。";
                } elseif (!empty($args)) {
                    $this->output = "エラー: 'explorer' コマンドに引数は不要です。";
                } else {
                    // フロントエンドに特別なアクションを返す
                    return [
                        'output' => "エクスプローラーを起動します...",
                        'prompt' => $this->getPrompt(),
                        'clear' => false,
                        'action' => 'open_explorer'
                    ];
                }
            } else {
            // --- 追加ここまで ---
                switch ($command) {
                    case 'login':
                        $this->handleLogin($args);
                        break;
                    case 'register':
                        $this->handleRegistration($args);
                        break;
                    case 'help':
                        if (!empty($args)) {
                            $this->output = "エラー: 'help' コマンドに引数は不要です。";
                            break;
                        }
                        $this->output = $this->getHelpText();
                        break;
                    case 'logout':
                        if (!empty($args)) {
                            $this->output = "エラー: 'logout' コマンドに引数は不要です。";
                            break;
                        }
                        $this->logout();
                        break;
                    case 'clear':
                        if (!empty($args)) {
                            $this->output = "エラー: 'clear' コマンドに引数は不要です。";
                            break;
                        }
                        $this->clearScreen = true;
                        $_SESSION['history'] = [];
                        break;
                    case 'whoami':
                        if (!empty($args)) {
                            $this->output = "エラー: 'whoami' コマンドに引数は不要です。";
                            break;
                        }
                        $this->output = isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : 'ログインしていません。';
                        break;
                    case 'start':
                         if (!empty($args)) {
                            $this->output = "エラー: 'start' コマンドに引数は不要です。";
                            break;
                        }
                        $this->output = "新しいコンソールウィンドウを開きました。";
                        break;
                    case 'delete-account':
                        $this->handleDeleteAccount($args);
                        break;
                    case '':
                        break;
                    default:
                        $this->output = "コマンドが見つかりません: " . htmlspecialchars($command, ENT_QUOTES, 'UTF-8') . "<br>'help'と入力すると、コマンドの一覧を表示します。";
                        break;
                }
            // --- 追加 ---
            }
            // --- 追加ここまで ---
        }

        $_SESSION['interaction_state'] = $this->interactionState;
        return [
            'output' => $this->output,
            'prompt' => $this->getPrompt(),
            'clear' => $this->clearScreen
        ];
    }

    private function checkInvalidArgs($commandName, $args, $validArgs) {
        foreach (array_keys($args) as $arg) {
            if (!in_array($arg, $validArgs)) {
                $this->output = "エラー: '" . htmlspecialchars($commandName, ENT_QUOTES, 'UTF-8') . "' コマンドに不明な引数 '-" . htmlspecialchars($arg, ENT_QUOTES, 'UTF-8') . "' があります。";
                return false;
            }
        }
        return true;
    }

    private function handleInteraction($input)
    {
        $stateData = $this->interactionState;
        $type = $stateData['type'];

        if ($type === 'register' || $type === 'login') {
            if ($stateData['step'] === 'get_username') {
                $this->interactionState['username'] = $input;
                $this->interactionState['step'] = 'get_password';
                $this->output = "パスワードを入力してください: ";
            } elseif ($stateData['step'] === 'get_password') {
                $method = ($type === 'login') ? 'loginUser' : 'registerUser';
                $this->$method($stateData['username'], $input);
                $this->interactionState = null;
            }
        } elseif ($type === 'delete_account') {
            if (strtolower($input) === 'yes') {
                $this->deleteUser();
            } else {
                $this->output = "アカウントの削除を中止しました。";
            }
            $this->interactionState = null;
        }
    }

    private function handleLogin($args)
    {
        if(!$this->checkInvalidArgs('login', $args, ['u', 'p'])) return;
        if (!empty($args['u']) && !empty($args['p'])) {
            $this->loginUser($args['u'], $args['p']);
        } else {
            $this->interactionState = ['type' => 'login', 'step' => 'get_username'];
            $this->output = "ユーザー名を入力してください: ";
        }
    }

    private function handleRegistration($args)
    {
        if(!$this->checkInvalidArgs('register', $args, ['u', 'p'])) return;
        if (!empty($args['u']) && !empty($args['p'])) {
            $this->registerUser($args['u'], $args['p']);
        } else {
            $this->interactionState = ['type' => 'register', 'step' => 'get_username'];
            $this->output = "登録するユーザー名を入力してください: ";
        }
    }
    
    private function handleDeleteAccount($args) {
        if (!isset($_SESSION['user_id'])) {
            $this->output = "エラー: このコマンドを実行するにはログインが必要です。";
            return;
        }
        if (!empty($args)) {
            $this->output = "エラー: 'delete-account' コマンドに引数は不要です。";
            return;
        }
        $this->interactionState = ['type' => 'delete_account', 'step' => 'confirm'];
        $this->output = "本当にアカウントを削除しますか？ この操作は取り消せません。<br>よろしい場合は 'yes' と入力してください。";
    }

    private function loginUser($username, $password)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $this->output = "ようこそ、" . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . "さん！";
        } else {
            $this->output = "ログイン失敗: ユーザー名またはパスワードが間違っています。";
        }
    }

    private function registerUser($username, $password)
    {
        if (preg_match('/[\\\\\/:\*\?"<>|.]/', $username) || $username === '..') {
            $this->output = "エラー: ユーザー名に無効な文字 (\\ / : * ? \" < > | .) が含まれています。";
            return;
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $this->output = "エラー: ユーザー名 '" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "' は既に使用されています。";
            return;
        }

        $this->db->beginTransaction();

        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->execute([$username, $hashedPassword]);

            if (!is_dir(USER_DIR_PATH)) {
                if (!mkdir(USER_DIR_PATH, 0775, true)) {
                     throw new Exception("ベースディレクトリの作成に失敗しました。");
                }
            }

            if (!is_writable(USER_DIR_PATH)) {
                throw new Exception("'user' ディレクトリに書き込み権限がありません。");
            }
            
            $userSpecificDir = USER_DIR_PATH . '/' . $username;
            if (!is_dir($userSpecificDir)) {
                if (!mkdir($userSpecificDir, 0775)) {
                    throw new Exception("ユーザーディレクトリの作成に失敗しました。");
                }
            }
            
            $this->db->commit();
            $this->output = "ユーザー '" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "' を登録しました。'login'コマンドでログインしてください。";

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->output = "エラー: 登録中にサーバーで問題が発生しました。";
            error_log("User registration failed for '$username': " . $e->getMessage());
        }
    }
    
    private function deleteUser() {
        if (!isset($_SESSION['user_id'])) return;

        $userId = $_SESSION['user_id'];
        $username = $_SESSION['username'];
        $userSpecificDir = USER_DIR_PATH . '/' . $username;

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            if (is_dir($userSpecificDir)) {
                if (!$this->deleteDirectoryRecursively($userSpecificDir)) {
                     throw new Exception("ユーザーディレクトリの削除に失敗しました。");
                }
            }

            $this->db->commit();
            $this->output = "アカウントを削除しました。";
            $this->logout(false);

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->output = "エラー: アカウント削除中にサーバーで問題が発生しました。";
            error_log("Account deletion failed for user ID '$userId' ($username): " . $e->getMessage());
        }
    }

    private function deleteDirectoryRecursively($dir) {
        if (!is_dir($dir)) return false;
        $files = array_diff(scandir($dir), ['.','..']);
        foreach ($files as $file) {
          (is_dir("$dir/$file")) ? $this->deleteDirectoryRecursively("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    private function logout($showMessage = true)
    {
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
            unset($_SESSION['username']);
            if ($showMessage) $this->output = "ログアウトしました。";
        } else {
            if ($showMessage) $this->output = "ログインしていません。";
        }
    }
    
    private function parseArgs($argString)
    {
        $args = [];
        preg_match_all('/-(\w+)(?:\s+("[^"]*"|[^\s-]+))?/', $argString, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2] ?? true;
            if (is_string($value)) {
                $value = trim($value, '"');
            }
            $args[$key] = $value;
        }
        return $args;
    }
    
    private function getPrompt()
    {
        if ($this->interactionState) {
            return '';
        }
        $username = $_SESSION['username'] ?? null;
        return $username ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '@database&gt; ' : 'database&gt; ';
    }
    
    private function getHelpText()
    {
        $loggedIn = isset($_SESSION['user_id']);
        $help = "利用可能なコマンド:<br>  <span class=\"cmd\">help</span>                      - このヘルプメッセージを表示します。<br>  <span class=\"cmd\">register</span> [-u USER] [-p PASS] - 新しいユーザーアカウントを作成します。<br>  <span class=\"cmd\">login</span>    [-u USER] [-p PASS] - アカウントにログインします。<br>  <span class=\"cmd\">logout</span>                      - アカウントからログアウトします。<br>  <span class=\"cmd\">whoami</span>                      - 現在ログインしているユーザーを表示します。<br>  <span class=\"cmd\">clear</span>                       - コンソール画面をきれいにします。<br>  <span class=\"cmd\">start</span>                       - 新しいコンソールウィンドウを開きます。<br>";

        if ($loggedIn) {
            // --- 追加 ---
            $help .= "  <span class=\"cmd\">explorer</span>                    - ファイルエクスプローラーを起動します。<br>";
            // --- 追加ここまで ---
            $help .= "  <span class=\"cmd\">delete-account</span>              - 現在のユーザーアカウントを削除します。<br>";
        }
        return $help;
    }
}

// =================================================================
// POSTリクエスト処理
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['command'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $handler = new CommandHandler();
        $commandStr = $_POST['command'];
        $response = $handler->handle($commandStr);

        if (empty($response['clear'])) {
            $currentPrompt = $_POST['current_prompt'] ?? '';
            if($commandStr) {
                 $_SESSION['history'][] = '<div>' . $currentPrompt . htmlspecialchars($commandStr, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            if (!empty($response['output'])) {
                 $_SESSION['history'][] = '<div>' . $response['output'] . '</div>';
            }
        }

        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        $promptAfterError = (isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') . '@database&gt; ' : 'database&gt; ');
        if (isset($_SESSION['interaction_state'])) {
            $promptAfterError = '';
        }
        echo json_encode(['output' => '<span class="error">サーバーエラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span>', 'prompt' => $promptAfterError, 'clear' => false]);
    }
    exit;
}

// =================================================================
// HTMLレンダリング
// =================================================================
$history = $_SESSION['history'] ?? [];
if (empty($history)) {
    $history = ['<div>データベースクライアントへようこそ。</div>', "<div>'help' と入力するとコマンドの一覧を表示します。</div>", '<div><br></div>'];
}
$prompt = (isset($_SESSION['interaction_state'])) ? '' : ((isset($_SESSION['username'])) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') . '@database&gt; ' : 'database&gt; ');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>database</title>
    <style>
        @font-face {
            font-family: 'MS Gothic';
            src: local('MS Gothic'), local('ＭＳ ゴシック'), local('Osaka-mono');
        }
        html, body {
            width: 100%; height: 100%; margin: 0; padding: 0; overflow: hidden;
        }
        body {
            background-color: #008080; font-family: 'MS Gothic', 'Osaka-mono', monospace;
        }
        .console-window {
            width: 700px; height: 450px; background-color: #000000;
            border: 1px solid #707070; box-shadow: 2px 2px 10px rgba(0,0,0,0.5);
            position: absolute; top: 50px; left: 50px;
            display: flex; flex-direction: column; overflow: hidden;
            min-width: 350px; min-height: 200px;
        }
        .console-window.maximized {
            top: 0 !important; left: 0 !important; width: 100vw !important; height: 100vh !important;
            border: none; box-shadow: none; transition: none;
        }
        .console-window.snapped-left {
            top: 0 !important; left: 0 !important; width: 50vw !important; height: 100vh !important;
        }
        .console-window.snapped-right {
            top: 0 !important; left: 50vw !important; width: 50vw !important; height: 100vh !important;
        }
        .title-bar {
            background-color: #0d2a53; color: #cccccc; padding: 4px 5px;
            cursor: move; user-select: none; display: flex;
            justify-content: space-between; align-items: center; flex-shrink: 0;
            font-size: 14px;
        }
        .title-bar-text {
            display: flex; align-items: center; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
        }
        .title-bar-icon {
            margin-right: 8px; height: 16px; width: 16px;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="%23fff"><path d="M2 2v2h12V2H2zm0 3v2h12V5H2zm0 3v2h12V8H2zm0 3v2h12v-2H2z"/></svg>');
            background-repeat: no-repeat; background-size: contain;
            cursor: pointer;
        }
        .maximized .title-bar, .snapped-left .title-bar, .snapped-right .title-bar { cursor: default; }
        .window-controls { display: flex; }
        .window-controls span {
            width: 26px; height: 20px; margin-left: 1px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-weight: bold; display: flex; align-items: center; justify-content: center;
            color: #ccc; cursor: pointer; transition: background-color 0.15s;
        }
        .window-controls span:hover { background-color: #2a4a7a; }
        .window-controls .close-btn:hover { background-color: #e81123; color: #fff; }
        .console-body {
            padding: 8px; flex-grow: 1; overflow-y: auto; color: #c0c0c0;
            font-size: 16px; line-height: 1.4;
        }
        .console-body::-webkit-scrollbar { width: 16px; }
        .console-body::-webkit-scrollbar-track { background: #2c2c2c; }
        .console-body::-webkit-scrollbar-thumb { background: #555; border: 1px solid #777; }
        .console-output > div {
            white-space: pre-wrap; word-wrap: break-word;
        }
        .input-line { display: flex; }
        .input-line.interactive-mode .prompt { display: none; }
        .prompt { white-space: pre; color: #c0c0c0; }
        .console-input {
            background: none; border: none; outline: none; color: #c0c0c0;
            font: inherit; flex-grow: 1; padding: 0; caret-color: #c0c0c0;
        }
        #minimized-area {
            position: fixed; bottom: 5px; left: 5px; display: flex; z-index: 10000;
        }
        .minimized-tab {
            height: 28px; max-width: 250px; background-color: #c0c0c0;
            border: 2px solid #ffffff; border-right-color: #808080; border-bottom-color: #808080;
            display: flex; align-items: center; padding: 0 8px; margin: 0 2px;
            cursor: pointer; color: #000; font-weight: bold; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap; box-shadow: 1px 1px 1px #808080;
        }
        .minimized-tab:active {
            border-color: #808080; border-right-color: #ffffff; border-bottom-color: #ffffff;
        }
        .resizer { position: absolute; background: transparent; z-index: 10; }
        .resizer.top { cursor: ns-resize; width: 100%; height: 8px; top: -4px; left: 0; }
        .resizer.right { cursor: ew-resize; height: 100%; width: 8px; top: 0; right: -4px; }
        .resizer.bottom { cursor: ns-resize; width: 100%; height: 8px; bottom: -4px; left: 0; }
        .resizer.left { cursor: ew-resize; height: 100%; width: 8px; top: 0; left: -4px; }
        .resizer.top-left { cursor: nwse-resize; width: 12px; height: 12px; top: -6px; left: -6px; }
        .resizer.top-right { cursor: nesw-resize; width: 12px; height: 12px; top: -6px; right: -6px; }
        .resizer.bottom-left { cursor: nesw-resize; width: 12px; height: 12px; bottom: -6px; left: -6px; }
        .resizer.bottom-right { cursor: nwse-resize; width: 12px; height: 12px; bottom: -6px; right: -6px; }
        .snap-indicator {
            position: fixed; z-index: 99999; pointer-events: none; border: 2px solid #fff;
            box-sizing: border-box; background: rgba(255,255,255,0.11);
            transition: all 0.1s ease-in-out;
        }
        .custom-context-menu {
            position: fixed;
            min-width: 180px;
            background: #0d2a53;
            border: 1.5px solid #707070;
            box-shadow: 1px 2px 8px rgba(0,0,0,0.4);
            font-family: 'MS Gothic', 'Osaka-mono', monospace;
            color: #cccccc;
            padding: 4px 0;
            margin: 0;
            z-index: 10001;
            font-size: 14px;
        }
        .custom-context-menu .menu-item {
            padding: 6px 20px 6px 20px;
            cursor: pointer;
            position: relative;
            user-select: none;
            white-space: nowrap;
            display: flex;
            justify-content: space-between;
        }
        .custom-context-menu .menu-item:hover {
            background-color: #2a4a7a;
        }
        .custom-context-menu .separator {
            height: 1px; background-color: #707070; margin: 4px 0;
        }
        .cmd { color: #4CAF50; }
        .error { color: #ff5555; }
        
        /* --- 追加 --- */
        .explorer-window {
            width: 800px; height: 550px; background-color: #f0f0f0;
        }
        .explorer-window .title-bar {
             background-color: #f0f0f0; color: #000; border-bottom: 1px solid #ccc;
        }
        .explorer-window .title-bar .title-bar-icon {
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="%23000"><path d="M13 2H3v2h10V2zm0 3H3v2h10V5zm0 3H3v2h10V8zM3 11h5v2H3v-2z"/></svg>');
        }
        .explorer-window .window-controls span { color: #000; }
        .explorer-window .window-controls span:hover { background-color: #e0e0e0; }
        .explorer-window .window-controls .close-btn:hover { background-color: #e81123; color: #fff; }
        .explorer-window iframe { width: 100%; height: 100%; border: none; flex-grow: 1; }
        /* --- 追加ここまで --- */
    </style>
</head>
<body>

    <template id="windowTemplate">
        <div class="console-window">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div>
            <div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar">
                <div class="title-bar-text">
                    <span class="title-bar-icon"></span>
                    <span class="window-title">database client</span>
                </div>
                <div class="window-controls">
                    <span class="minimize-btn">_</span>
                    <span class="maximize-btn">&#10065;</span>
                    <span class="close-btn">X</span>
                </div>
            </div>
            <div class="console-body">
                <div class="console-output"></div>
                <div class="input-line">
                    <span class="prompt"></span>
                    <input type="text" class="console-input" spellcheck="false" autocomplete="off">
                </div>
            </div>
        </div>
    </template>

    <!-- --- 追加 --- -->
    <template id="explorerWindowTemplate">
        <div class="console-window explorer-window">
             <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div>
            <div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar">
                <div class="title-bar-text">
                    <span class="title-bar-icon"></span>
                    <span class="window-title">エクスプローラー</span>
                </div>
                <div class="window-controls">
                    <span class="minimize-btn">_</span>
                    <span class="maximize-btn">&#10065;</span>
                    <span class="close-btn">X</span>
                </div>
            </div>
            <iframe src="system/explorer.php" name="explorer-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>
    <!-- --- 追加ここまで --- -->

    <div id="minimized-area"></div>
    <div class="snap-indicator" style="display: none;"></div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        createWindow(<?php echo json_encode($history); ?>, <?php echo json_encode($prompt); ?>);
        setupGlobalListeners();
    });

    let windowIdCounter = 0;
    let contextMenuTargetWindow = null;
    let lastMousePosition = { x: 0, y: 0 };

    function createWindow(history = [], prompt = 'database&gt; ') {
        const template = document.getElementById('windowTemplate');
        const newWindow = template.content.cloneNode(true).querySelector('.console-window');
        
        newWindow.id = `window-${windowIdCounter++}`;
        newWindow.style.left = `${50 + windowIdCounter * 25}px`;
        newWindow.style.top = `${50 + windowIdCounter * 25}px`;
        newWindow.style.zIndex = getHighestZIndex() + 1;

        document.body.appendChild(newWindow);
        
        const outputEl = newWindow.querySelector('.console-output');
        const promptEl = newWindow.querySelector('.prompt');
        
        outputEl.innerHTML = history.length > 0 ? history.join('') : `<div>データベースクライアントへようこそ。</div><div>'help' と入力するとコマンドの一覧を表示します。</div><div><br></div>`;
        promptEl.innerHTML = prompt;
        if (prompt === '') newWindow.querySelector('.input-line').classList.add('interactive-mode');

        makeInteractive(newWindow);

        const inputEl = newWindow.querySelector('.console-input');
        inputEl.focus();
        const consoleBody = newWindow.querySelector('.console-body');
        consoleBody.scrollTop = consoleBody.scrollHeight;
    }

    // --- 追加 ---
    function createExplorerWindow() {
        const template = document.getElementById('explorerWindowTemplate');
        const newWindow = template.content.cloneNode(true).querySelector('.console-window');
        
        newWindow.id = `window-${windowIdCounter++}`;
        newWindow.style.left = `${80 + windowIdCounter * 25}px`;
        newWindow.style.top = `${80 + windowIdCounter * 25}px`;
        newWindow.style.zIndex = getHighestZIndex() + 1;
        
        document.body.appendChild(newWindow);
        makeInteractive(newWindow);
    }
    // --- 追加ここまで ---

    function makeInteractive(win) {
        const titleBar = win.querySelector('.title-bar');
        const inputEl = win.querySelector('.console-input');
        const consoleBody = win.querySelector('.console-body');
        const maximizeBtn = win.querySelector('.maximize-btn');
        const icon = win.querySelector('.title-bar-icon');
        
        let dragOffsetX, dragOffsetY;

        const bringToFront = () => win.style.zIndex = getHighestZIndex() + 1;
        win.addEventListener('mousedown', bringToFront, { capture: true });
        
        icon.addEventListener('mousedown', e => e.stopPropagation());
        icon.addEventListener('click', onIconClick);

        titleBar.addEventListener('mousedown', onDragStart);
        titleBar.addEventListener('contextmenu', onContextMenu);
        titleBar.addEventListener('dblclick', (e) => {
            if (!e.target.closest('.window-controls') && !e.target.closest('.title-bar-icon')) {
                 toggleMaximize(win);
            }
        });

        win.querySelector('.close-btn').addEventListener('click', () => closeWindow(win));
        win.querySelector('.minimize-btn').addEventListener('click', () => minimizeWindow(win));
        maximizeBtn.addEventListener('click', () => toggleMaximize(win));

        win.querySelectorAll('.resizer').forEach(resizer => {
            resizer.addEventListener('mousedown', (e) => onResizeStart(e, win));
        });

        if (inputEl) { // エクスプローラーウィンドウには無いのでnullチェック
            inputEl.addEventListener('keydown', (e) => onCommandSubmit(e, win));

            consoleBody.addEventListener('click', (e) => {
                if (window.getSelection().toString() === '') inputEl.focus();
            });
        }
        
        function onIconClick(e) {
            e.preventDefault();
            e.stopPropagation();
            const rect = titleBar.getBoundingClientRect();
            showContextMenu(rect.left, rect.bottom, win);
        }

        function onDragStart(e) {
            if (e.target.closest('.window-controls') || e.target.closest('.resizer') || e.target.closest('.title-bar-icon') || e.button !== 0) return;
            e.preventDefault();
            bringToFront();

            if (win.dataset.isSnapped || win.classList.contains('maximized')) {
                const prevWidth = parseFloat(JSON.parse(win.dataset.prevRect || '{}').width) || 700;
                const newLeft = e.clientX - (prevWidth * (e.clientX / window.innerWidth));
                win.classList.remove('maximized', 'snapped-left', 'snapped-right');
                win.dataset.isSnapped = 'false';
                win.style.left = `${newLeft}px`;
                win.style.top = `${e.clientY - 15}px`;
                win.style.width = `${prevWidth}px`;
                win.style.height = (JSON.parse(win.dataset.prevRect || '{}').height) || '450px';
                dragOffsetX = e.clientX - newLeft;
                dragOffsetY = e.clientY - parseFloat(win.style.top);
            } else {
                dragOffsetX = e.clientX - win.offsetLeft;
                dragOffsetY = e.clientY - win.offsetTop;
            }
            
            document.addEventListener('mousemove', onDragging);
            document.addEventListener('mouseup', onDragEnd, { once: true });
        }

        function onDragging(e) {
            const { clientX, clientY } = e;
            const snapMargin = 5;
            let snapType = null;
            if (clientY <= snapMargin && clientX > snapMargin && clientX < window.innerWidth - snapMargin) snapType = 'top';
            else if (clientX <= snapMargin && clientY > snapMargin) snapType = 'left';
            else if (clientX >= window.innerWidth - snapMargin && clientY > snapMargin) snapType = 'right';
            
            if (snapType) {
                showSnapIndicator(snapType);
                win.dataset.snapType = snapType;
            } else {
                hideSnapIndicator();
                win.dataset.snapType = '';
                win.style.left = `${clientX - dragOffsetX}px`;
                win.style.top = `${clientY - dragOffsetY}px`;
            }
        }

        function onDragEnd() {
            document.removeEventListener('mousemove', onDragging);
            hideSnapIndicator();
            const snapType = win.dataset.snapType;
            if (snapType) applySnap(win, snapType);
            if (inputEl) inputEl.focus();
        }

        function onResizeStart(e, win) {
            e.preventDefault();
            bringToFront();
            let prevX = e.clientX, prevY = e.clientY;
            let rect = win.getBoundingClientRect();
            const resizer = e.target;
            
            win.classList.remove('maximized', 'snapped-left', 'snapped-right');
            win.dataset.isSnapped = 'false';

            const handleResize = (e) => {
                const dx = e.clientX - prevX, dy = e.clientY - prevY;
                if (resizer.classList.contains('right') || resizer.classList.contains('top-right') || resizer.classList.contains('bottom-right')) rect.width += dx;
                if (resizer.classList.contains('bottom') || resizer.classList.contains('bottom-left') || resizer.classList.contains('bottom-right')) rect.height += dy;
                if (resizer.classList.contains('left') || resizer.classList.contains('top-left') || resizer.classList.contains('bottom-left')) { rect.width -= dx; rect.left += dx; }
                if (resizer.classList.contains('top') || resizer.classList.contains('top-left') || resizer.classList.contains('top-right')) { rect.height -= dy; rect.top += dy; }
                
                win.style.width = rect.width + 'px'; win.style.height = rect.height + 'px';
                win.style.top = rect.top + 'px'; win.style.left = rect.left + 'px';
                prevX = e.clientX; prevY = e.clientY;
            };

            const stopResize = () => {
                window.removeEventListener('mousemove', handleResize);
                win.dataset.prevRect = JSON.stringify({
                    top: win.style.top, left: win.style.left,
                    width: win.style.width, height: win.style.height
                });
                if (inputEl) inputEl.focus();
            };
            window.addEventListener('mousemove', handleResize);
            window.addEventListener('mouseup', stopResize, { once: true });
        }
        
        async function onCommandSubmit(e, win) {
            if (e.key !== 'Enter' || e.isComposing) return;
            e.preventDefault();
            
            const command = inputEl.value.trim();
            inputEl.value = '';
            
            const currentPrompt = win.querySelector('.prompt').textContent;
            const outputEl = win.querySelector('.console-output');
            const consoleBody = win.querySelector('.console-body');

            if(command){
                outputEl.innerHTML += `<div>${escapeHtml(currentPrompt + command)}</div>`;
            }
            consoleBody.scrollTop = consoleBody.scrollHeight;

            if (command.toLowerCase() === 'start') {
                 outputEl.innerHTML += `<div>新しいコンソールウィンドウを開きました。</div>`;
                 createWindow();
                 consoleBody.scrollTop = consoleBody.scrollHeight;
                 return;
            }
            if(!command) return;
            
            const formData = new FormData();
            formData.append('command', command);
            formData.append('current_prompt', currentPrompt);

            try {
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (!response.ok) throw new Error(data.output || `サーバーエラー: ${response.status}`);
                
                // --- 追加 ---
                if (data.action === 'open_explorer') {
                    createExplorerWindow();
                }
                // --- 追加ここまで ---

                if (data.clear) {
                    outputEl.innerHTML = '';
                }
                if (data.output) {
                    outputEl.innerHTML += `<div>${data.output}</div>`;
                }
                if (data.prompt !== undefined) {
                    win.querySelector('.prompt').innerHTML = data.prompt;
                    win.querySelector('.input-line').classList.toggle('interactive-mode', data.prompt === '');
                }
            } catch (error) {
                outputEl.innerHTML += `<div class="error">クライアントエラー: ${error.message}</div>`;
            }
            
            consoleBody.scrollTop = consoleBody.scrollHeight;
            inputEl.focus();
        }

        function onContextMenu(e) {
            e.preventDefault();
            e.stopPropagation();
            showContextMenu(e.clientX, e.clientY, win);
        }
    }

    function minimizeWindow(win) {
        win.style.display = 'none';
        const minimizedTab = document.createElement('div');
        minimizedTab.className = 'minimized-tab';
        minimizedTab.textContent = win.querySelector('.window-title').textContent;
        minimizedTab.dataset.windowId = win.id;
        document.getElementById('minimized-area').appendChild(minimizedTab);
    }
        
    function toggleMaximize(win) {
        const maximizeBtn = win.querySelector('.maximize-btn');
        if (win.classList.contains('maximized') || win.classList.contains('snapped-left') || win.classList.contains('snapped-right')) {
            const pos = JSON.parse(win.dataset.prevRect || '{}');
            win.classList.remove('maximized', 'snapped-left', 'snapped-right');
            win.style.top = pos.top || '50px';
            win.style.left = pos.left || '50px';
            win.style.width = pos.width || '700px';
            win.style.height = pos.height || '450px';
            maximizeBtn.innerHTML = '&#10065;';
            win.dataset.isSnapped = 'false';
        } else {
            win.dataset.prevRect = JSON.stringify({
                top: win.style.top, left: win.style.left,
                width: win.style.width, height: win.style.height
            });
            win.classList.add('maximized');
            maximizeBtn.innerHTML = '&#10066;';
            win.dataset.isSnapped = 'true';
        }
    }
    
    function closeWindow(win) {
        win.remove();
        if (document.querySelectorAll('.console-window').length === 0) {
            createWindow();
        }
    }

    function applySnap(win, type) {
        if (!win.classList.contains('maximized') && !win.classList.contains('snapped-left') && !win.classList.contains('snapped-right')) {
            win.dataset.prevRect = JSON.stringify({
                top: win.style.top, left: win.style.left,
                width: win.style.width, height: win.style.height
            });
        }
        win.classList.remove('maximized', 'snapped-left', 'snapped-right');
        if (type === 'top') {
             toggleMaximize(win);
        } else {
            if (type === 'left') win.classList.add('snapped-left');
            else if (type === 'right') win.classList.add('snapped-right');
            win.dataset.isSnapped = 'true';
            win.querySelector('.maximize-btn').innerHTML = '&#10065;';
        }
    }
    
    function setupGlobalListeners() {
        document.addEventListener('mousemove', e => {
            lastMousePosition = { x: e.clientX, y: e.clientY };
        });

        document.getElementById('minimized-area').addEventListener('click', (e) => {
            const tab = e.target.closest('.minimized-tab');
            if (!tab) return;
            const win = document.getElementById(tab.dataset.windowId);
            if (win) {
                win.style.display = '';
                win.style.zIndex = getHighestZIndex() + 1;
                const input = win.querySelector('.console-input');
                if(input) input.focus();
            }
            tab.remove();
        });

        document.addEventListener('keydown', (e) => {
            if (contextMenuTargetWindow) {
                e.preventDefault();
                e.stopPropagation();
                
                if (e.key === 'Escape') {
                    hideContextMenu();
                    return;
                }

                const keyActionMap = { 'm': 'move', 's': 'resize', 'n': 'minimize', 'x': 'maximize', 'c': 'close' };
                const action = keyActionMap[e.key.toLowerCase()];

                if (action) {
                    executeContextMenuAction(contextMenuTargetWindow, action);
                    hideContextMenu();
                }
                return;
            }

            const topWindow = getTopWindow();
            if (!topWindow) return;

            if (e.ctrlKey && ['ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                e.preventDefault();
                if (e.key === 'ArrowUp') toggleMaximize(topWindow);
                if (e.key === 'ArrowLeft') applySnap(topWindow, 'left');
                if (e.key === 'ArrowRight') applySnap(topWindow, 'right');
            }
            
            const activeEl = document.activeElement;
            if (!activeEl || (activeEl.tagName !== 'INPUT' && activeEl.tagName !== 'TEXTAREA')) {
                 if (!e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
                     const input = topWindow.querySelector('.console-input');
                     if (input) {
                        input.focus();
                     }
                }
            }
        });
        
        document.addEventListener('click', hideContextMenu);
        document.addEventListener('contextmenu', (e) => {
            if (!e.target.closest('.title-bar')) {
                 hideContextMenu();
            }
        });
        
        window.addEventListener('resize', hideSnapIndicator);
    }

    // --- ヘルパー関数 ---
    function getHighestZIndex() {
        return Math.max(0, ...Array.from(document.querySelectorAll('.console-window')).map(el => parseFloat(window.getComputedStyle(el).zIndex) || 0));
    }

    function getTopWindow() {
        let highestZ = -1, topWindow = null;
        document.querySelectorAll('.console-window:not([style*="display: none"])').forEach(win => {
            const z = parseInt(window.getComputedStyle(win).zIndex) || 0;
            if (z >= highestZ) {
                highestZ = z; 
                topWindow = win; 
            }
        });
        return topWindow;
    }
    
    function showSnapIndicator(type) {
        const indicator = document.querySelector('.snap-indicator');
        const { innerWidth: w, innerHeight: h } = window;
        indicator.style.display = 'block';
        if (type === "left") Object.assign(indicator.style, { left: '0px', top: '0px', width: w / 2 + 'px', height: h + 'px' });
        else if (type === "right") Object.assign(indicator.style, { left: w / 2 + 'px', top: '0px', width: w / 2 + 'px', height: h + 'px' });
        else if (type === "top") Object.assign(indicator.style, { left: '0px', top: '0px', width: w + 'px', height: h + 'px' });
    }

    function hideContextMenu() {
        const menu = document.querySelector('.custom-context-menu');
        if(menu) menu.remove();
        contextMenuTargetWindow = null;
    }

    function executeContextMenuAction(win, action) {
        if (!win) return;
        const mouseEventInit = {
            bubbles: true,
            cancelable: true,
            clientX: lastMousePosition.x,
            clientY: lastMousePosition.y
        };

        switch(action) {
            case 'move':
                win.querySelector('.title-bar').dispatchEvent(new MouseEvent('mousedown', mouseEventInit));
                break;
            case 'resize':
                win.querySelector('.resizer.bottom-right').dispatchEvent(new MouseEvent('mousedown', mouseEventInit));
                break;
            case 'minimize':
                minimizeWindow(win);
                break;
            case 'maximize':
                toggleMaximize(win);
                break;
            case 'close':
                closeWindow(win);
                break;
        }
    }

    function showContextMenu(x, y, win) {
        hideContextMenu();
        contextMenuTargetWindow = win;
        const menu = document.createElement('div');
        menu.className = 'custom-context-menu';

        const isMaximized = win.classList.contains('maximized');
        const isSnapped = win.classList.contains('snapped-left') || win.classList.contains('snapped-right');

        menu.innerHTML = `
            <div class="menu-item" data-action="move"><span>移動</span><span><u>M</u></span></div>
            <div class="menu-item" data-action="resize"><span>サイズ変更</span><span><u>S</u></span></div>
            <div class="separator"></div>
            <div class="menu-item" data-action="minimize"><span>最小化</span><span><u>N</u></span></div>
            <div class="menu-item" data-action="maximize"><span>${isMaximized || isSnapped ? '元に戻す' : '最大化'}</span><span><u>X</u></span></div>
            <div class="separator"></div>
            <div class="menu-item" data-action="close"><span>閉じる</span><span><u>C</u></span></div>
        `;
        document.body.appendChild(menu);

        const menuRect = menu.getBoundingClientRect();
        menu.style.left = `${Math.min(x, window.innerWidth - menuRect.width)}px`;
        menu.style.top = `${Math.min(y, window.innerHeight - menuRect.height)}px`;

        menu.addEventListener('click', (e) => {
            const item = e.target.closest('.menu-item');
            if (!item) return;
            const action = item.dataset.action;
            executeContextMenuAction(win, action);
            hideContextMenu();
        });
    }
    
    function hideSnapIndicator() {
        const indicator = document.querySelector('.snap-indicator');
        if(indicator) indicator.style.display = 'none';
    }

    function escapeHtml(text) {
        const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return text.replace(/[&<>"']/g, m => map[m]);
    }
    </script>
</body>
</html>
