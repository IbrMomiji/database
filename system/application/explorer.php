<?php
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../boot.php';

if (!isset($_SESSION['user_id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'アクセス権がありません。ログインしてください。']);
    } else {
        die('アクセス権がありません。ログインしてください。');
    }
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT username, uuid FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception('ユーザー情報が見つかりません。再ログインしてください。');
    }
    $username = $user['username'];
    $user_uuid = $user['uuid'];
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
    exit;
}

define('SETTINGS_DIR', '.settings');
define('FAVORITES_FILE', SETTINGS_DIR . '/.favorites.json');
define('MAX_STORAGE_MB', 100);
define('MAX_STORAGE_BYTES', MAX_STORAGE_MB * 1024 * 1024);

$user_dir = USER_DIR_PATH . '/' . $user_uuid;

if (!is_dir($user_dir)) {
    mkdir($user_dir, 0775, true);
}
$settings_path = $user_dir . '/' . SETTINGS_DIR;
if (!is_dir($settings_path)) {
    mkdir($settings_path, 0775, true);
}

function getSafePath($baseDir, $path)
{
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
            if ($part !== '') $safeParts[] = $part;
        }
    }
    $finalPath = $baseDir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $safeParts);
    $realBaseDir = realpath($baseDir);
    if ($realBaseDir === false) {
        return false;
    }
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

function getDirectorySize($dir)
{
    if (!is_dir($dir) || !is_readable($dir)) return 0;
    $size = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isReadable() && $file->isFile()) {
                if (strpos($file->getPathname(), realpath($GLOBALS['user_dir'] . '/' . SETTINGS_DIR)) === 0) {
                    continue;
                }
                $size += $file->getSize();
            }
        }
    } catch (Exception $e) {
        return 0;
    }
    return $size;
}

