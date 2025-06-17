<?php
// =================================================================
// アプリケーション初期設定ファイル (boot.php)
//
// - セッション管理
// - 定数定義
// - エラーハンドリング
// - クラスの自動読み込み
// - POSTリクエストのルーティング
// =================================================================

// --- セッション管理 ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// POSTリクエスト以外でアクセスされた場合はセッションをリセット
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION = [];
    session_destroy();
    session_start();
}

// --- エラーハンドリング ---
error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// --- 定数定義 ---
define('DB_PATH', __DIR__ . '/../db/database.sqlite');
define('USER_DIR_PATH', __DIR__ . '/../user');

// --- クラスの自動読み込み (PSR-4風) ---
spl_autoload_register(function ($class_name) {
    // 通常のクラス (Auth, Database, CommandHandlerなど)
    $file = __DIR__ . '/' . $class_name . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }
    
    // コマンドクラス (HelpCommand, LoginCommandなど)
    // "Command"で終わるクラス名をコマンドと見なす
    if (substr($class_name, -7) === 'Command') {
        // 例: LoginCommand -> login
        $command_name_base = strtolower(substr($class_name, 0, -7)); 
        
        // 検索するディレクトリパス
        $paths_to_check = [
            __DIR__ . '/commands/guest/' . $class_name . '.php',
            __DIR__ . '/commands/login/' . $class_name . '.php',
            __DIR__ . '/commands/' . $class_name . '.php',
        ];

        foreach ($paths_to_check as $command_file) {
            if (file_exists($command_file)) {
                require_once $command_file;
                return;
            }
        }
    }

    // ICommandインターフェース
    if ($class_name === 'ICommand') {
        $iface_file = __DIR__ . '/commands/ICommand.php';
        if (file_exists($iface_file)) {
            require_once $iface_file;
        }
    }
});


// --- POSTリクエスト処理 (APIエンドポイント) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    $postData = json_decode(file_get_contents('php://input'), true);
    $commandStr = $postData['command'] ?? '';
    $currentPrompt = $postData['current_prompt'] ?? '';

    try {
        $handler = new CommandHandler();
        $response = $handler->handle($commandStr);

        if (empty($response['clear'])) {
            if(!isset($_SESSION['history'])) $_SESSION['history'] = [];
            
            if($commandStr) {
                 $_SESSION['history'][] = '<div>' . htmlspecialchars($currentPrompt, ENT_QUOTES, 'UTF-8') . htmlspecialchars($commandStr, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            if (!empty($response['output'])) {
                 $_SESSION['history'][] = '<div>' . $response['output'] . '</div>';
            }
        }

        echo json_encode($response);

    } catch (Exception $e) {
        http_response_code(500);
        $auth = new Auth();
        echo json_encode([
            'output' => '<span class="error">サーバーエラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span>',
            'prompt' => $auth->getPrompt(), 
            'clear' => false
        ]);
    }
    exit;
}
