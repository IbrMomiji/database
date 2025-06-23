<?php
// タイムゾーンを東京に設定
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/system/boot.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('getSafePath')) {
    function getSafePath($baseDir, $path) {
        $path = str_replace('\\', '/', $path);
        $path = '/' . trim($path, '/');
        $parts = explode('/', $path);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') continue;
            if ($part === '..') {
                if (!empty($safeParts)) array_pop($safeParts);
            } else {
                $part = preg_replace('/[\\\\\/:\*\?"<>|]/', '', $part);
                if($part !== '') $safeParts[] = $part;
            }
        }
        $finalPath = $baseDir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $safeParts);
        $realBaseDir = realpath($baseDir);
        if ($realBaseDir === false) { return false; }
        $realFinalPath = realpath($finalPath);
        if ($realFinalPath !== false) {
            if (strpos($realFinalPath, $realBaseDir) !== 0) return false;
            return $realFinalPath;
        } else {
            $parentDir = dirname($finalPath);
            $realParentPath = realpath($parentDir);
            if ($realParentPath === false || strpos($realParentPath, $realBaseDir) !== 0) return false;
            return $realParentPath . DIRECTORY_SEPARATOR . basename($finalPath);
        }
    }
}

$share_id = $_GET['id'] ?? null;
if (!$share_id) {
    die('共有リンクが無効です。');
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT s.*, u.uuid as owner_uuid 
        FROM shares s 
        JOIN users u ON s.owner_user_id = u.id 
        WHERE s.share_id = :share_id
    ");
    $stmt->execute([':share_id' => $share_id]);
    $share = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$share) {
        die('共有リンクが見つかりません。');
    }

    if ($share['expires_at'] && new DateTime() > new DateTime($share['expires_at'])) {
        die('この共有リンクの有効期限は切れています。');
    }
    
    if ($share['password_hash']) {
        if (!isset($_SESSION['share_access'][$share_id]) || $_SESSION['share_access'][$share_id] !== true) {
             if (isset($_POST['password'])) {
                if (password_verify($_POST['password'], $share['password_hash'])) {
                    $_SESSION['share_access'][$share_id] = true;
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
                } else {
                    $password_error = 'パスワードが違います。';
                }
            }
            if (!isset($_SESSION['share_access'][$share_id]) || $_SESSION['share_access'][$share_id] !== true) {
                 header('Content-Type: text/html; charset=utf-8');
                 echo '<html><head><title>パスワード入力</title><style>body{font-family: sans-serif; text-align: center; padding-top: 50px;}</style></head><body>';
                 echo '<h2>このコンテンツはパスワードで保護されています</h2>';
                 if (isset($password_error)) echo '<p style="color:red;">' . htmlspecialchars($password_error) . '</p>';
                 echo '<form method="POST" action="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '"><label for="password">パスワード: </label><input type="password" name="password" id="password" autofocus><button type="submit">送信</button></form>';
                 echo '</body></html>';
                 exit;
            }
        }
    }

    if ($share['share_type'] === 'private') {
        if (!isset($_SESSION['user_id'])) {
            die('このコンテンツにアクセスするにはログインが必要です。');
        }
        $stmt_recipient = $db->prepare("SELECT 1 FROM share_recipients WHERE share_id = :share_id AND recipient_user_id = :user_id");
        $stmt_recipient->execute([':share_id' => $share['id'], ':user_id' => $_SESSION['user_id']]);
        if (!$stmt_recipient->fetch()) {
            die('あなたはこの共有コンテンツにアクセスする権限がありません。');
        }
    }

    $owner_user_dir = USER_DIR_PATH . '/' . $share['owner_uuid'];
    $share_root_path = getSafePath($owner_user_dir, $share['source_path']);

    if (!$share_root_path || !file_exists($share_root_path)) {
        die('共有されているファイルまたはフォルダが見つかりません。');
    }

    $relative_path_req = $_GET['path'] ?? '';
    $path_parts = explode('/', $relative_path_req);
    $safe_path_parts = [];
    foreach ($path_parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') die('無効なパスです。');
        $safe_path_parts[] = $part;
    }
    $safe_relative_path = implode('/', $safe_path_parts);
    
    $current_item_path = $share_root_path . ($safe_relative_path ? DIRECTORY_SEPARATOR . $safe_relative_path : '');
    
    if (strpos(realpath($current_item_path), realpath($share_root_path)) !== 0) {
        die('不正なアクセスです。');
    }

    if (!file_exists($current_item_path)) {
        die('指定されたファイルまたはフォルダは存在しません。');
    }
    
    if (is_dir($current_item_path)) {
        header('Content-Type: text/html; charset=utf-8');
        $base_name = basename($share_root_path);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>共有フォルダ: <?php echo htmlspecialchars($base_name); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; margin: 0; background-color: #f8f9fa; }
        .container { max-width: 900px; margin: 20px auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { font-size: 1.5rem; margin-bottom: 20px; word-break: break-all; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background-color: #f1f3f5; }
        a { text-decoration: none; color: #007bff; }
        a:hover { text-decoration: underline; }
        .icon { display: inline-block; width: 20px; text-align: center; margin-right: 10px; }
        .breadcrumb { margin-bottom: 20px; font-size: 1.1rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>共有アイテム: <?php echo htmlspecialchars($base_name); ?></h1>
        <div class="breadcrumb">
            <a href="?id=<?php echo htmlspecialchars($share_id); ?>">ルート</a> / <?php echo htmlspecialchars($safe_relative_path); ?>
        </div>
        <table>
            <thead><tr><th>名前</th><th>サイズ</th></tr></thead>
            <tbody>
<?php
        if ($safe_relative_path) {
            $parent_path = dirname($safe_relative_path);
            if ($parent_path === '.') $parent_path = '';
            echo '<tr><td><span class="icon">📁</span><a href="?id=' . htmlspecialchars($share_id) . '&path=' . urlencode($parent_path) . '">.. (親ディレクトリへ)</a></td><td>-</td></tr>';
        }

        $items = scandir($current_item_path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $item_full_path = $current_item_path . DIRECTORY_SEPARATOR . $item;
            $item_relative_path = $safe_relative_path ? $safe_relative_path . '/' . $item : $item;
            if (is_dir($item_full_path)) {
                echo '<tr><td><span class="icon">📁</span><a href="?id=' . htmlspecialchars($share_id) . '&path=' . urlencode($item_relative_path) . '">' . htmlspecialchars($item) . '</a></td><td>-</td></tr>';
            } else {
                echo '<tr><td><span class="icon">📄</span><a href="?id=' . htmlspecialchars($share_id) . '&path=' . urlencode($item_relative_path) . '">' . htmlspecialchars($item) . '</a></td><td>' . round(filesize($item_full_path) / 1024, 2) . ' KB</td></tr>';
            }
        }
?>
            </tbody>
        </table>
    </div>
</body>
</html>
<?php
    } else {
        $base_name = basename($current_item_path);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $base_name . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($current_item_path));
        flush();
        readfile($current_item_path);
        exit;
    }

} catch (Exception $e) {
    header('Content-Type: text/html; charset=utf-8');
    die('エラーが発生しました: ' . $e->getMessage());
}
?>