<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'],
    'path' => '/',
    'domain' => $cookieParams['domain'],
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('DB_PATH', __DIR__ . '/../db/database.sqlite');
define('USER_DIR_PATH', __DIR__ . '/../users');

spl_autoload_register(function ($class_name) {
    $file = __DIR__ . '/' . $class_name . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    if ($class_name === 'ICommand') {
        $iface_file = __DIR__ . '/commands/ICommand.php';
        if (file_exists($iface_file)) {
            require_once $iface_file;
            return;
        }
    }
    
    if (substr($class_name, -7) === 'Command') {
        $command_dirs = [
            __DIR__ . '/commands/guest/',
            __DIR__ . '/commands/login/',
            __DIR__ . '/commands/login/account/',
        ];

        foreach ($command_dirs as $dir) {
            $command_file = $dir . $class_name . '.php';
            if (file_exists($command_file)) {
                require_once $command_file;
                return;
            }
        }
    }
});


if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['REQUEST_URI'], '/system/application/') === false) {
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