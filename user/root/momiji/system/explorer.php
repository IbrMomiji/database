<?php
// =================================================================
// エクスプローラーシステム (v1.2)
//
// - 大容量ファイルのアップロードに対応
// - 右クリックメニューによるダウンロード機能を実装
// - シングルクリックは「選択」、ダブルクリックは「開く」に操作を統一
// =================================================================

// --- アップロード上限緩和 ---
ini_set('upload_max_filesize', '512M');
ini_set('post_max_size', '512M');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- 定数と設定 ---
define('USER_BASE_DIR', __DIR__ . '/../user');
define('MAX_STORAGE_MB', 100);
define('MAX_STORAGE_BYTES', MAX_STORAGE_MB * 1024 * 1024);

// --- 認証チェック ---
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

// --- ユーティリティ関数 ---

/**
 * ディレクトリサイズを再帰的に計算する
 */
function getDirectorySize($dir) {
    if (!is_dir($dir)) return 0;
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * ファイルサイズを人間が読みやすい形式に変換する
 */
function formatBytes($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    if ($bytes > 0) return $bytes . ' Bytes';
    return '0 Bytes';
}

/**
 * 安全なファイルパスを生成し、ディレクトリトラバーサルを防ぐ
 * @param string $baseDir ユーザーのベースディレクトリ
 * @param string $path ユーザーからのパス入力
 * @return string|false 正規化された安全なパス、または失敗した場合はfalse
 */
function getSafePath($baseDir, $path) {
    // 1. パスを正規化
    $path = str_replace('\\', '/', $path);
    $path = '/' . trim($path, '/');
    
    // 2. '..' や '.' を解決
    $parts = explode('/', $path);
    $safeParts = [];
    foreach ($parts as $part) {
        if ($part === '.' || $part === '') continue;
        if ($part === '..') {
            array_pop($safeParts);
        } else {
            $safeParts[] = $part;
        }
    }
    
    $finalPath = $baseDir . '/' . implode('/', $safeParts);
    
    // 3. 最終的なパスがベースディレクトリ内にあることを確認
    $realBaseDir = realpath($baseDir);
    if($realBaseDir === false) return false; // ベースディレクトリが存在しない
    
    $checkPath = $finalPath;
    
    // パスが存在しない場合は、親ディレクトリでチェック
    if (!file_exists($checkPath)) {
        $checkPath = dirname($checkPath);
    }
    
    $realFinalPath = realpath($checkPath);
    
    if ($realFinalPath === false || strpos($realFinalPath, $realBaseDir) !== 0) {
        return false;
    }

    return $finalPath;
}


/**
 * ディレクトリを再帰的に削除する
 */
function deleteDirectoryRecursively($dir) {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), ['.','..']);
    foreach ($files as $file) {
      is_dir("$dir/$file") ? deleteDirectoryRecursively("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}


// =================================================================
// APIリクエスト処理
// =================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $path = isset($_REQUEST['path']) ? $_REQUEST['path'] : '/'; // POST or GET
    
    try {
        // ダウンロードアクションはパス検証が異なるため、先に行う
        if ($action === 'download') {
            $file_to_download = getSafePath($user_dir, $path);
            if ($file_to_download === false || !is_file($file_to_download)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'ファイルが見つかりません。']);
                exit;
            }
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

        $safe_base_path = getSafePath($user_dir, $path);
        if ($safe_base_path === false) {
            throw new Exception('無効なパスです。');
        }

        switch ($action) {
            case 'list':
                $files = [];
                if (!is_dir($safe_base_path)) {
                     throw new Exception('ディレクトリが見つかりません。');
                }
                $items = scandir($safe_base_path);
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $item_path = $safe_base_path . '/' . $item;
                    $is_dir = is_dir($item_path);
                    $files[] = [
                        'name' => $item,
                        'is_dir' => $is_dir,
                        'size' => $is_dir ? '' : formatBytes(filesize($item_path)),
                        'modified' => date('Y/m/d H:i', filemtime($item_path))
                    ];
                }
                usort($files, function($a, $b) {
                    if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
                    return strcasecmp($a['name'], $b['name']);
                });
                echo json_encode(['success' => true, 'files' => $files]);
                break;

            case 'get_usage':
                echo json_encode(['success' => true, 'used' => getDirectorySize($user_dir), 'total' => MAX_STORAGE_BYTES, 'used_formatted' => formatBytes(getDirectorySize($user_dir)), 'total_formatted' => formatBytes(MAX_STORAGE_BYTES)]);
                break;
            
            case 'upload':
                if (!isset($_FILES['files'])) throw new Exception('アップロードされたファイルがありません。');
                
                $total_upload_size = 0;
                foreach ($_FILES['files']['size'] as $size) $total_upload_size += $size;
                if (getDirectorySize($user_dir) + $total_upload_size > MAX_STORAGE_BYTES) {
                    throw new Exception('容量オーバーです。'.formatBytes(MAX_STORAGE_BYTES).'の上限を超過します。');
                }

                foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
                    $file_name = basename($_FILES['files']['name'][$key]);
                    if (!move_uploaded_file($tmp_name, $safe_base_path . '/' . $file_name)) {
                        throw new Exception("'$file_name' のアップロードに失敗しました。");
                    }
                }
                echo json_encode(['success' => true, 'message' => 'アップロードが完了しました。']);
                break;

            case 'create_folder':
                $folder_name = $_POST['name'] ?? '';
                if (empty($folder_name) || preg_match('/[\\\\\/:\*\?"<>|]/', $folder_name)) throw new Exception('無効なフォルダ名です。');
                $new_folder_path = $safe_base_path . '/' . $folder_name;
                if (file_exists($new_folder_path)) throw new Exception('同じ名前のフォルダまたはファイルが既に存在します。');
                if (!mkdir($new_folder_path, 0775)) throw new Exception('フォルダの作成に失敗しました。');
                echo json_encode(['success' => true, 'message' => 'フォルダを作成しました。']);
                break;

            case 'delete':
                $items_to_delete = json_decode($_POST['items'] ?? '[]', true);
                if (empty($items_to_delete)) throw new Exception('削除するアイテムが指定されていません。');
                
                foreach ($items_to_delete as $item_name) {
                    $item_path = getSafePath($user_dir, $path . '/' . $item_name);
                    if ($item_path === false) continue;
                    if (is_dir($item_path)) deleteDirectoryRecursively($item_path);
                    else if (file_exists($item_path)) unlink($item_path);
                }
                echo json_encode(['success' => true, 'message' => '選択されたアイテムを削除しました。']);
                break;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================
// HTMLレンダリング
// =================================================================
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explorer - <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root { --accent-color: #2a579a; --border-color: #dcdcdc; --hover-bg: #e5f3ff; }
        html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; font-family: 'Segoe UI', 'Meiryo UI', sans-serif; font-size: 14px; background-color: #f0f0f0; }
        .explorer-container { display: flex; flex-direction: column; height: 100%; }
        /* Toolbar */
        .toolbar { flex-shrink: 0; padding: 8px; background: #fff; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 8px; }
        .toolbar button { background: #fff; border: 1px solid #ccc; border-radius: 4px; padding: 6px 12px; cursor: pointer; display: flex; align-items: center; gap: 6px; }
        .toolbar button:hover { background-color: #e9e9e9; border-color: #adadad; }
        .toolbar button:disabled { cursor: not-allowed; color: #999; }
        /* Main Content */
        .main-content { flex-grow: 1; display: flex; overflow: hidden; }
        .nav-pane { width: 200px; background: #fafafa; border-right: 1px solid var(--border-color); padding: 8px; flex-shrink: 0; overflow-y: auto; }
        .file-view { flex-grow: 1; overflow-y: auto; }
        /* File List Table */
        .file-table { width: 100%; border-collapse: collapse; }
        .file-table th, .file-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border-color); user-select: none; }
        .file-table th { background: #f8f8f8; font-weight: normal; color: #555; }
        .file-table tr:not(.no-hover):hover { background-color: var(--hover-bg); }
        .file-table tr.selected { background-color: var(--accent-color) !important; color: white; }
        .file-table tr.selected a { color: white; }
        .file-item .icon { margin-right: 8px; vertical-align: middle; }
        .file-item a { text-decoration: none; color: inherit; }
        /* Status Bar */
        .status-bar { flex-shrink: 0; padding: 6px 12px; background: #f0f0f0; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; }
        .usage-bar { width: 200px; height: 18px; background: #e0e0e0; border: 1px solid #ccc; border-radius: 3px; overflow: hidden; }
        .usage-bar-fill { width: 0%; height: 100%; background: var(--accent-color); transition: width 0.3s; }
        /* Icons */
        .icon { display: inline-block; width: 16px; height: 16px; background-repeat: no-repeat; background-position: center; }
        .icon-upload { background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="%23000" d="M4 14h8v-2H4v2zm1-4h6V7h3l-6-6-6 6h3v3z"/></svg>'); }
        .icon-new-folder { background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="%23000" d="M7 2l2 2h5v10H2V2h5zm2 4H2v8h12V6H9z"/></svg>'); }
        .icon-delete { background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="%23000" d="M2 4v11h12V4H2zm3 9H4V6h1v7zm2 0H6V6h1v7zm2 0H8V6h1v7zm2 0h-1V6h1v7zm2-10h-4l-1-1H6L5 3H1v2h14V3h-4z"/></svg>'); }
        .icon-folder { background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="%23000" d="M14 4h-5l-2-2H2v12h12V4z"/></svg>'); }
        .icon-file { background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="%23000" d="M10 1H4v14h10V5l-4-4zm-1 5V2l4 4h-4z"/></svg>'); }
        .icon-back { background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="%23000" d="M13 3v2H5.4L8 7.6l-1.4 1.4L1 4l5.6-5L8 0.4 5.4 3H13z"/></svg>'); }
        .icon-download { background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="currentColor" d="M4 14h8v-2H4v2zm9-9h-3V1H6v4H3l5 5 5-5z"/></svg>'); }
        /* Context Menu */
        .file-context-menu { position: fixed; z-index: 1000; background: #fff; border: 1px solid #ccc; box-shadow: 2px 2px 5px rgba(0,0,0,0.1); padding: 4px 0; min-width: 160px; border-radius: 4px; }
        .file-context-menu .menu-item { padding: 8px 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .file-context-menu .menu-item:hover { background-color: var(--hover-bg); }

        /* Other */
        #file-upload-input { display: none; }
        .loading-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); display: flex; align-items: center; justify-content: center; z-index: 100; }
    </style>
</head>
<body>
    <div class="explorer-container">
        <div class="toolbar">
            <button id="upload-btn"><span class="icon icon-upload"></span>アップロード</button>
            <button id="new-folder-btn"><span class="icon icon-new-folder"></span>新しいフォルダー</button>
            <button id="delete-btn" disabled><span class="icon icon-delete"></span>削除</button>
        </div>
        <div class="main-content">
            <div class="nav-pane"><strong><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="file-view"><table class="file-table"><thead><tr><th>名前</th><th>更新日時</th><th>サイズ</th></tr></thead><tbody id="file-list-body"></tbody></table></div>
        </div>
        <div class="status-bar">
            <div id="item-count">0 個の項目</div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="usage-bar"><div id="usage-bar-fill" class="usage-bar-fill"></div></div>
                <div id="usage-text">0 Bytes / <?php echo MAX_STORAGE_MB; ?> MB</div>
            </div>
        </div>
    </div>
    <input type="file" id="file-upload-input" multiple>
    <div class="loading-overlay" style="display: none;"><span>処理中...</span></div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const fileListBody = document.getElementById('file-list-body');
        const itemCountEl = document.getElementById('item-count');
        const usageBarFill = document.getElementById('usage-bar-fill');
        const usageTextEl = document.getElementById('usage-text');
        const uploadBtn = document.getElementById('upload-btn');
        const newFolderBtn = document.getElementById('new-folder-btn');
        const deleteBtn = document.getElementById('delete-btn');
        const uploadInput = document.getElementById('file-upload-input');
        const loadingOverlay = document.querySelector('.loading-overlay');
        let currentPath = '/';
        let activeFileContextMenu = null;

        function showLoading(show) { loadingOverlay.style.display = show ? 'flex' : 'none'; }

        async function apiRequest(action, formData) {
            showLoading(true);
            try {
                const response = await fetch(`explorer.php?action=${action}`, { method: 'POST', body: formData });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.message || `サーバーエラー: ${response.status}`);
                return data;
            } catch (error) {
                alert(`エラー: ${error.message}`);
                return null;
            } finally {
                showLoading(false);
            }
        }

        async function loadFiles(path) {
            currentPath = path;
            const formData = new FormData();
            formData.append('path', path);
            const data = await apiRequest('list', formData);

            if (data) {
                fileListBody.innerHTML = '';
                if (currentPath !== '/') {
                    const backRow = document.createElement('tr');
                    backRow.className = 'file-item no-hover';
                    backRow.innerHTML = `<td colspan="3"><span class="icon icon-back"></span><a href="#" id="back-link">..</a></td>`;
                    fileListBody.appendChild(backRow);
                    document.getElementById('back-link').addEventListener('click', (e) => {
                        e.preventDefault();
                        const parentPath = currentPath.substring(0, currentPath.lastIndexOf('/', currentPath.length - 2) + 1);
                        loadFiles(parentPath);
                    });
                }
                data.files.forEach(file => {
                    const row = document.createElement('tr');
                    row.className = 'file-item';
                    row.dataset.name = file.name;
                    row.dataset.isDir = file.is_dir;
                    const iconClass = file.is_dir ? 'icon-folder' : 'icon-file';
                    row.innerHTML = `<td><span class="icon ${iconClass}"></span><a href="#">${file.name}</a></td><td>${file.modified}</td><td>${file.size}</td>`;
                    fileListBody.appendChild(row);
                });
                itemCountEl.textContent = `${data.files.length} 個の項目`;
            }
            updateUsage();
            updateSelection();
        }
        
        async function updateUsage() {
            const data = await apiRequest('get_usage', new FormData());
            if (data) {
                usageTextEl.textContent = `${data.used_formatted} / ${data.total_formatted}`;
                usageBarFill.style.width = `${Math.min(100, (data.used / data.total) * 100)}%`;
            }
        }
        
        function updateSelection() { deleteBtn.disabled = fileListBody.querySelectorAll('.selected').length === 0; }

        function hideFileContextMenu() {
            if (activeFileContextMenu) {
                activeFileContextMenu.remove();
                activeFileContextMenu = null;
            }
        }

        function showFileContextMenu(e, row) {
            hideFileContextMenu();
            const fileName = row.dataset.name;
            const menu = document.createElement('div');
            menu.className = 'file-context-menu';
            menu.innerHTML = `<div class="menu-item" data-action="download"><span class="icon icon-download"></span>ダウンロード</div>`;
            document.body.appendChild(menu);

            const menuRect = menu.getBoundingClientRect();
            let x = e.clientX, y = e.clientY;
            if (x + menuRect.width > window.innerWidth) x = window.innerWidth - menuRect.width - 5;
            if (y + menuRect.height > window.innerHeight) y = window.innerHeight - menuRect.height - 5;
            menu.style.left = `${x}px`;
            menu.style.top = `${y}px`;
            
            activeFileContextMenu = menu;

            menu.addEventListener('click', (event) => {
                const item = event.target.closest('.menu-item');
                if (item?.dataset.action === 'download') {
                    window.location.href = `explorer.php?action=download&path=${encodeURIComponent(currentPath + fileName)}`;
                }
                hideFileContextMenu();
            });
        }
        
        fileListBody.addEventListener('click', e => {
            const row = e.target.closest('.file-item');
            if (!row || e.target.id === 'back-link') return;
            e.preventDefault();
            if (!e.ctrlKey) fileListBody.querySelectorAll('.selected').forEach(r => r.classList.remove('selected'));
            row.classList.toggle('selected');
            updateSelection();
        });

        fileListBody.addEventListener('dblclick', e => {
            const row = e.target.closest('.file-item');
            if (!row || e.target.id === 'back-link' || row.dataset.isDir !== 'true') return;
            e.preventDefault();
            loadFiles(currentPath + row.dataset.name + '/');
        });
        
        fileListBody.addEventListener('contextmenu', e => {
            const row = e.target.closest('.file-item');
            if (!row || row.classList.contains('no-hover') || row.dataset.isDir === 'true') return;
            e.preventDefault();
            if (!row.classList.contains('selected')) {
                fileListBody.querySelectorAll('.selected').forEach(r => r.classList.remove('selected'));
                row.classList.add('selected');
                updateSelection();
            }
            showFileContextMenu(e, row);
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.file-context-menu')) hideFileContextMenu();
        }, true);
        
        uploadBtn.addEventListener('click', () => uploadInput.click());
        uploadInput.addEventListener('change', async () => {
            if (uploadInput.files.length === 0) return;
            const formData = new FormData();
            formData.append('path', currentPath);
            for (const file of uploadInput.files) formData.append('files[]', file);
            await apiRequest('upload', formData);
            uploadInput.value = '';
            loadFiles(currentPath);
        });

        newFolderBtn.addEventListener('click', async () => {
            const folderName = prompt('新しいフォルダー名を入力してください:', '新しいフォルダー');
            if (!folderName) return;
            const formData = new FormData();
            formData.append('path', currentPath);
            formData.append('name', folderName);
            await apiRequest('create_folder', formData);
            loadFiles(currentPath);
        });

        deleteBtn.addEventListener('click', async () => {
            const selectedRows = fileListBody.querySelectorAll('.selected');
            if (selectedRows.length === 0) return;
            const itemsToDelete = Array.from(selectedRows).map(row => row.dataset.name);
            if (!confirm(`${itemsToDelete.join(', ')}\n\nこれらのアイテムを完全に削除しますか？`)) return;
            const formData = new FormData();
            formData.append('path', currentPath);
            formData.append('items', JSON.stringify(itemsToDelete));
            await apiRequest('delete', formData);
            loadFiles(currentPath);
        });

        loadFiles('/');
    });
    </script>
</body>
</html>