function formatBytes($bytes)
{
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getFileType($filePath)
{
    if (is_dir($filePath)) return 'ファイル フォルダー';
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $types = ['txt' => 'テキスト ドキュメント', 'pdf' => 'PDF ドキュメント', 'jpg' => 'JPEG 画像', 'jpeg' => 'JPEG 画像', 'png' => 'PNG 画像', 'gif' => 'GIF 画像', 'svg' => 'SVG 画像', 'zip' => '圧縮 (zip) フォルダー', 'rar' => 'WinRAR 書庫', 'doc' => 'Microsoft Word 文書', 'docx' => 'Microsoft Word 文書', 'xls' => 'Microsoft Excel ワークシート', 'xlsx' => 'Microsoft Excel ワークシート', 'ppt' => 'Microsoft PowerPoint プレゼンテーション', 'pptx' => 'Microsoft PowerPoint プレゼンテーション', 'php' => 'PHP ファイル', 'html' => 'HTML ドキュメント', 'css' => 'CSS スタイルシート', 'js' => 'JavaScript ファイル',];
    return $types[$ext] ?? strtoupper($ext) . ' ファイル';
}

function deleteDirectoryRecursively($dir)
{
    if (!is_dir($dir)) return false;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $fileinfo) {
        $path = $fileinfo->getRealPath();
        if ($fileinfo->isDir()) {
            @chmod($path, 0777);
            @rmdir($path);
        } else {
            @chmod($path, 0777);
            @unlink($path);
        }
    }
    return @rmdir($dir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $path = $_POST['path'] ?? '/';

    try {
        if (!in_array($action, ['get_usage', 'search', 'get_favorites', 'save_favorites', 'create_share', 'stop_share'])) {
            $safe_path = getSafePath($user_dir, $path);
            if ($safe_path === false) {
                throw new Exception('無効なパスです。');
            }
        }

        switch ($action) {
            case 'list':
                $files = [];
                if (!is_dir($safe_path) || !is_readable($safe_path)) {
                    echo json_encode(['success' => true, 'path' => $path, 'files' => []]);
                    exit;
                }
                $items = scandir($safe_path);
                if ($items === false) {
                    throw new Exception("ディレクトリの読み込みに失敗しました。パーミッションを確認してください。");
                }
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $item_path = $safe_path . DIRECTORY_SEPARATOR . $item;
                    clearstatcache(true, $item_path);
                    $is_dir = is_dir($item_path);
                    $is_system = ($item === SETTINGS_DIR || $item === '.logs');
                    $files[] = [
                        'name' => $item,
                        'is_dir' => $is_dir,
                        'type' => getFileType($item_path),
                        'size' => $is_dir ? '' : formatBytes(filesize($item_path)),
                        'modified' => date('Y/m/d H:i', filemtime($item_path)),
                        'is_system' => $is_system
                    ];
                }
                usort($files, function ($a, $b) {
                    if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
                    return strcasecmp($a['name'], $b['name']);
                });
                echo json_encode(['success' => true, 'path' => $path, 'files' => $files]);
                break;

            case 'move':
                $items_to_move = json_decode($_POST['items'] ?? '[]', true);
                $destination_path = $_POST['destination'] ?? '';
                if (empty($items_to_move) || empty($destination_path)) {
                    throw new Exception('移動するアイテムまたは移動先が指定されていません。');
                }
                $dest_dir_path = getSafePath($user_dir, $destination_path);
                if ($dest_dir_path === false || !is_dir($dest_dir_path)) {
                    throw new Exception('無効な移動先ディレクトリです。');
                }
                $errors = [];
                $moved_items_log = [];
                foreach ($items_to_move as $item) {
                    $source_path = getSafePath($user_dir, $item['path']);
                    if ($source_path === false || !file_exists($source_path)) {
                        $errors[] = "'{$item['name']}' が見つかりません。";
                        continue;
                    }
                    if (basename($source_path) === SETTINGS_DIR || basename($source_path) === '.logs') {
                        continue;
                    }
                    $new_path = $dest_dir_path . DIRECTORY_SEPARATOR . basename($source_path);
                    if (strpos($dest_dir_path, $source_path) === 0) {
                        $errors[] = "自分自身の中に '{$item['name']}' を移動することはできません。";
                        continue;
                    }
                    if (realpath($source_path) == realpath($new_path)) {
                        continue;
                    }
                    if (file_exists($new_path)) {
                        $errors[] = "移動先に同じ名前のアイテム '{$item['name']}' が既に存在します。";
                        continue;
                    }
                    if (rename($source_path, $new_path)) {
                        $moved_items_log[] = ['from' => $item['path'], 'to' => $destination_path . '/' . $item['name']];
                    } else {
                        $errors[] = "'{$item['name']}' の移動に失敗しました。";
                    }
                }
                if (!empty($moved_items_log)) {
                     Logger::log($user_uuid, 3001, 'Explorer', 'ファイル操作', Logger::INFO, count($moved_items_log) . '個のアイテムを移動しました。', ['items' => $moved_items_log]);
                }
                if (!empty($errors)) {
                    throw new Exception(implode("\n", $errors));
                }
                echo json_encode(['success' => true, 'message' => 'アイテムを移動しました。']);
                break;

            case 'search':
                $query = $_POST['query'] ?? '';
                if (empty($query)) throw new Exception('検索クエリがありません。');
                $results = [];
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($user_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iterator as $file) {
                    if (strpos($file->getPathname(), realpath($user_dir . '/' . SETTINGS_DIR)) === 0) continue;
                    if (strpos($file->getPathname(), realpath($user_dir . '/.logs')) === 0) continue;
                    if (stripos($file->getFilename(), $query) !== false) {
                        $item_path = $file->getRealPath();
                        $relative_path = str_replace(realpath($user_dir), '', $item_path);
                        $is_dir = $file->isDir();
                        $results[] = ['name' => $file->getFilename(), 'path' => str_replace('\\', '/', $relative_path), 'is_dir' => $is_dir, 'type' => getFileType($item_path), 'size' => $is_dir ? '' : formatBytes($file->getSize()), 'modified' => date('Y/m/d H:i', $file->getMTime())];
                    }
                }
                echo json_encode(['success' => true, 'files' => $results]);
                break;

            case 'get_usage':
                $used_size = getDirectorySize($user_dir);
                echo json_encode(['success' => true, 'used' => $used_size, 'total' => MAX_STORAGE_BYTES, 'used_formatted' => formatBytes($used_size), 'total_formatted' => formatBytes(MAX_STORAGE_BYTES)]);
                break;

            case 'upload':
                if (empty($_FILES['files']['name'][0])) throw new Exception('アップロードされたファイルがありません。');
                $total_size = array_sum($_FILES['files']['size']);
                if (getDirectorySize($user_dir) + $total_size > MAX_STORAGE_BYTES) throw new Exception('ストレージ容量不足です。');
                $relative_paths = isset($_POST['relative_paths']) ? json_decode($_POST['relative_paths'], true) : [];
                $uploaded_files_log = [];
                foreach ($_FILES['files']['tmp_name'] as $index => $tmp_name) {
                    $file_name = count($relative_paths) > 0 ? $relative_paths[$index] : $_FILES['files']['name'][$index];
                    $target_path = $safe_path . DIRECTORY_SEPARATOR . $file_name;
                    $dir_path = dirname($target_path);
                    if (!is_dir($dir_path)) mkdir($dir_path, 0777, true);
                    if (!move_uploaded_file($tmp_name, $target_path)) {
                        throw new Exception("{$file_name} のアップロードに失敗しました。");
                    }
                    $uploaded_files_log[] = $path . '/' . $file_name;
                }
                Logger::log($user_uuid, 3002, 'Explorer', 'ファイル操作', Logger::INFO, count($uploaded_files_log) . '個のファイルをアップロードしました。', ['files' => $uploaded_files_log]);
                echo json_encode(['success' => true, 'message' => 'アップロードが完了しました。']);
                break;

            case 'create_folder':
                $folder_name = $_POST['name'] ?? '';
                if (empty($folder_name) || preg_match('/[\\\\\/:\*\?"<>|]/', $folder_name)) throw new Exception('無効なフォルダ名です。');
                $new_folder_path = $safe_path . DIRECTORY_SEPARATOR . $folder_name;
                if (file_exists($new_folder_path)) throw new Exception('同じ名前のアイテムが存在します。');
                if (!mkdir($new_folder_path, 0777, true)) throw new Exception('フォルダの作成に失敗しました。');
                $log_path = $path === '/' ? '/' . $folder_name : $path . '/' . $folder_name;
                Logger::log($user_uuid, 3003, 'Explorer', 'ファイル操作', Logger::INFO, "フォルダ「{$folder_name}」を作成しました。", ['path' => $log_path]);
                echo json_encode(['success' => true, 'message' => 'フォルダを作成しました。']);
                break;

            case 'create_file':
                $file_name = $_POST['name'] ?? '';
                if (empty($file_name) || preg_match('/[\\\\\/:\*\?"<>|]/', $file_name)) throw new Exception('無効なファイル名です。');
                $new_file_path = $safe_path . DIRECTORY_SEPARATOR . $file_name;
                if (file_exists($new_file_path)) throw new Exception('同じ名前のファイルが既に存在します。');
                if (file_put_contents($new_file_path, '') === false) throw new Exception('ファイルの作成に失敗しました。');
                $log_path = $path === '/' ? '/' . $file_name : $path . '/' . $file_name;
                Logger::log($user_uuid, 3004, 'Explorer', 'ファイル操作', Logger::INFO, "ファイル「{$file_name}」を作成しました。", ['path' => $log_path]);
                echo json_encode(['success' => true, 'message' => 'ファイルを作成しました。']);
                break;

            case 'rename':
                $old_name_path_rel = $_POST['item_path'] ?? '';
                $new_name = $_POST['new_name'] ?? '';
                if (empty($old_name_path_rel) || empty($new_name) || preg_match('/[\\\\\/:\*\?"<>|]/', $new_name)) throw new Exception('無効なファイル名です。');
                $old_path = getSafePath($user_dir, $old_name_path_rel);
                $old_name = basename($old_path);
                if ($old_name === SETTINGS_DIR || $old_name === '.logs') throw new Exception('システムフォルダの名前は変更できません。');
                $new_path = dirname($old_path) . DIRECTORY_SEPARATOR . $new_name;
                if ($old_path === false) throw new Exception('無効なパスです。');
                if (!file_exists($old_path)) throw new Exception('元のファイルが見つかりません。');
                if (file_exists($new_path)) throw new Exception('同じ名前のファイルが既に存在します。');
                if (!rename($old_path, $new_path)) throw new Exception('名前の変更に失敗しました。');
                $new_name_path_rel = dirname($old_name_path_rel) . '/' . $new_name;
                Logger::log($user_uuid, 3005, 'Explorer', 'ファイル操作', Logger::INFO, "「{$old_name}」を「{$new_name}」に名前変更しました。", ['from' => $old_name_path_rel, 'to' => $new_name_path_rel]);
                echo json_encode(['success' => true, 'message' => '名前を変更しました。']);
                break;

            case 'delete':
                $items = json_decode($_POST['items'] ?? '[]', true);
                if (empty($items)) throw new Exception('削除するアイテムが指定されていません。');
                $deleted_items_log = [];
                foreach ($items as $item) {
                    $item_path_full = getSafePath($user_dir, $item['path']);
                    if (basename($item_path_full) === SETTINGS_DIR || basename($item_path_full) === '.logs') continue;
                    if (!$item_path_full || !file_exists($item_path_full)) continue;
                    if (is_dir($item_path_full)) {
                        if (!deleteDirectoryRecursively($item_path_full)) throw new Exception("ディレクトリ '{$item['name']}' の削除に失敗しました。");
                    } else {
                        if (!unlink($item_path_full)) throw new Exception("ファイル '{$item['name']}' の削除に失敗しました。");
                    }
                    $deleted_items_log[] = $item['path'];
                }
                if (!empty($deleted_items_log)) {
                     Logger::log($user_uuid, 3006, 'Explorer', 'ファイル操作', Logger::WARNING, count($deleted_items_log) . '個のアイテムを削除しました。', ['items' => $deleted_items_log]);
                }
                echo json_encode(['success' => true, 'message' => 'アイテムを削除しました。']);
                break;

            case 'get_favorites':
                $fav_file = $user_dir . DIRECTORY_SEPARATOR . FAVORITES_FILE;
                if (file_exists($fav_file)) {
                    $fav_data = json_decode(file_get_contents($fav_file), true);
                    echo json_encode(['success' => true, 'favorites' => $fav_data ?? []]);
                } else {
                    echo json_encode(['success' => true, 'favorites' => []]);
                }
                break;

            case 'save_favorites':
                $favorites = json_decode($_POST['favorites'] ?? '[]', true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('無効なデータです。');
                }
                $fav_file = $user_dir . DIRECTORY_SEPARATOR . FAVORITES_FILE;
                file_put_contents($fav_file, json_encode($favorites, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'message' => 'お気に入りを保存しました。']);
                break;

            case 'create_share':
                $item_path = $_POST['item_path'] ?? '';
                if (empty($item_path)) throw new Exception('共有するアイテムが指定されていません。');
                $safe_item_path = getSafePath($user_dir, $item_path);
                if ($safe_item_path === false || !file_exists($safe_item_path)) {
                    throw new Exception('共有するアイテムが見つかりません。');
                }
                $share_id = bin2hex(random_bytes(16));
                $password = $_POST['password'] ?? null;
                $password_hash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
                $expires_at = !empty($_POST['expires_at']) ? date('Y-m-d H:i:s', strtotime($_POST['expires_at'])) : null;
                $stmt_check = $db->prepare("SELECT share_id FROM shares WHERE owner_user_id = :owner_user_id AND source_path = :source_path");
                $stmt_check->execute([':owner_user_id' => $_SESSION['user_id'], ':source_path' => $item_path]);
                $existing = $stmt_check->fetchColumn();
                if ($existing) {
                    $stmt_update = $db->prepare("UPDATE shares SET password_hash = :password_hash, expires_at = :expires_at WHERE share_id = :share_id");
                    $stmt_update->execute([':password_hash' => $password_hash, ':expires_at' => $expires_at, ':share_id' => $existing]);
                    $share_id_to_use = $existing;
                } else {
                    $stmt_insert = $db->prepare("INSERT INTO shares (share_id, owner_user_id, source_path, password_hash, expires_at) VALUES (:share_id, :owner_user_id, :source_path, :password_hash, :expires_at)");
                    $stmt_insert->execute([':share_id' => $share_id, ':owner_user_id' => $_SESSION['user_id'], ':source_path' => $item_path, ':password_hash' => $password_hash, ':expires_at' => $expires_at]);
                    $share_id_to_use = $share_id;
                }
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $base_uri = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $share_url = $protocol . $host . $base_uri . "/share.php?id=" . $share_id_to_use;
                Logger::log($user_uuid, 4001, 'Explorer', '共有', Logger::INFO, "アイテム「{$item_path}」を共有しました。", ['path' => $item_path]);
                echo json_encode(['success' => true, 'message' => '共有リンクを作成・更新しました。', 'url' => $share_url, 'password_hash' => !empty($password_hash)]);
                break;

            case 'stop_share':
                $item_path_stop = $_POST['item_path'] ?? '';
                 if (empty($item_path_stop)) throw new Exception('共有を停止するアイテムが指定されていません。');
                $stmt = $db->prepare("DELETE FROM shares WHERE owner_user_id = :owner_user_id AND source_path = :source_path");
                $stmt->execute([':owner_user_id' => $_SESSION['user_id'], ':source_path' => $item_path_stop]);
                Logger::log($user_uuid, 4002, 'Explorer', '共有', Logger::INFO, "アイテム「{$item_path_stop}」の共有を停止しました。", ['path' => $item_path_stop]);
                echo json_encode(['success' => true, 'message' => '共有を停止しました。']);
                break;
        }
    } catch (Exception $e) {
        http_response_code(500);
        Logger::log($user_uuid, 5000, 'Explorer', 'エラー', Logger::ERROR, 'ファイル操作エラー: ' . $e->getMessage(), ['action' => $action ?? 'unknown', 'path' => $path]);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download') {
    $file_to_download = getSafePath($user_dir, $_GET['file']);
    if ($file_to_download === false || !is_file($file_to_download)) {
        http_response_code(404);
        die('File not found.');
    }
    Logger::log($user_uuid, 3007, 'Explorer', 'ファイル操作', Logger::INFO, "ファイル「{$_GET['file']}」をダウンロードしました。", ['path' => $_GET['file']]);
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file_to_download) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_to_download));
    readfile($file_to_download);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explorer - <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root {
            --font-family: 'Yu Gothic UI', 'Segoe UI', Meiryo, system-ui, sans-serif;
            --bg-color: #1f1f1f;
            --bg-header: #2b2b2b;
            --bg-secondary-color: #2b2b2b;
            --bg-tertiary-color: #313131;
            --bg-hover: rgba(255, 255, 255, 0.05);
            --bg-selection: #004a7f;
            --border-color: #424242;
            --text-color: #ffffff;
            --text-secondary-color: #c5c5c5;
            --accent-color: #4cc2ff;
        }
        html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; font-family: var(--font-family); font-size: 14px; background: var(--bg-color); color: var(--text-color); }
        ::-webkit-scrollbar { width: 12px; }
        ::-webkit-scrollbar-track { background: var(--bg-color); }
        ::-webkit-scrollbar-thumb { background-color: #555; border-radius: 10px; border: 3px solid var(--bg-color); }
        ::-webkit-scrollbar-thumb:hover { background-color: #777; }
        .explorer-container { display: flex; flex-direction: column; height: 100%; position: relative; }
        .header { background: var(--bg-header); border-bottom: 1px solid var(--border-color); }
        .header-tabs { display: flex; padding: 0 12px; position: relative; }
        .tab-item { padding: 10px 16px; font-size: 13px; cursor: pointer; border-bottom: 2px solid transparent; }
        .tab-item.active { border-bottom-color: var(--accent-color); font-weight: 500; }
        .tab-item:not(.active):hover { background: var(--bg-hover); }
        .toolbar { display: none; align-items: stretch; gap: 1px; padding: 8px 12px; background: #202020; border-bottom: 1px solid var(--border-color); }
        .toolbar.active { display: flex; }
        .ribbon-group { display: flex; flex-direction: column; align-items: center; padding: 0 12px; }
        .ribbon-buttons { display: flex; align-items: flex-start; height: 100%; gap: 2px; }
        .ribbon-group .group-label { font-size: 12px; color: var(--text-secondary-color); margin-top: 4px; }
        .toolbar button { background: none; border: 1px solid transparent; color: var(--text-color); padding: 4px; border-radius: 4px; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; width: auto; min-width: 70px; height: 60px; font-family: var(--font-family); }
        .toolbar button.toggle-btn.active { background: var(--bg-selection); }
        .toolbar button:hover { background: var(--bg-hover); }
        .toolbar button:disabled { cursor: not-allowed; opacity: 0.4; }
        .toolbar .icon { width: 24px; height: 24px; }
        .toolbar .label { font-size: 12px; white-space: nowrap; }
        .ribbon-separator { width: 1px; background: var(--border-color); }
        .address-bar-container { display: flex; padding: 8px 12px; align-items: center; gap: 8px; }
        .address-bar-nav { display: flex; flex-direction: row; }
        .address-bar-nav button { background: none; border: 1px solid transparent; color: var(--text-color); padding: 6px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; }
        .address-bar-nav button:not(:disabled):hover { background: var(--bg-hover); }
        .address-bar-nav button:disabled { opacity: 0.4; cursor: not-allowed; }
        .address-bar-nav .icon { width: 16px; height: 16px; }
        .address-bar { flex-grow: 1; display: flex; align-items: center; background: var(--bg-tertiary-color); border: 1px solid var(--border-color); border-radius: 4px; padding: 2px 4px; }
        .address-bar-part { padding: 4px 8px; cursor: pointer; border-radius: 4px; white-space: nowrap; color: var(--text-secondary-color); }
        .address-bar-part:hover { background: var(--bg-hover); }
        .address-bar-separator { color: var(--text-secondary-color); padding: 0 4px; }
        .address-input { flex-grow: 1; background: var(--bg-tertiary-color); border: 1px solid var(--accent-color); color: var(--text-color); padding: 6px 10px; outline: none; border-radius: 4px; }
        .search-box { background: var(--bg-tertiary-color); border: 1px solid var(--border-color); color: var(--text-color); border-radius: 4px; padding: 6px 10px; width: 200px; }
        .search-box:focus { border-color: var(--accent-color); }
        .main-content { flex: 1; display: flex; overflow: hidden; }
        .nav-pane { width: 240px; background: var(--bg-color); border-right: 1px solid var(--border-color); padding: 8px; overflow-y: auto; user-select: none; }
        .nav-group-header { padding: 10px 4px 4px; font-size: 12px; color: var(--text-secondary-color); font-weight: bold; }
        .nav-item { padding: 6px 10px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .nav-item:hover { background: var(--bg-hover); }
        .nav-item.selected { background: var(--bg-hover); }
        .nav-item.active { background: var(--bg-selection); }
        .nav-item .icon { width: 20px; height: 20px; flex-shrink: 0; }
        .nav-item.nested { padding-left: 28px; }
        .content-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; user-select: none; position: relative; }
        .file-grid-view { display: none; flex-wrap: wrap; align-content: flex-start; gap: 16px; padding: 16px; overflow-y: auto; }
        .grid-item { display: flex; flex-direction: column; align-items: center; width: 100px; padding: 8px; border-radius: 4px; cursor: pointer; }
        .grid-item:hover { background: var(--bg-hover); }
        .grid-item.selected { background: var(--bg-selection); }
        .grid-item .icon { width: 64px; height: 64px; }
        .grid-item .name { font-size: 12px; text-align: center; margin-top: 8px; word-break: break-all; }
        .file-table-view { flex: 1; overflow: auto; display: block; }
        .file-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .file-table th { background: rgba(31, 31, 31, 0.8); backdrop-filter: blur(10px); padding: 8px 16px; text-align: left; font-weight: normal; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; color: var(--text-secondary-color); }
        .file-table td { padding: 8px 16px; border-bottom: 1px solid var(--border-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: default; }
        .file-item.cut, .grid-item.cut { opacity: 0.5; }
        .col-check { width: 40px; display: none; }
        .col-name { width: 50%; }
        .col-modified { width: 20%; }
        .col-type { width: 20%; }
        .col-size { width: 10%; }
        .explorer-container.show-checkboxes .col-check { display: table-cell; }
        .file-table tr.search-result .path { font-size: 12px; color: var(--text-secondary-color); }
        .file-table tr:hover { background: var(--bg-hover); }
        .file-table tr.selected { background: var(--bg-selection) !important; color: white; }
        .file-table tr.selected .path { color: #ccc; }
        .file-table tr.empty-message td { text-align: center; padding: 40px; color: var(--text-secondary-color); }
        .item-name-container { display: flex; align-items: center; gap: 8px; }
        .item-name-container input { background: var(--bg-color); color: var(--text-color); border: 1px solid var(--accent-color); padding: 4px; border-radius: 4px; outline: none; flex-grow: 1; }
        .file-table .icon { width: 20px; height: 20px; flex-shrink: 0; }
        #preview-pane { display: none; width: 300px; flex-shrink: 0; border-left: 1px solid var(--border-color); padding: 16px; overflow-y: auto; text-align: center; }
        #preview-pane.active { display: block; }
        #preview-pane img, #preview-pane video { max-width: 100%; border-radius: 4px; }
        #preview-placeholder { color: var(--text-secondary-color); }
        .status-bar { padding: 4px 12px; background: var(--bg-secondary-color); border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; font-size: 12px; }
        .usage-display { display: flex; align-items: center; gap: 8px; }
        .usage-bar { width: 150px; height: 14px; background: var(--bg-tertiary-color); border-radius: 4px; overflow: hidden; border: 1px solid var(--border-color); }
        .usage-fill { height: 100%; background: var(--accent-color); width: 0%; transition: width 0.5s ease; }
        #drag-drop-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); border: 2px dashed var(--accent-color); display: none; justify-content: center; align-items: center; font-size: 24px; color: var(--text-color); z-index: 9999; pointer-events: none; }
        #drag-drop-overlay.visible { display: flex; }
        .context-menu { position: fixed; z-index: 1001; background: #2b2b2b; border: 1px solid #454545; min-width: 250px; padding: 4px; box-shadow: 0 8px 16px rgba(0, 0, 0, 0.4); border-radius: 8px; }
        .context-menu-item { position: relative; padding: 6px 12px; cursor: pointer; white-space: nowrap; display: flex; justify-content: space-between; align-items: center; user-select: none; border-radius: 4px; }
        .context-menu-item.disabled { opacity: 0.5; cursor: default; background: none !important; }
        .context-menu-item:not(.disabled):hover { background: var(--bg-hover); }
        .context-menu-item .label { display: flex; align-items: center; gap: 12px; }
        .context-menu-item .icon { width: 16px; height: 16px; fill: var(--text-color); }
        .context-menu-item .hint { color: var(--text-secondary-color); font-size: 12px; }
        .context-menu-separator { height: 1px; background: #454545; margin: 4px; }
        .context-menu-item.has-submenu::after { content: '▶'; font-size: 10px; }
        .submenu { display: none; position: absolute; left: 100%; top: -5px; background: #2b2b2b; border: 1px solid #454545; min-width: 180px; padding: 4px; box-shadow: 0 8px 16px rgba(0, 0, 0, 0.4); border-radius: 8px; z-index: 1002; }
        #selection-rectangle { position: absolute; border: 1px solid var(--accent-color); background-color: rgba(76, 194, 255, 0.2); pointer-events: none; z-index: 999; }
    </style>
</head>
<body>
    <div class="explorer-container">
        <div class="header">
            <div class="header-tabs">
                <div class="tab-item" data-toolbar="file-toolbar">ファイル</div>
                <div class="tab-item active" data-toolbar="home-toolbar">ホーム</div>
                <div class="tab-item" data-toolbar="share-toolbar">共有</div>
                <div class="tab-item" data-toolbar="view-toolbar">表示</div>
            </div>
            <div id="file-toolbar" class="toolbar">
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="upload-file-btn"><span class="icon" id="icon-upload-file"></span><span class="label">ファイル</span></button>
                        <button id="upload-folder-btn"><span class="icon" id="icon-upload-folder"></span><span class="label">フォルダー</span></button>
                    </div>
                </div>
            </div>
            <div id="home-toolbar" class="toolbar active">
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="paste-btn" disabled><span class="icon" id="icon-paste"></span><span class="label">貼り付け</span></button>
                        <button id="copy-btn" disabled><span class="icon" id="icon-copy"></span><span class="label">コピー</span></button>
                        <button id="cut-btn" disabled><span class="icon" id="icon-cut"></span><span class="label">切り取り</span></button>
                    </div>
                </div>
                <div class="ribbon-separator"></div>
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="delete-btn" disabled><span class="icon" id="icon-delete"></span><span class="label">削除</span></button>
                        <button id="rename-btn" disabled><span class="icon" id="icon-rename"></span><span class="label">名前の変更</span></button>
                    </div>
                </div>
                <div class="ribbon-separator"></div>
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="new-folder-btn"><span class="icon" id="icon-new-folder"></span><span class="label">新しいフォルダー</span></button>
                    </div>
                </div>
            </div>
            <div id="share-toolbar" class="toolbar">
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="share-btn" disabled><span class="icon" id="icon-share"></span><span class="label">選択項目<br>の共有</span></button>
                        <button id="manage-shares-btn"><span class="icon" id="icon-share-manage"></span><span class="label">共有の<br>管理</span></button>
                    </div>
                </div>
            </div>
            <div id="view-toolbar" class="toolbar">
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="preview-pane-btn" class="toggle-btn"><span class="icon" id="icon-preview-pane"></span><span class="label">プレビュー</span></button>
                    </div>
                </div>
                <div class="ribbon-separator"></div>
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="large-icons-btn" class="layout-btn"><span class="icon" id="icon-large-icons"></span><span class="label">大アイコン</span></button>
                        <button id="details-btn" class="layout-btn active"><span class="icon" id="icon-details"></span><span class="label">詳細</span></button>
                    </div>
                </div>
                <div class="ribbon-separator"></div>
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="item-checkboxes-btn" class="toggle-btn"><span class="icon" id="icon-checkboxes"></span><span class="label">チェック<br>ボックス</span></button>
                    </div>
                </div>
            </div>
            <div class="address-bar-container">
                <div class="address-bar-nav">
                    <button id="nav-back-btn" disabled title="戻る"><span class="icon" id="icon-back"></span></button>
                    <button id="nav-forward-btn" disabled title="進む"><span class="icon" id="icon-forward"></span></button>
                    <button id="nav-up-btn" disabled title="上に移動"><span class="icon" id="icon-up"></span></button>
                </div>
                <div id="address-bar" class="address-bar"></div>
                <input type="text" id="address-input" class="address-input" style="display: none;">
                <input type="search" id="search-box" class="search-box" placeholder="検索">
            </div>
        </div>
        <div class="main-content">
            <div class="nav-pane" id="nav-pane">
                <div id="favorites-section">
                    <div class="nav-group-header">お気に入り</div>
                    <div id="favorites-list"></div>
                </div>
                <div class="nav-group-header" style="margin-top: 16px;">PC</div>
                <div class="nav-item active" id="nav-home" data-path="/"><span class="icon" id="icon-user-folder"></span> <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="content-area" id="content-area">
                <div class="file-table-view" id="file-table-view">
                    <table class="file-table">
                        <thead>
                            <tr>
                                <th class="col-check"><input type="checkbox" id="select-all-checkbox"></th>
                                <th class="col-name">名前</th>
                                <th class="col-modified">更新日時</th>
                                <th class="col-type">種類</th>
                                <th class="col-size">サイズ</th>
                            </tr>
                        </thead>
                        <tbody id="file-list-body"></tbody>
                    </table>
                </div>
                <div class="file-grid-view" id="file-grid-view"></div>
                <div id="selection-rectangle" style="display: none;"></div>
            </div>
            <div id="preview-pane">
                <div id="preview-placeholder">プレビューするファイルを選択してください</div>
                <div id="preview-content"></div>
            </div>
        </div>
        <div class="status-bar">
            <div id="item-count">0 項目</div>
            <div id="usage-display" class="usage-display">
                <div id="usage-bar" class="usage-bar">
                    <div id="usage-fill" class="usage-fill"></div>
                </div>
                <span id="usage-text"></span>
            </div>
        </div>
    </div>
    <input type="file" id="file-input" multiple hidden><input type="file" id="folder-input" webkitdirectory directory multiple hidden>
    <div id="drag-drop-overlay">ファイルをここにドロップ</div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const getEl = (id) => document.getElementById(id);
            const addEventListenerIfPresent = (id, event, handler) => {
                const el = getEl(id);
                if (el) el.addEventListener(event, handler);
            };
            const fileListBody = getEl('file-list-body');
            const itemCountEl = getEl('item-count');
            const dragDropOverlay = getEl('drag-drop-overlay');
            const addressBar = getEl('address-bar');
            const navBackBtn = getEl('nav-back-btn');
            const navUpBtn = getEl('nav-up-btn');
            const navForwardBtn = getEl('nav-forward-btn');
            const fileInput = getEl('file-input');
            const folderInput = getEl('folder-input');
            const usageFill = getEl('usage-fill');
            const usageText = getEl('usage-text');
            const searchBox = getEl('search-box');
            const addressInput = getEl('address-input');
            const navPane = getEl('nav-pane');
            const favoritesListEl = getEl('favorites-list');
            const fileTableView = getEl('file-table-view');
            const fileGridView = getEl('file-grid-view');
            const previewPane = getEl('preview-pane');
            const previewPlaceholder = getEl('preview-placeholder');
            const previewContent = getEl('preview-content');
            const explorerContainer = document.querySelector('.explorer-container');
            const contentArea = getEl('content-area');
            const selectionRectangle = getEl('selection-rectangle');
            const shareBtn = getEl('share-btn');
            const manageSharesBtn = getEl('manage-shares-btn');
            let currentPath = '/',
                selectedItems = new Map(),
                contextMenu = null,
                isSearchMode = false,
                favorites = [],
                currentLayout = 'details';
            let clipboard = {
                type: null,
                items: []
            };
            let isSelecting = false,
                startX = 0,
                startY = 0,
                contentAreaRect;
            const history = {
                past: [],
                future: []
            };
            const ICONS = {
                'back': '<svg fill="currentColor" viewBox="0 0 16 16"><path d="M7.78 12.53a.75.75 0 0 1-1.06 0L2.47 8.28a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 1.06L4.81 7.5h8.44a.75.75 0 0 1 0 1.5H4.81l2.97 2.97a.75.75 0 0 1 0 1.06z"/></svg>',
                'forward': '<svg fill="currentColor" viewBox="0 0 16 16"><path d="M8.22 3.47a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06l2.97-2.97H2.75a.75.75 0 0 1 0-1.5h8.44L8.22 4.53a.75.75 0 0 1 0-1.06z"/></svg>',
                'up': '<svg fill="currentColor" viewBox="0 0 16 16"><path d="M8 3.25a.75.75 0 0 1 .75.75v8.19l2.22-2.22a.75.75 0 1 1 1.06 1.06l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 1 1 1.06-1.06l2.22 2.22V4a.75.75 0 0 1 .75-.75z"/></svg>',
                'paste': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M19 2h-4.18C14.4.84 13.3 0 12 0S9.6.84 9.18 2H5c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm7 18H5V4h2v3h10V4h2v16z"/></svg>',
                'copy': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>',
                'cut': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M9.64 7.64c.2-.2.2-.51 0-.71L8.35 5.64c-.2-.2-.51-.2-.71 0L4 9.29V5c0-1.1-.9-2-2-2s-2 .9-2 2v14c0 1.1.9 2 2 2h4v-3.29l3.64-3.64-1-1.01L7.35 15.35l-1.41-1.41 4.24-4.24-1-1.01L7.83 12.05l-1.41-1.41 3.22-3.22zm4.07-4.07c.2-.2.51-.2.71 0l1.29 1.29c.2.2.2.51 0 .71L11.35 9.9c-.2.2-.51.2-.71 0l-1.29-1.29a.512.512 0 0 1 0-.71l4.36-4.35zM22 10h-4c-1.1 0-2 .9-2 2v4c0 1.1.9 2 2 2h4c1.1 0 2-.9 2-2v-4c0-1.1-.9-2-2-2zm0 6h-4v-4h4v4z"/></svg>',
                'rename': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83zM3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM5.92 19H5v-.92l9.06-9.06.92.92L5.92 19z"/></svg>',
                'delete': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>',
                'new-folder': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm0 12H4V6h5.17l2 2H20v10zm-8-4h2v2h-2v-2zm-4 0h2v2H8v-2zm8 0h2v2h-2v-2z"/></svg>',
                'upload-file': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5 20h14v-2H5v2zm0-10h4v6h6v-6h4l-7-7-7 7z"/></svg>',
                'upload-folder': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M11 5c0-1.1.9-2 2-2h6c1.1 0 2 .9 2 2v14c0 1.1-.9 2-2 2H3c-1.1 0-2-.9-2-2V7c0-1.1.9-2 2-2h5l2 2h3z"/></svg>',
                'folder': '<svg fill="#FFCA28" viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>',
                'file': '<svg fill="#E0E0E0" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zM13 9V3.5L18.5 9H13z"/></svg>',
                'user-folder': '<svg fill="#FFCA28" viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>',
                'preview-pane': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-1 16H6c-.55 0-1-.45-1-1V6c0-.55.45-1 1-1h12c.55 0 1 .45 1 1v12c0 .55-.45 1-1 1zm-4-4h-4v-2h4v2z"/></svg>',
                'large-icons': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M3 11h8V3H3v8zm0 10h8v-8H3v8zM13 3v8h8V3h-8zm0 10h8v-8h-8v8z"/></svg>',
                'details': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M3 15h18v-2H3v2zm0 4h18v-2H3v2zm0-8h18V9H3v2zm0-6v2h18V5H3z"/></svg>',
                'checkboxes': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>',
                'share': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg>',
                'share-manage': '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-4H6v-2h12v2zm0-4H6V8h12v2z"/></svg>'
            };
            Object.keys(ICONS).forEach(id => {
                const el = getEl(`icon-${id}`);
                if (el) el.innerHTML = ICONS[id];
            });
            const apiCall = async (action, formData) => {
                try {
                    formData.append('action', action);
                    const response = await fetch('', { method: 'POST', body: formData });
                    if (!response.ok) {
                        const err = await response.json().catch(() => ({}));
                        throw new Error(err.message || `サーバーエラー: ${response.status}`);
                    }
                    const text = await response.text();
                    if (!text) return null;
                    return JSON.parse(text);
                } catch (error) {
                    console.error(`API Call Error (${action}):`, error);
                    alert(`エラー: ${error.message}`);
                    return null;
                }
            };
            const updateAddressBar = (path) => {
                addressBar.innerHTML = '';
                const homeIconSvg = '<svg fill="currentColor" viewBox="0 0 16 16" style="width:16px; height:16px; margin-right:4px;"><path d="M8 1.75a.75.75 0 0 1 .53.22l5.25 5.25a.75.75 0 0 1-1.06 1.06L12 7.78V14a.75.75 0 0 1-1.5 0V9h-1v5a.75.75 0 0 1-1.5 0V7.78L4.28 8.53a.75.75 0 0 1-1.06-1.06l5.25-5.25A.75.75 0 0 1 8 1.75z"/></svg>';
                const homePart = document.createElement('span');
                homePart.className = 'address-bar-part';
                homePart.innerHTML = homeIconSvg;
                homePart.onclick = () => navigateTo('/');
                addressBar.appendChild(homePart);
                const parts = path.split('/').filter(p => p);
                let currentBuildPath = '';
                parts.forEach(part => {
                    addressBar.insertAdjacentHTML('beforeend', '<span class="address-bar-separator">&gt;</span>');
                    currentBuildPath += `/${part}`;
                    const partEl = document.createElement('span');
                    partEl.className = 'address-bar-part';
                    partEl.textContent = part;
                    partEl.dataset.path = currentBuildPath;
                    partEl.onclick = () => navigateTo(partEl.dataset.path);
                    addressBar.appendChild(partEl);
                });
            };
            const updateUsage = async () => {
                const data = await apiCall('get_usage', new FormData());
                if (data && data.success) {
                    const percentage = data.total > 0 ? (data.used / data.total) * 100 : 0;
                    usageFill.style.width = `${Math.min(percentage, 100)}%`;
                    usageText.textContent = `${data.used_formatted} / ${data.total_formatted}`;
                }
            };
            const updateSelection = () => {
                document.querySelectorAll('.file-item.selected, .grid-item.selected').forEach(el => el.classList.remove('selected'));
                document.querySelectorAll('.file-item.cut, .grid-item.cut').forEach(el => el.classList.remove('cut'));
                selectedItems.forEach((_, key) => {
                    document.querySelectorAll(`[data-path="${CSS.escape(key)}"]`).forEach(el => el.classList.add('selected'));
                });
                if (clipboard.type === 'cut') {
                    clipboard.items.forEach(item => {
                        document.querySelectorAll(`[data-path="${CSS.escape(item.path)}"]`).forEach(el => el.classList.add('cut'));
                    });
                }
                const hasSelection = selectedItems.size > 0;
                const hasSystemFile = Array.from(selectedItems.values()).some(file => file.is_system);
                getEl('delete-btn').disabled = !hasSelection || hasSystemFile;
                getEl('rename-btn').disabled = selectedItems.size !== 1 || hasSystemFile;
                getEl('copy-btn').disabled = !hasSelection;
                getEl('cut-btn').disabled = !hasSelection || hasSystemFile;
                getEl('paste-btn').disabled = clipboard.items.length === 0;
                getEl('share-btn').disabled = selectedItems.size !== 1;
                const totalItems = document.querySelectorAll('.file-item:not(.search-result), .grid-item:not(.search-result)').length;
                itemCountEl.textContent = selectedItems.size > 0 ? `${selectedItems.size} 個の項目を選択` : `${totalItems} 項目`;
            };
            const navigateTo = (path, fromHistory = false) => {
                if (currentPath === path && !fromHistory) return;
                if (!fromHistory) {
                    if (currentPath !== path) history.past.push(currentPath);
                    history.future = [];
                }
                currentPath = path;
                selectedItems.clear();
                searchBox.value = '';
                isSearchMode = false;
                loadDirectory(path);
                updateNavButtons();
            };
            const updateNavButtons = () => {
                navBackBtn.disabled = history.past.length === 0;
                navForwardBtn.disabled = history.future.length === 0;
                navUpBtn.disabled = currentPath === '/';
            };
            const renderItems = (files, isSearch = false) => {
                fileListBody.innerHTML = '';
                fileGridView.innerHTML = '';
                if (files.length === 0) {
                    if (!isSearch) {
                        fileListBody.innerHTML = '<tr class="empty-message"><td colspan="5">このフォルダーは空です。</td></tr>';
                    }
                } else {
                    files.forEach(file => {
                        const filePath = file.path || (currentPath === '/' ? `/${file.name}` : `${currentPath}/${file.name}`);
                        const row = document.createElement('tr');
                        row.className = `file-item ${isSearch ? 'search-result' : ''}`;
                        row.dataset.path = filePath;
                        row.dataset.name = file.name;
                        row.dataset.isDir = file.is_dir;
                        row.dataset.isSystem = file.is_system;
                        let nameCellHTML = `<div class="item-name-container"><span class="icon">${file.is_dir ? ICONS.folder : ICONS.file}</span><span class="item-name">${file.name}</span></div>`;
                        if (isSearch) {
                            nameCellHTML += `<div class="path">${filePath}</div>`;
                        }
                        const checkboxHTML = `<td class="col-check"><input type="checkbox" class="item-checkbox" data-path="${filePath}"></td>`;
                        if (file.is_system) {
                            row.innerHTML = `${checkboxHTML}<td class="col-name">${nameCellHTML}</td><td class="col-modified"></td><td class="col-type"><span style="color: var(--text-secondary-color);">SYSTEM FILE</span></td><td class="col-size"></td>`;
                        } else {
                            row.innerHTML = `${checkboxHTML}<td class="col-name">${nameCellHTML}</td><td class="col-modified">${file.modified}</td><td class="col-type">${file.type}</td><td class="col-size">${file.size}</td>`;
                        }
                        fileListBody.appendChild(row);
                        const gridItem = document.createElement('div');
                        gridItem.className = 'grid-item';
                        gridItem.dataset.path = filePath;
                        gridItem.dataset.name = file.name;
                        gridItem.dataset.isDir = file.is_dir;
                        gridItem.dataset.isSystem = file.is_system;
                        gridItem.innerHTML = `<span class="icon">${file.is_dir ? ICONS.folder : ICONS.file}</span><span class="name">${file.name}</span>`;
                        fileGridView.appendChild(gridItem);
                        [row, gridItem].forEach(el => {
                            el.addEventListener('click', e => handleItemClick(e, file, el));
                            el.addEventListener('dblclick', () => handleItemDblClick(file, filePath));
                            el.addEventListener('contextmenu', e => handleItemContextMenu(e, file, el));
                        });
                    });
                }
                updateSelection();
            };
            const loadDirectory = async (path) => {
                const formData = new FormData();
                formData.append('path', path);
                const data = await apiCall('list', formData);
                if (!data || !data.success) {
                    if (path !== '/') navigateTo('/');
                    return;
                }
                isSearchMode = false;
                addressBar.style.display = 'flex';
                addressInput.style.display = 'none';
                searchBox.style.display = 'block';
                updateAddressBar(data.path);
                renderItems(data.files, false);
                updateUsage();
                navPane.querySelectorAll('.nav-item').forEach(item => {
                    if (item.dataset.path === path) item.classList.add('active');
                    else item.classList.remove('active');
                });
            };
            const handleItemClick = (e, file, element) => {
                e.stopPropagation();
                hideContextMenu();
                const itemPath = element.dataset.path;
                const fileData = { path: itemPath, name: file.name, is_dir: file.is_dir, is_system: file.is_system };
                if (e.target.type === 'checkbox') {
                    e.target.checked ? selectedItems.set(itemPath, fileData) : selectedItems.delete(itemPath);
                } else if (e.ctrlKey) {
                    selectedItems.has(itemPath) ? selectedItems.delete(itemPath) : selectedItems.set(itemPath, fileData);
                } else if (e.shiftKey && selectedItems.size > 0) {
                    selectedItems.clear();
                    selectedItems.set(itemPath, fileData);
                } else {
                    selectedItems.clear();
                    selectedItems.set(itemPath, fileData);
                }
                updateSelection();
                showPreview();
            };
            const handleItemDblClick = (file, path) => {
                file.is_dir ? navigateTo(path) : downloadFile(path);
            };
            const handleItemContextMenu = (e, file, row) => {
                e.preventDefault();
                e.stopPropagation();
                const itemPath = row.dataset.path;
                const fileData = { path: itemPath, name: file.name, is_dir: file.is_dir, is_system: file.is_system };
                if (!selectedItems.has(itemPath)) {
                    selectedItems.clear();
                    selectedItems.set(itemPath, fileData);
                    updateSelection();
                }
                showContextMenu(e, fileData, row);
            };
            const showContextMenu = (e, fileInfo, element) => {
                hideContextMenu();
                contextMenu = document.createElement('div');
                contextMenu.className = 'context-menu';
                document.body.appendChild(contextMenu);
                const canPaste = clipboard.items.length > 0;
                let menuItemsHTML = '';
                if (fileInfo) {
                    if (fileInfo.name === '.logs') {
                        menuItemsHTML += `<div class="context-menu-item" data-action="open"><span class="label">開く</span></div>`;
                    } else {
                        const isSystem = fileInfo.is_system;
                        const openActionText = fileInfo.is_dir ? '開く' : 'ダウンロード';
                        menuItemsHTML += `<div class="context-menu-item" data-action="open"><span class="label">${openActionText}</span></div>`;

                        const itemPath = element.dataset.path;
                        const isInsideLogs = itemPath.includes('/.logs/');
                        if (!fileInfo.is_dir && !isInsideLogs) {
                            menuItemsHTML += `<div class="context-menu-item has-submenu"><span class="label">アプリで開く</span><div class="submenu"><div class="context-menu-item" data-action="open-with-notepad"><span class="label">Notepad</span></div></div></div>`;
                        }

                        if (!isSystem) {
                            if (fileInfo.is_dir) {
                                const isFavorite = favorites.some(fav => fav.path === element.dataset.path);
                                menuItemsHTML += `<div class="context-menu-item" data-action="${isFavorite ? 'remove_favorite' : 'add_favorite'}"><span class="label">${isFavorite ? 'お気に入りから削除' : 'お気に入りに追加'}</span></div>`;
                            }
                            menuItemsHTML += `<div class="context-menu-separator"></div>`;
                            menuItemsHTML += `<div class="context-menu-item" data-action="cut"><span class="label">切り取り</span></div>`;
                            menuItemsHTML += `<div class="context-menu-separator"></div>`;
                            menuItemsHTML += `<div class="context-menu-item" data-action="delete"><span class="label">削除</span></div>`;
                            menuItemsHTML += `<div class="context-menu-item" data-action="rename"><span class="label">名前の変更</span></div>`;
                        }
                    }
                } else {
                    menuItemsHTML += `<div class="context-menu-item ${canPaste ? '' : 'disabled'}" data-action="paste"><span class="label">貼り付け</span></div>`;
                    menuItemsHTML += `<div class="context-menu-separator"></div>`;
                    menuItemsHTML += `<div class="context-menu-item has-submenu"><span class="label">新規作成</span><div class="submenu"><div class="context-menu-item" data-action="create_folder"><span class="label">フォルダー</span></div><div class="context-menu-item" data-action="create_file"><span class="label">テキスト ドキュメント</span></div></div></div>`;
                    menuItemsHTML += `<div class="context-menu-separator"></div>`;
                    menuItemsHTML += `<div class="context-menu-item" data-action="copy_path"><span class="label">パスのコピー</span></div>`;
                }
                contextMenu.innerHTML = menuItemsHTML;
                const menuRect = contextMenu.getBoundingClientRect();
                let x = e.clientX;
                let y = e.clientY;
                if (x + menuRect.width > window.innerWidth) x = window.innerWidth - menuRect.width - 5;
                if (y + menuRect.height > window.innerHeight) y = window.innerHeight - menuRect.height - 5;
                if (x < 0) x = 5;
                if (y < 0) y = 5;
                contextMenu.style.left = `${x}px`;
                contextMenu.style.top = `${y}px`;
                contextMenu.querySelectorAll('.has-submenu').forEach(item => {
                    const submenu = item.querySelector('.submenu');
                    if (!submenu) return;
                    item.addEventListener('mouseenter', () => {
                        contextMenu.querySelectorAll('.submenu').forEach(sm => sm.style.display = 'none');
                        submenu.style.display = 'block';
                        submenu.style.left = '100%';
                        submenu.style.right = 'auto';
                        submenu.style.top = '-5px';
                        submenu.style.bottom = 'auto';
                        const subRect = submenu.getBoundingClientRect();
                        if (subRect.right > window.innerWidth) { submenu.style.left = 'auto'; submenu.style.right = '100%'; }
                        if (subRect.bottom > window.innerHeight) { submenu.style.top = 'auto'; submenu.style.bottom = '0px'; }
                    });
                });
                contextMenu.addEventListener('mouseleave', () => {
                    contextMenu.querySelectorAll('.submenu').forEach(sm => sm.style.display = 'none');
                });
            };
            const hideContextMenu = () => { if (contextMenu) contextMenu.remove(); contextMenu = null; };
            const handleContextMenuAction = (action, fileInfo, element) => {
                hideContextMenu();
                const itemPath = element ? element.dataset.path : null;
                switch (action) {
                    case 'open': fileInfo.is_dir ? navigateTo(itemPath) : downloadFile(itemPath); break;
                    case 'open-with-notepad': window.parent.postMessage({ type: 'openWithApp', app: 'notepad', filePath: itemPath }, '*'); break;
                    case 'rename': initiateRename(element); break;
                    case 'delete': deleteItems(); break;
                    case 'cut': cutItems(); break;
                    case 'paste': pasteItems(); break;
                    case 'add_favorite': addFavorite(itemPath, fileInfo.name); break;
                    case 'remove_favorite': removeFavorite(itemPath); break;
                    case 'copy_path': copyToClipboard(currentPath); break;
                    case 'create_file': createNewFile(); break;
                    case 'create_folder': createFolder(); break;
                }
            };
            const initiateRename = (rowElement) => {
                const nameContainer = rowElement.querySelector('.item-name-container');
                const nameSpan = nameContainer.querySelector('.item-name');
                if (!nameSpan || nameContainer.querySelector('input')) return;
                const oldName = nameSpan.textContent;
                nameSpan.style.display = 'none';
                const input = document.createElement('input');
                input.type = 'text';
                input.value = oldName;
                const finishRename = async () => {
                    input.removeEventListener('blur', finishRename);
                    input.removeEventListener('keydown', keydownHandler);
                    const newName = input.value.trim();
                    nameSpan.style.display = '';
                    input.remove();
                    if (newName && newName !== oldName) {
                        const formData = new FormData();
                        formData.append('item_path', rowElement.dataset.path);
                        formData.append('new_name', newName);
                        const result = await apiCall('rename', formData);
                        if (result && result.success) isSearchMode ? searchFiles(searchBox.value) : loadDirectory(currentPath);
                    }
                };
                const keydownHandler = e => { if (e.key === 'Enter') finishRename(); else if (e.key === 'Escape') { input.removeEventListener('blur', finishRename); nameSpan.style.display = ''; input.remove(); } };
                input.addEventListener('blur', finishRename);
                input.addEventListener('keydown', keydownHandler);
                nameContainer.appendChild(input);
                input.focus();
                input.select();
            };
            const uploadFiles = async (files, isFolder = false) => {
                const formData = new FormData();
                formData.append('path', currentPath);
                const relativePaths = [];
                for (const file of files) {
                    formData.append('files[]', file, file.name);
                    if (isFolder && file.webkitRelativePath) relativePaths.push(file.webkitRelativePath);
                }
                if (isFolder) formData.append('relative_paths', JSON.stringify(relativePaths));
                const result = await apiCall('upload', formData);
                if (result && result.success) loadDirectory(currentPath);
            };
            const createFolder = async () => {
                const name = prompt('新しいフォルダ名:', '新しいフォルダー');
                if (!name) return;
                const formData = new FormData();
                formData.append('path', currentPath);
                formData.append('name', name);
                const result = await apiCall('create_folder', formData);
                if (result && result.success) loadDirectory(currentPath);
            };
            const createNewFile = async () => {
                const name = prompt('新しいファイル名:', '新規テキストドキュメント.txt');
                if (!name) return;
                const formData = new FormData();
                formData.append('path', currentPath);
                formData.append('name', name);
                const result = await apiCall('create_file', formData);
                if (result && result.success) loadDirectory(currentPath);
            };
            const deleteItems = async () => {
                if (selectedItems.size === 0 || !confirm(`${selectedItems.size}個の項目を完全に削除しますか？`)) return;
                const itemsToDelete = Array.from(selectedItems.values());
                const formData = new FormData();
                formData.append('items', JSON.stringify(itemsToDelete.map(item => ({ path: item.path, name: item.name }))));
                const result = await apiCall('delete', formData);
                if (result && result.success) { selectedItems.clear(); isSearchMode ? searchFiles(searchBox.value) : loadDirectory(currentPath); }
            };
            const cutItems = () => { if (selectedItems.size > 0) { clipboard.type = 'cut'; clipboard.items = Array.from(selectedItems.values()); updateSelection(); } };
            const pasteItems = async () => {
                if (clipboard.items.length === 0) return;
                const formData = new FormData();
                formData.append('items', JSON.stringify(clipboard.items));
                formData.append('destination', currentPath);
                let result;
                if (clipboard.type === 'cut') {
                    result = await apiCall('move', formData);
                }
                if (result && result.success) { clipboard = { type: null, items: [] }; loadDirectory(currentPath); } else { updateSelection(); }
            };
            const downloadFile = (filePath) => { window.location.href = `?action=download&file=${encodeURIComponent(filePath)}`; };
            const searchFiles = async (term) => {
                const formData = new FormData();
                formData.append('query', term);
                const data = await apiCall('search', formData);
                if (data && data.success) { isSearchMode = true; renderItems(data.files, true); }
            };
            const loadFavorites = async () => {
                const data = await apiCall('get_favorites', new FormData());
                if (data && data.success) { favorites = data.favorites || []; renderFavorites(); }
            };
            const saveFavorites = async () => {
                const formData = new FormData();
                formData.append('favorites', JSON.stringify(favorites));
                await apiCall('save_favorites', formData);
            };
            const renderFavorites = () => {
                favoritesListEl.innerHTML = '';
                getEl('favorites-section').style.display = favorites.length === 0 ? 'none' : 'block';
                if (favorites.length > 0) {
                    favorites.forEach(fav => {
                        const favEl = document.createElement('div');
                        favEl.className = 'nav-item nested favorite';
                        favEl.dataset.path = fav.path;
                        favEl.innerHTML = `<span class="icon">${ICONS.folder}</span> <span>${fav.name}</span>`;
                        favEl.addEventListener('click', () => navigateTo(fav.path));
                        favEl.addEventListener('contextmenu', (e) => { e.preventDefault(); e.stopPropagation(); showFavoriteContextMenu(e, fav.path); });
                        favoritesListEl.appendChild(favEl);
                    });
                }
            };
            const showFavoriteContextMenu = (e, path) => {
                hideContextMenu();
                contextMenu = document.createElement('div');
                contextMenu.className = 'context-menu';
                document.body.appendChild(contextMenu);
                contextMenu.innerHTML = `<div class="context-menu-item" data-action="remove_favorite"><span class="label">お気に入りから削除</span></div>`;
                const menuRect = contextMenu.getBoundingClientRect();
                let x = e.clientX; let y = e.clientY;
                if (x + menuRect.width > window.innerWidth) x = window.innerWidth - menuRect.width - 5;
                if (y + menuRect.height > window.innerHeight) y = window.innerHeight - menuRect.height - 5;
                if (x < 0) x = 5; if (y < 0) y = 5;
                contextMenu.style.left = `${x}px`; contextMenu.style.top = `${y}px`;
            };
            const addFavorite = (path, name) => { if (!favorites.some(fav => fav.path === path)) { favorites.push({ path, name }); saveFavorites(); renderFavorites(); } };
            const removeFavorite = (path) => { favorites = favorites.filter(fav => fav.path !== path); saveFavorites(); renderFavorites(); };
            const showViewContextMenu = (e) => { hideContextMenu(); showContextMenu(e, null, null); };
            const copyToClipboard = (text) => { navigator.clipboard.writeText(text).catch(err => console.error('クリップボードへのコピーに失敗:', err)); };
            const showPreview = () => {
                if (!previewPane.classList.contains('active') || selectedItems.size !== 1) {
                    previewContent.innerHTML = '';
                    previewPlaceholder.textContent = 'プレビューするファイルを選択してください';
                    previewPlaceholder.style.display = 'block';
                    return;
                }
                const [filePath] = selectedItems.keys();
                const ext = filePath.split('.').pop().toLowerCase();
                const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp'];
                const videoExts = ['mp4', 'webm', 'ogg'];
                previewPlaceholder.style.display = 'none';
                previewContent.innerHTML = '';
                if (imageExts.includes(ext)) {
                    const img = document.createElement('img');
                    img.src = `?action=download&file=${encodeURIComponent(filePath)}`;
                    previewContent.appendChild(img);
                } else if (videoExts.includes(ext)) {
                    const video = document.createElement('video');
                    video.src = `?action=download&file=${encodeURIComponent(filePath)}`;
                    video.controls = true;
                    previewContent.appendChild(video);
                } else {
                    previewPlaceholder.textContent = 'このファイル形式のプレビューはありません。';
                    previewPlaceholder.style.display = 'block';
                }
            };
            const startSelection = (e) => {
                if (e.target.closest('.file-item, .grid-item, .modal')) return;
                isSelecting = true;
                contentAreaRect = contentArea.getBoundingClientRect();
                startX = e.clientX - contentAreaRect.left;
                startY = e.clientY - contentAreaRect.top;
                selectionRectangle.style.left = `${startX}px`;
                selectionRectangle.style.top = `${startY}px`;
                selectionRectangle.style.width = '0px';
                selectionRectangle.style.height = '0px';
                selectionRectangle.style.display = 'block';
                document.addEventListener('mousemove', doSelection);
                document.addEventListener('mouseup', endSelection);
            };
            const doSelection = (e) => {
                if (!isSelecting) return;
                e.preventDefault();
                const currentX = e.clientX - contentAreaRect.left;
                const currentY = e.clientY - contentAreaRect.top;
                const left = Math.min(startX, currentX);
                const top = Math.min(startY, currentY);
                const width = Math.abs(startX - currentX);
                const height = Math.abs(startY - currentY);
                selectionRectangle.style.left = `${left}px`;
                selectionRectangle.style.top = `${top}px`;
                selectionRectangle.style.width = `${width}px`;
                selectionRectangle.style.height = `${height}px`;
                const rect = selectionRectangle.getBoundingClientRect();
                const items = document.querySelectorAll('.file-item, .grid-item');
                if (!e.ctrlKey) selectedItems.clear();
                items.forEach(itemEl => {
                    const itemRect = itemEl.getBoundingClientRect();
                    const path = itemEl.dataset.path;
                    const fileData = { path, name: itemEl.dataset.name, is_dir: itemEl.dataset.isDir === 'true', is_system: itemEl.dataset.isSystem === 'true' };
                    if (rect.left < itemRect.right && rect.right > itemRect.left && rect.top < itemRect.bottom && rect.bottom > itemRect.top) {
                        if (!selectedItems.has(path)) selectedItems.set(path, fileData);
                    }
                });
                updateSelection();
            };
            const endSelection = () => {
                isSelecting = false;
                selectionRectangle.style.display = 'none';
                document.removeEventListener('mousemove', doSelection);
                document.removeEventListener('mouseup', endSelection);
            };
            addEventListenerIfPresent('share-btn', 'click', () => {
                if (selectedItems.size !== 1) return;
                const itemPath = selectedItems.keys().next().value;
                window.parent.postMessage({ type: 'openShareWindow', itemPath: itemPath }, '*');
            });
            addEventListenerIfPresent('manage-shares-btn', 'click', () => { window.parent.postMessage({ type: 'openShareManager' }, '*'); });
            document.querySelectorAll('.header-tabs .tab-item').forEach(tab => tab.addEventListener('click', (e) => {
                document.querySelector('.header-tabs .tab-item.active')?.classList.remove('active');
                e.currentTarget.classList.add('active');
                const targetToolbarId = e.currentTarget.dataset.toolbar;
                document.querySelectorAll('.toolbar').forEach(toolbar => { toolbar.classList.toggle('active', toolbar.id === targetToolbarId); });
            }));
            document.addEventListener('click', (e) => {
                if (e.target.closest('.context-menu-item')) {
                    const item = e.target.closest('.context-menu-item');
                    if (item && !item.classList.contains('has-submenu') && !item.classList.contains('disabled')) {
                        const selectedRow = fileListBody.querySelector('tr.selected') || fileGridView.querySelector('.grid-item.selected');
                        const fileInfo = selectedRow ? { name: selectedRow.dataset.name, is_dir: selectedRow.dataset.isDir === 'true', is_system: selectedRow.dataset.isSystem === 'true' } : null;
                        handleContextMenuAction(item.dataset.action, fileInfo, selectedRow);
                    }
                } else if (contextMenu && !contextMenu.contains(e.target)) {
                    hideContextMenu();
                }
                const clickedInside = e.target.closest('.content-area, .toolbar, .nav-pane, .address-bar-container, .modal-content, .context-menu');
                if (!clickedInside) { selectedItems.clear(); updateSelection(); }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') { hideContextMenu(); }
                if (document.activeElement.tagName === 'INPUT') return;
                if (e.key === 'Delete' && selectedItems.size > 0) deleteItems();
                if (e.key === 'F2' && selectedItems.size === 1) { const path = selectedItems.keys().next().value; const el = document.querySelector(`[data-path="${CSS.escape(path)}"]`); if (el) initiateRename(el); }
                if (e.ctrlKey && e.key.toLowerCase() === 'x') { e.preventDefault(); cutItems(); }
                if (e.ctrlKey && e.key.toLowerCase() === 'v') { e.preventDefault(); pasteItems(); }
            });
            addEventListenerIfPresent('upload-file-btn', 'click', () => fileInput.click());
            addEventListenerIfPresent('upload-folder-btn', 'click', () => folderInput.click());
            addEventListenerIfPresent('preview-pane-btn', 'click', (e) => { e.currentTarget.classList.toggle('active'); previewPane.classList.toggle('active'); showPreview(); });
            addEventListenerIfPresent('item-checkboxes-btn', 'click', (e) => { e.currentTarget.classList.toggle('active'); explorerContainer.classList.toggle('show-checkboxes'); });
            addEventListenerIfPresent('new-folder-btn', 'click', createFolder);
            addEventListenerIfPresent('delete-btn', 'click', deleteItems);
            addEventListenerIfPresent('rename-btn', 'click', () => { if (selectedItems.size !== 1) return; const path = selectedItems.keys().next().value; const el = document.querySelector(`[data-path="${CSS.escape(path)}"]`); if (el) initiateRename(el); });
            addEventListenerIfPresent('cut-btn', 'click', cutItems);
            addEventListenerIfPresent('paste-btn', 'click', pasteItems);
            document.querySelectorAll('.layout-btn').forEach(btn => btn.addEventListener('click', (e) => { document.querySelector('.layout-btn.active')?.classList.remove('active'); const target = e.currentTarget; target.classList.add('active'); currentLayout = target.id === 'large-icons-btn' ? 'grid' : 'details'; fileTableView.style.display = currentLayout === 'details' ? 'block' : 'none'; fileGridView.style.display = currentLayout === 'grid' ? 'flex' : 'none'; }));
            navBackBtn.addEventListener('click', () => { if (history.past.length > 0) { history.future.unshift(currentPath); navigateTo(history.past.pop(), true); } });
            navForwardBtn.addEventListener('click', () => { if (history.future.length > 0) { history.past.push(currentPath); navigateTo(history.future.shift(), true); } });
            navUpBtn.addEventListener('click', () => { if (currentPath !== '/') navigateTo(currentPath.substring(0, currentPath.lastIndexOf('/')) || '/'); });
            fileInput.addEventListener('change', (e) => uploadFiles(e.target.files, false));
            folderInput.addEventListener('change', (e) => uploadFiles(e.target.files, true));
            searchBox.addEventListener('input', () => { const term = searchBox.value.trim(); if (term === '') { if (isSearchMode) navigateTo(currentPath); } else { searchFiles(term); } });
            getEl('nav-home').addEventListener('click', () => navigateTo('/'));
            const body = document.body;
            body.addEventListener('dragenter', (e) => { e.preventDefault(); dragDropOverlay.classList.add('visible'); });
            body.addEventListener('dragover', (e) => { e.preventDefault(); });
            body.addEventListener('dragleave', (e) => { if (e.clientX <= 0 || e.clientY <= 0 || e.clientX >= window.innerWidth || e.clientY >= window.innerHeight) dragDropOverlay.classList.remove('visible'); });
            body.addEventListener('drop', (e) => { e.preventDefault(); dragDropOverlay.classList.remove('visible'); uploadFiles(e.dataTransfer.files, false); });
            contentArea.addEventListener('mousedown', startSelection);
            contentArea.addEventListener('contextmenu', (e) => { if (e.target.closest('.file-item') || e.target.closest('.grid-item')) return; e.preventDefault(); showViewContextMenu(e); });
            loadFavorites();
            loadDirectory(currentPath);
            updateNavButtons();
            const myWindowIdForMessaging = window.name;
            document.addEventListener('mousedown', () => { window.parent.postMessage({ type: 'iframeClick', windowId: myWindowIdForMessaging }, '*'); }, true);
            document.addEventListener('keydown', (e) => { if ((e.altKey && e.key.toLowerCase() === 'w') || (e.altKey && ['ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key))) { e.preventDefault(); window.parent.postMessage({ type: 'forwardedKeydown', key: e.key, altKey: e.altKey, ctrlKey: e.ctrlKey, shiftKey: e.shiftKey, metaKey: e.metaKey, windowId: myWindowIdForMessaging }, '*'); } });
            document.addEventListener('keyup', (e) => { if (e.key === 'Alt') { e.preventDefault(); window.parent.postMessage({ type: 'forwardedKeyup', key: e.key }, '*'); } });
        });
    </script>
</body>
</html>