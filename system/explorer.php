<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('USER_BASE_DIR', __DIR__ . '/../user');
define('MAX_STORAGE_MB', 100);
define('MAX_STORAGE_BYTES', MAX_STORAGE_MB * 1024 * 1024);

if (!isset($_SESSION['username'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'アクセス権がありません。ログインしてください。']);
    } else {
        die('アクセス権がありません。ログインしてください。');
    }
    exit;
}

$username = $_SESSION['username'];
$user_dir = USER_BASE_DIR . '/' . $username;

if (!is_dir($user_dir)) {
    mkdir($user_dir, 0777, true);
}

function getDirectorySize($dir) {
    if (!is_dir($dir)) return 0;
    $size = 0;
    clearstatcache();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO));
    foreach ($iterator as $file) {
        if ($file->isReadable()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function formatBytes($bytes) {
    if ($bytes <= 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

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
    $finalPath = $baseDir . '/' . implode('/', $safeParts);
    $realBaseDir = realpath($baseDir);
    $realFinalPath = realpath($finalPath);

    if ($realFinalPath !== false) {
        if (strpos($realFinalPath, $realBaseDir) !== 0) return false;
    } else {
        $realParentPath = realpath(dirname($finalPath));
         if ($realParentPath === false || strpos($realParentPath, $realBaseDir) !== 0) return false;
    }
    return rtrim($finalPath, '/');
}

function getFileType($filePath) {
    if (is_dir($filePath)) return 'ファイル フォルダー';
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $types = [ 'txt' => 'テキスト ドキュメント', 'pdf' => 'PDF ドキュメント', 'jpg' => 'JPEG 画像', 'jpeg' => 'JPEG 画像', 'png' => 'PNG 画像', 'gif' => 'GIF 画像', 'svg' => 'SVG 画像', 'zip' => '圧縮 (zip) フォルダー', 'rar' => 'WinRAR 書庫', 'doc' => 'Microsoft Word 文書', 'docx' => 'Microsoft Word 文書', 'xls' => 'Microsoft Excel ワークシート', 'xlsx' => 'Microsoft Excel ワークシート', 'ppt' => 'Microsoft PowerPoint プレゼンテーション', 'pptx' => 'Microsoft PowerPoint プレゼンテーション', 'php' => 'PHP ファイル', 'html' => 'HTML ドキュメント', 'css' => 'CSS スタイルシート', 'js' => 'JavaScript ファイル', ];
    return $types[$ext] ?? strtoupper($ext) . ' ファイル';
}

function deleteDirectoryRecursively($dir) {
    if (!is_dir($dir)) return false;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $fileinfo) {
        $path = $fileinfo->getRealPath();
        if ($fileinfo->isDir()) { @chmod($path, 0777); @rmdir($path); }
        else { @chmod($path, 0777); @unlink($path); }
    }
    return @rmdir($dir);
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $path = $_REQUEST['path'] ?? '/';

    try {
        if ($action !== 'get_usage' && $action !== 'search') {
            $safe_path = getSafePath($user_dir, $path);
            if ($safe_path === false) throw new Exception('無効なパスです。');
        }

        switch ($action) {
            case 'download':
                $file_to_download = getSafePath($user_dir, $_GET['file']);
                if ($file_to_download === false || !is_file($file_to_download)) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'ファイルが見つかりません。']); exit; }
                header('Content-Description: File Transfer'); header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($file_to_download) . '"');
                header('Expires: 0'); header('Cache-Control: must-revalidate'); header('Pragma: public');
                header('Content-Length: ' . filesize($file_to_download));
                readfile($file_to_download);
                exit;

            case 'list':
                $files = []; if (!is_dir($safe_path)) throw new Exception('ディレクトリが見つかりません。');
                $items = scandir($safe_path);
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $item_path = $safe_path . '/' . $item; clearstatcache(true, $item_path);
                    $is_dir = is_dir($item_path);
                    $files[] = [ 'name' => $item, 'is_dir' => $is_dir, 'type' => getFileType($item_path), 'size' => $is_dir ? '' : formatBytes(filesize($item_path)), 'modified' => date('Y/m/d H:i', filemtime($item_path)) ];
                }
                usort($files, function($a, $b) {
                    if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
                    return strcasecmp($a['name'], $b['name']);
                });
                echo json_encode(['success' => true, 'path' => $path, 'files' => $files]);
                break;

            case 'search':
                $query = $_POST['query'] ?? ''; if (empty($query)) throw new Exception('検索クエリがありません。');
                $results = [];
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($user_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iterator as $file) {
                    if (stripos($file->getFilename(), $query) !== false) {
                        $item_path = $file->getRealPath();
                        $relative_path = str_replace(realpath($user_dir), '', $item_path);
                        $is_dir = $file->isDir();
                        $results[] = [ 'name' => $file->getFilename(), 'path' => str_replace('\\', '/', $relative_path), 'is_dir' => $is_dir, 'type' => getFileType($item_path), 'size' => $is_dir ? '' : formatBytes($file->getSize()), 'modified' => date('Y/m/d H:i', $file->getMTime()) ];
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
                $total_size = array_sum($_FILES['files']['size']); if (getDirectorySize($user_dir) + $total_size > MAX_STORAGE_BYTES) throw new Exception('ストレージ容量不足です。');
                $relative_paths = isset($_POST['relative_paths']) ? json_decode($_POST['relative_paths'], true) : [];
                foreach ($_FILES['files']['tmp_name'] as $index => $tmp_name) {
                    $file_name = count($relative_paths) > 0 ? $relative_paths[$index] : $_FILES['files']['name'][$index];
                    $target_path = $safe_path . '/' . $file_name; $dir_path = dirname($target_path);
                    if (!is_dir($dir_path)) mkdir($dir_path, 0777, true);
                    if (!move_uploaded_file($tmp_name, $target_path)) throw new Exception("{$file_name} のアップロードに失敗しました。");
                }
                echo json_encode(['success' => true, 'message' => 'アップロードが完了しました。']);
                break;

            case 'create_folder':
                $folder_name = $_POST['name'] ?? ''; if (empty($folder_name) || preg_match('/[\\\\\/:\*\?"<>|]/', $folder_name)) throw new Exception('無効なフォルダ名です。');
                $new_folder_path = $safe_path . '/' . $folder_name; if (file_exists($new_folder_path)) throw new Exception('同じ名前のアイテムが存在します。');
                if (!mkdir($new_folder_path, 0777, true)) throw new Exception('フォルダの作成に失敗しました。');
                echo json_encode(['success' => true, 'message' => 'フォルダを作成しました。']);
                break;

            case 'rename':
                $old_name_path = $_POST['item_path'] ?? ''; $new_name = $_POST['new_name'] ?? '';
                if (empty($old_name_path) || empty($new_name) || preg_match('/[\\\\\/:\*\?"<>|]/', $new_name)) throw new Exception('無効なファイル名です。');
                $old_path = getSafePath($user_dir, $old_name_path);
                $new_path = dirname($old_path) . '/' . $new_name;
                if ($old_path === false) throw new Exception('無効なパスです。');
                if (!file_exists($old_path)) throw new Exception('元のファイルが見つかりません。');
                if (file_exists($new_path)) throw new Exception('同じ名前のファイルが既に存在します。');
                if (!rename($old_path, $new_path)) throw new Exception('名前の変更に失敗しました。');
                echo json_encode(['success' => true, 'message' => '名前を変更しました。']);
                break;

            case 'delete':
                $items = json_decode($_POST['items'] ?? '[]', true); if (empty($items)) throw new Exception('削除するアイテムが指定されていません。');
                foreach ($items as $item) {
                    $item_path_full = getSafePath($user_dir, $item['path']);
                    if (!$item_path_full || !file_exists($item_path_full)) continue;
                    if (is_dir($item_path_full)) {
                        if (!deleteDirectoryRecursively($item_path_full)) throw new Exception("ディレクトリ '{$item['name']}' の削除に失敗しました。");
                    } else {
                        if (!unlink($item_path_full)) throw new Exception("ファイル '{$item['name']}' の削除に失敗しました。");
                    }
                }
                echo json_encode(['success' => true, 'message' => 'アイテムを削除しました。']);
                break;
        }
    } catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#202020">
    <title>Explorer - <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root {
            --bg-color: #202020; --bg-secondary-color: #2b2b2b; --bg-tertiary-color: #333333;
            --text-color: #f0f0f0; --text-secondary-color: #cccccc; --border-color: #404040;
            --accent-color: #0d63be; --hover-bg: #3a3a3a; --selection-bg: #00457e;
        }
        html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; font-family: 'Yu Gothic UI', 'Segoe UI', Meiryo, system-ui, sans-serif; font-size: 14px; background: var(--bg-color); color: var(--text-color); }
        .explorer-container { display: flex; flex-direction: column; height: 100%; }
        .header { background: var(--bg-secondary-color); border-bottom: 1px solid var(--border-color); }
        .toolbar { display: flex; gap: 24px; padding: 8px 12px; }
        .ribbon-group { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .ribbon-buttons { display: flex; gap: 4px; }
        .ribbon-group .group-label { font-size: 12px; color: var(--text-secondary-color); }
        .toolbar button { background: none; border: 1px solid transparent; color: var(--text-color); padding: 4px; border-radius: 4px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 4px; width: 70px; }
        .toolbar button:hover { background: var(--hover-bg); border-color: var(--border-color); }
        .toolbar button:disabled { cursor: not-allowed; opacity: 0.4; }
        .toolbar .icon { width: 24px; height: 24px; } .toolbar .label { font-size: 12px; white-space: nowrap; }
        .address-bar-container { display: flex; padding: 4px 12px; align-items: center; gap: 4px; }
        .address-bar-nav button { background: none; border: 1px solid transparent; color: var(--text-color); padding: 4px; border-radius: 4px; cursor: pointer;}
        .address-bar-nav button:not(:disabled):hover { background: var(--hover-bg); }
        .address-bar-nav button:disabled { opacity: 0.4; cursor: not-allowed; }
        .address-bar { flex-grow: 1; display: flex; align-items: center; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 4px; padding: 2px 4px; }
        .address-bar-part { padding: 4px 8px; cursor: pointer; border-radius: 4px; white-space: nowrap; color: var(--text-secondary-color); }
        .address-bar-part:hover { background: var(--hover-bg); }
        .address-bar-separator { color: var(--text-secondary-color); padding: 0 4px; }
        .address-input { flex-grow: 1; background: #3c3c3c; border: 1px solid var(--accent-color); color: var(--text-color); padding: 6px 10px; outline: none; border-radius: 4px; }
        .search-box { background: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-color); border-radius: 4px; padding: 6px 10px; width: 200px; }
        .main-content { flex: 1; display: flex; overflow: hidden; }
        .nav-pane { width: 240px; background: var(--bg-secondary-color); border-right: 1px solid var(--border-color); padding: 8px; overflow-y: auto; }
        .nav-item { padding: 6px 10px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .nav-item:hover, .nav-item.selected { background: var(--hover-bg); }
        .nav-item .icon { width: 20px; height: 20px; }
        .file-view { flex: 1; overflow: auto; }
        .file-table { width: 100%; border-collapse: collapse; }
        .file-table th { background: var(--bg-secondary-color); padding: 8px 16px; text-align: left; font-weight: normal; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; color: var(--text-secondary-color); }
        .file-table td { padding: 8px 16px; border-bottom: 1px solid var(--border-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: default; }
        .file-table tr.search-result .path { font-size: 12px; color: var(--text-secondary-color); }
        .file-table tr:hover { background: var(--hover-bg); }
        .file-table tr.selected { background: var(--selection-bg) !important; color: white; }
        .file-table tr.selected .path { color: #ccc; }
        .item-name-container { display: flex; align-items: center; gap: 8px; }
        .item-name-container input { background: var(--bg-tertiary-color); color: var(--text-color); border: 1px solid var(--accent-color); padding: 4px; border-radius: 4px; outline: none; flex-grow: 1; }
        .file-table .icon { width: 20px; height: 20px; flex-shrink: 0; }
        .status-bar { padding: 4px 12px; background: var(--bg-secondary-color); border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; font-size: 12px; }
        .usage-display { display: flex; align-items: center; gap: 8px; }
        .usage-bar { width: 150px; height: 14px; background: var(--bg-tertiary-color); border-radius: 4px; overflow: hidden; border: 1px solid var(--border-color); }
        .usage-fill { height: 100%; background: var(--accent-color); width: 0%; transition: width 0.5s ease; }
        #drag-drop-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); border: 2px dashed var(--accent-color); display: none; justify-content: center; align-items: center; font-size: 24px; color: var(--text-color); z-index: 9999; pointer-events: none; }
        #drag-drop-overlay.visible { display: flex; }
        .context-menu { position: absolute; z-index: 1000; background: #2c2c2c; border: 1px solid #555; min-width: 240px; padding: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); border-radius: 0; }
        .context-menu-item { padding: 8px 12px; cursor: pointer; white-space: nowrap; display: flex; align-items: center; gap: 12px; position: relative; }
        .context-menu-item:hover { background: var(--hover-bg); }
        .context-menu-item .icon { width: 16px; height: 16px; fill: var(--text-color); }
        .context-menu-separator { height: 1px; background: var(--border-color); margin: 4px 0; }
        .context-menu-item.has-submenu::after { content: '▶'; position: absolute; right: 8px; color: var(--text-secondary-color); }
        .submenu { display: none; position: absolute; left: 100%; top: -5px; background: #2c2c2c; border: 1px solid #555; min-width: 150px; padding: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); border-radius: 0; z-index: 1001; }
        .context-menu-item:hover > .submenu { display: block; }
    </style>
</head>
<body>
    <div class="explorer-container">
        <div class="header">
            <div class="toolbar">
                 <div class="ribbon-group">
                    <div class="ribbon-buttons">
                        <button id="upload-file-btn"><span class="icon" id="icon-upload-file"></span><span class="label">ファイル</span></button>
                        <button id="upload-folder-btn"><span class="icon" id="icon-upload-folder"></span><span class="label">フォルダー</span></button>
                    </div><span class="group-label">アップロード</span>
                </div>
                <div class="ribbon-group">
                    <div class="ribbon-buttons"><button id="new-folder-btn"><span class="icon" id="icon-new-folder"></span><span class="label">新しいフォルダー</span></button></div>
                    <span class="group-label">新規</span>
                </div>
                <div class="ribbon-group">
                    <div class="ribbon-buttons">
                         <button id="rename-btn" disabled><span class="icon" id="icon-rename"></span><span class="label">名前の変更</span></button>
                         <button id="delete-btn" disabled><span class="icon" id="icon-delete"></span><span class="label">削除</span></button>
                    </div><span class="group-label">整理</span>
                </div>
            </div>
            <div class="address-bar-container">
                <div class="address-bar-nav">
                    <button id="nav-back-btn" disabled title="戻る"><svg class="icon" viewBox="0 0 16 16" fill="currentColor"><path d="M10.273 2.96a.75.75 0 00-1.06 0L4.47 7.704a.75.75 0 000 1.06l4.744 4.743a.75.75 0 101.06-1.06L6.062 8.234l4.21-4.214a.75.75 0 000-1.06z"/></svg></button>
                </div>
                <div id="address-bar" class="address-bar"></div>
                <input type="text" id="address-input" class="address-input" style="display: none;">
                <input type="search" id="search-box" class="search-box" placeholder="検索">
            </div>
        </div>
        <div class="main-content">
            <div class="nav-pane">
                 <div class="nav-item selected" id="nav-home"><span class="icon" id="icon-user-folder"></span> <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="file-view" id="file-view">
                <table class="file-table">
                    <thead><tr><th style="width: 50%;">名前</th><th style="width: 20%;">更新日時</th><th style="width: 20%;">種類</th><th style="width: 10%;">サイズ</th></tr></thead>
                    <tbody id="file-list-body"></tbody>
                </table>
            </div>
        </div>
        <div class="status-bar">
            <div id="item-count">0 項目</div>
            <div id="usage-display" class="usage-display">
                <div id="usage-bar" class="usage-bar"><div id="usage-fill" class="usage-fill"></div></div>
                <span id="usage-text"></span>
            </div>
        </div>
    </div>
    <input type="file" id="file-input" multiple hidden><input type="file" id="folder-input" webkitdirectory directory multiple hidden>
    <div id="drag-drop-overlay">ファイルをここにドロップ</div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const getEl = (id) => document.getElementById(id);
        const fileListBody = getEl('file-list-body'), deleteBtn = getEl('delete-btn'), renameBtn = getEl('rename-btn'),
              itemCountEl = getEl('item-count'), dragDropOverlay = getEl('drag-drop-overlay'), fileView = getEl('file-view'),
              addressBar = getEl('address-bar'), navBackBtn = getEl('nav-back-btn'), fileInput = getEl('file-input'),
              folderInput = getEl('folder-input'), usageFill = getEl('usage-fill'), usageText = getEl('usage-text'), searchBox = getEl('search-box'),
              addressInput = getEl('address-input');
        let currentPath = '/', selectedItems = new Map(), contextMenu = null, isSearchMode = false;

        const ICONS = {
            'upload-file': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5 20h14v-2H5v2zm0-10h4v6h6v-6h4l-7-7-7 7z"/></svg>',
            'upload-folder': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M11 5c0-1.1.9-2 2-2h6c1.1 0 2 .9 2 2v14c0 1.1-.9 2-2 2H3c-1.1 0-2-.9-2-2V7c0-1.1.9-2 2-2h5l2 2h3z"/></svg>',
            'new-folder': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M20 6h-8l-2-2H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-1 8h-3v3h-2v-3h-3v-2h3V9h2v3h3v2z"/></svg>',
            'rename': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83zM3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25z"/></svg>',
            'delete': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 4h-3.5l-1-1h-5l-1 1H5v2h14V4zM6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12z"/></svg>',
            'folder': '<svg viewBox="0 0 24 24" fill="#FFCA28"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>',
            'file': '<svg viewBox="0 0 24 24" fill="#E0E0E0"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zM13 9V3.5L18.5 9H13z"/></svg>',
            'context-open': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/></svg>',
            'context-download': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
        };
        ['upload-file','upload-folder','new-folder','rename','delete'].forEach(id => getEl(`icon-${id}`).innerHTML = ICONS[id]);
        getEl('icon-user-folder').innerHTML = ICONS.folder;

        const apiCall = async (action, formData) => {
            try {
                const response = await fetch(`?action=${action}`, { method: 'POST', body: formData });
                if (!response.ok) { const err = await response.json().catch(() => ({})); throw new Error(err.message || 'サーバーエラー'); }
                return await response.json();
            } catch (error) { alert(`エラー: ${error.message}`); return null; }
        };

        const updateAddressBar = (path) => {
            addressBar.innerHTML = '';
            const parts = path.split('/').filter(p => p);
            const homePart = document.createElement('span');
            homePart.className = 'address-bar-part'; homePart.textContent = 'database';
            homePart.onclick = () => navigateTo('/'); addressBar.appendChild(homePart);
            let currentBuildPath = '';
            parts.forEach(part => {
                addressBar.insertAdjacentHTML('beforeend', '<span class="address-bar-separator">&gt;</span>');
                currentBuildPath += `/${part}`;
                const partEl = document.createElement('span');
                partEl.className = 'address-bar-part'; partEl.textContent = part; partEl.dataset.path = currentBuildPath;
                partEl.onclick = () => navigateTo(partEl.dataset.path); addressBar.appendChild(partEl);
            });
            navBackBtn.disabled = path === '/';
        };

        const updateUsage = async () => {
            const data = await apiCall('get_usage', new FormData());
            if(data && data.success) {
                const percentage = data.total > 0 ? (data.used / data.total) * 100 : 0;
                usageFill.style.width = `${Math.min(percentage, 100)}%`;
                usageText.textContent = `${data.used_formatted} / ${data.total_formatted}`;
            }
        };

        const updateSelection = () => {
            fileListBody.querySelectorAll('tr.selected').forEach(row => row.classList.remove('selected'));
            selectedItems.forEach((_, key) => { const row = fileListBody.querySelector(`tr[data-path="${CSS.escape(key)}"]`); if (row) row.classList.add('selected'); });
            deleteBtn.disabled = selectedItems.size === 0;
            renameBtn.disabled = selectedItems.size !== 1;
            const totalItems = fileListBody.querySelectorAll('.file-item:not(.hidden)').length;
            const backItem = fileListBody.querySelector('.up-directory-item');
            const finalCount = backItem ? totalItems -1 : totalItems;
            itemCountEl.textContent = selectedItems.size > 0 ? `${selectedItems.size} 個の項目を選択` : `${finalCount} 項目`;
        };

        window.navigateTo = (path) => { currentPath = path; selectedItems.clear(); searchBox.value = ''; isSearchMode = false; loadDirectory(path); };
        const navigateUp = () => { if (currentPath === '/') return; navigateTo(currentPath.substring(0, currentPath.lastIndexOf('/')) || '/'); };
        navBackBtn.addEventListener('click', navigateUp);

        const renderItems = (files, isSearch = false) => {
            fileListBody.innerHTML = '';
            if (!isSearch && currentPath !== '/') {
                const backRow = document.createElement('tr');
                backRow.className = 'file-item up-directory-item';
                backRow.innerHTML = `<td colspan="4" style="cursor: pointer;"><div class="item-name-container"><span class="icon">${ICONS.folder}</span>..</div></td>`;
                backRow.addEventListener('dblclick', navigateUp);
                fileListBody.appendChild(backRow);
            }
            files.forEach(file => {
                const row = document.createElement('tr');
                const filePath = file.path || (currentPath === '/' ? `/${file.name}` : `${currentPath}/${file.name}`);
                row.className = `file-item ${isSearch ? 'search-result' : ''}`;
                row.dataset.path = filePath;
                row.dataset.name = file.name;
                row.dataset.isDir = file.is_dir;
                let nameCellHTML = `<div class="item-name-container"><span class="icon">${file.is_dir ? ICONS.folder : ICONS.file}</span><span class="item-name">${file.name}</span></div>`;
                if(isSearch) { nameCellHTML += `<div class="path">${filePath}</div>`; }
                row.innerHTML = `<td>${nameCellHTML}</td><td>${file.modified}</td><td>${file.type}</td><td>${file.size}</td>`;
                row.addEventListener('click', e => handleItemClick(e, file, row));
                row.addEventListener('dblclick', () => handleItemDblClick(file, filePath));
                row.addEventListener('contextmenu', e => handleItemContextMenu(e, file, row));
                fileListBody.appendChild(row);
            });
            updateSelection();
        };

        const loadDirectory = async (path) => {
            const formData = new FormData(); formData.append('path', path);
            const data = await apiCall('list', formData);
            if (!data || !data.success) { if (path !== '/') navigateUp(); return; }
            isSearchMode = false;
            addressBar.style.display = 'flex';
            addressInput.style.display = 'none';
            searchBox.style.display = 'block';
            updateAddressBar(data.path);
            renderItems(data.files, false);
            updateUsage();
        };
        
        const searchFiles = async (term) => {
            const formData = new FormData(); formData.append('query', term);
            const data = await apiCall('search', formData);
            if (!data || !data.success) return;
            isSearchMode = true;
            addressBar.style.display = 'none';
            addressInput.style.display = 'none';
            searchBox.style.display = 'block';
            renderItems(data.files, true);
        };

        const handleItemClick = (e, file, row) => {
            hideContextMenu();
            const itemPath = row.dataset.path;
            if (e.ctrlKey) {
                selectedItems.has(itemPath) ? selectedItems.delete(itemPath) : selectedItems.set(itemPath, file);
            } else {
                if (!selectedItems.has(itemPath) || selectedItems.size > 1) {
                    selectedItems.clear();
                    selectedItems.set(itemPath, file);
                }
            }
            updateSelection();
        };

        const handleItemDblClick = (file, path) => { file.is_dir ? navigateTo(path) : downloadFile(path); };

        const handleItemContextMenu = (e, file, row) => {
            e.preventDefault();
            const itemPath = row.dataset.path;
            if (!selectedItems.has(itemPath)) { selectedItems.clear(); selectedItems.set(itemPath, file); updateSelection(); }
            showContextMenu(e, file, row);
        };

        fileView.addEventListener('click', e => { if (e.target === fileView || (e.target.closest('table') && !e.target.closest('.file-item'))) { selectedItems.clear(); updateSelection(); hideContextMenu(); } });

        const showContextMenu = (e, fileInfo, element) => {
            hideContextMenu();
            contextMenu = document.createElement('div');
            contextMenu.className = 'context-menu';
            contextMenu.style.left = `${e.pageX}px`;
            contextMenu.style.top = `${e.pageY}px`;

            let menuItemsHTML = '';
            const openActionText = fileInfo.is_dir ? '開く' : 'ダウンロード';
            const openIcon = fileInfo.is_dir ? ICONS['context-open'] : ICONS['context-download'];
            menuItemsHTML += `<div class="context-menu-item" data-action="open"><span class="icon">${openIcon}</span><span>${openActionText}</span></div>`;

            if (!fileInfo.is_dir) {
                menuItemsHTML += `
                    <div class="context-menu-item has-submenu">
                        <span>アプリで開く</span>
                        <div class="submenu">
                            <div class="context-menu-item" data-action="open-with-notepad">
                                <span>Notepad</span>
                            </div>
                        </div>
                    </div>
                `;
            }

            menuItemsHTML += `<div class="context-menu-separator"></div>
                          <div class="context-menu-item" data-action="delete"><span class="icon">${ICONS.delete}</span><span>削除</span></div>
                          <div class="context-menu-item" data-action="rename"><span class="icon">${ICONS.rename}</span><span>名前の変更</span></div>`;

            contextMenu.innerHTML = menuItemsHTML;
            document.body.appendChild(contextMenu);

            contextMenu.addEventListener('click', ev => {
                const item = ev.target.closest('.context-menu-item');
                if (item) {
                    ev.stopPropagation();
                    handleContextMenuAction(item.dataset.action, fileInfo, element);
                }
            });
        };
        
        const hideContextMenu = () => contextMenu && contextMenu.remove();

        const handleContextMenuAction = (action, fileInfo, element) => {
            hideContextMenu();
            const itemPath = element.dataset.path;
            switch (action) {
                case 'open':
                    fileInfo.is_dir ? navigateTo(itemPath) : downloadFile(itemPath);
                    break;
                case 'open-with-notepad':
                    window.parent.postMessage({
                        type: 'openWithApp',
                        app: 'notepad',
                        filePath: itemPath
                    }, '*');
                    break;
                case 'rename':
                    initiateRename(element);
                    break;
                case 'delete':
                    deleteItems();
                    break;
            }
        };
        
        const initiateRename = (rowElement) => {
            const nameContainer = rowElement.querySelector('.item-name-container');
            const nameSpan = nameContainer.querySelector('.item-name');
            if (!nameSpan || nameContainer.querySelector('input')) return;
            const oldName = nameSpan.textContent;
            nameSpan.style.display = 'none';
            const input = document.createElement('input');
            input.type = 'text'; input.value = oldName;
            const finishRename = async () => {
                input.removeEventListener('blur', finishRename); input.removeEventListener('keydown', keydownHandler);
                const newName = input.value.trim();
                if (newName && newName !== oldName) {
                    const formData = new FormData();
                    formData.append('item_path', rowElement.dataset.path);
                    formData.append('new_name', newName);
                    await apiCall('rename', formData);
                }
                isSearchMode ? searchFiles(searchBox.value) : loadDirectory(currentPath);
            };
            const keydownHandler = e => { if (e.key === 'Enter') finishRename(); else if (e.key === 'Escape') { input.removeEventListener('blur', finishRename); input.remove(); nameSpan.style.display = 'inline'; } };
            input.addEventListener('blur', finishRename); input.addEventListener('keydown', keydownHandler);
            nameContainer.appendChild(input); input.focus(); input.select();
        };

        const uploadFiles = async (files, isFolder = false) => {
            const formData = new FormData(); formData.append('path', currentPath);
            const relativePaths = [];
            for (const file of files) {
                formData.append('files[]', file, file.name);
                if (isFolder) relativePaths.push(file.webkitRelativePath);
            }
            if (isFolder) formData.append('relative_paths', JSON.stringify(relativePaths));
            if (await apiCall('upload', formData)) loadDirectory(currentPath);
        };

        const createFolder = async () => {
            const name = prompt('新しいフォルダ名:', '新しいフォルダー');
            if (!name) return;
            const formData = new FormData(); formData.append('path', currentPath); formData.append('name', name);
            if (await apiCall('create_folder', formData)) loadDirectory(currentPath);
        };
        const deleteItems = async () => {
            if (selectedItems.size === 0 || !confirm(`${selectedItems.size}個の項目を完全に削除しますか？`)) return;
            const itemsToDelete = Array.from(selectedItems.values()).map(file => ({ name: file.name, path: file.path || (currentPath === '/' ? `/${file.name}` : `${currentPath}/${file.name}`) }));
            const formData = new FormData(); formData.append('items', JSON.stringify(Array.from(selectedItems.entries()).map(([key, val]) => ({ name: val.name, path: key }))));
            if (await apiCall('delete', formData)) {
                selectedItems.clear();
                isSearchMode ? searchFiles(searchBox.value) : loadDirectory(currentPath);
            }
        };
        const downloadFile = (filePath) => { window.location.href = `?action=download&file=${encodeURIComponent(filePath)}`; };
        
        searchBox.addEventListener('input', () => {
            const term = searchBox.value.trim();
            if (term === '') { if (isSearchMode) navigateTo(currentPath); }
            else { searchFiles(term); }
        });

        const switchToEditAddress = () => {
            addressBar.style.display = 'none';
            addressInput.style.display = 'flex';
            addressInput.value = currentPath;
            addressInput.focus();
            addressInput.select();
        };

        const switchToDisplayAddress = () => {
            addressBar.style.display = 'flex';
            addressInput.style.display = 'none';
            updateAddressBar(currentPath);
        };
        
        addressBar.addEventListener('click', switchToEditAddress);
        addressInput.addEventListener('blur', switchToDisplayAddress);
        addressInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                navigateTo(addressInput.value);
            } else if (e.key === 'Escape') {
                addressInput.blur();
            }
        });
        
        getEl('upload-file-btn').addEventListener('click', () => fileInput.click());
        getEl('upload-folder-btn').addEventListener('click', () => folderInput.click());
        fileInput.addEventListener('change', (e) => uploadFiles(e.target.files, false));
        folderInput.addEventListener('change', (e) => uploadFiles(e.target.files, true));
        getEl('new-folder-btn').addEventListener('click', createFolder);
        deleteBtn.addEventListener('click', deleteItems);
        renameBtn.addEventListener('click', () => {
            if(selectedItems.size !== 1) return;
            const path = selectedItems.keys().next().value;
            const row = fileListBody.querySelector(`tr[data-path="${CSS.escape(path)}"]`);
            if (row) initiateRename(row);
        });
        getEl('nav-home').addEventListener('click', () => navigateTo('/'));
        
        const body = document.body;
        body.addEventListener('dragenter', e => { e.preventDefault(); dragDropOverlay.classList.add('visible'); });
        body.addEventListener('dragover', e => e.preventDefault());
        body.addEventListener('dragleave', e => { if (e.relatedTarget === null || !body.contains(e.target)) dragDropOverlay.classList.remove('visible'); });
        body.addEventListener('drop', e => { e.preventDefault(); dragDropOverlay.classList.remove('visible'); uploadFiles(e.dataTransfer.files, false); });
        
        document.addEventListener('click', e => { if (contextMenu && !contextMenu.contains(e.target)) hideContextMenu(); });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') hideContextMenu();
            if (e.key === 'Delete' && selectedItems.size > 0 && document.activeElement.tagName !== 'INPUT') deleteItems();
            if (e.key === 'F2' && selectedItems.size === 1) renameBtn.click();
        });

        loadDirectory(currentPath);
    });
    </script>
</body>
</html>
