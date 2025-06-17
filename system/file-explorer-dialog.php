<?php
// =================================================================
// File Explorer Dialog (file-explorer-dialog.php)
// - Provides a rich UI for browsing and selecting files for other apps.
// =================================================================

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('FILEDIALOG_USER_BASE_DIR', __DIR__ . '/../user');
if (!isset($_SESSION['username'])) {
    http_response_code(403);
    die('Authentication required.');
}
$username = $_SESSION['username'];
$user_dir = FILEDIALOG_USER_BASE_DIR . '/' . $username;

// API to list files
if (isset($_GET['action']) && $_GET['action'] === 'list_files') {
    header('Content-Type: application/json; charset=utf-8');
    $path = $_POST['path'] ?? '/';
    $dir_path = realpath($user_dir . '/' . $path);
    $real_user_dir = realpath($user_dir);

    if (!$dir_path || strpos($dir_path, $real_user_dir) !== 0 || !is_dir($dir_path)) {
        echo json_encode(['success' => false, 'message' => '無効なディレクトリです。']);
        exit;
    }
    
    $items = scandir($dir_path);
    $files = []; $dirs = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $item_path = $dir_path . '/' . $item;
        $is_dir = is_dir($item_path);
        $file_info = [
            'name' => $item,
            'is_dir' => $is_dir,
            'size' => $is_dir ? '' : filesize($item_path),
            'modified' => date('Y/m/d H:i', filemtime($item_path))
        ];
        if ($is_dir) $dirs[] = $file_info; else $files[] = $file_info;
    }
    // Dirs first, then files, both sorted alphabetically
    usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    
    echo json_encode(['success' => true, 'items' => array_merge($dirs, $files)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>File Dialog</title>
<style>
    :root { --bg-main: #FFFFFF; --bg-secondary: #F0F0F0; --text-main: #000000; --border-color: #A0A0A0; --selection-bg: #0078D7; --selection-text: #FFFFFF; }
    html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; font-family: 'MS UI Gothic', 'Segoe UI', Meiryo, sans-serif; font-size: 9pt; background: var(--bg-secondary); }
    .dialog-container { display: flex; flex-direction: column; height: 100%; }
    .header { padding: 8px; border-bottom: 1px solid var(--border-color); display: flex; gap: 8px; align-items: center; flex-shrink: 0; }
    .header input { flex-grow: 1; }
    .main-content { flex-grow: 1; background: var(--bg-main); border: 1px inset; overflow-y: auto; }
    .file-table { width: 100%; border-collapse: collapse; }
    .file-table th { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); position: sticky; top: 0; user-select: none; }
    .file-table td { padding: 4px 8px; white-space: nowrap; cursor: default; }
    .file-table tr:hover { background: #EAF2FB; }
    .file-table tr.selected { background: var(--selection-bg); color: var(--selection-text); }
    .footer { padding: 12px; display: grid; grid-template-columns: 80px 1fr; grid-template-rows: auto auto; gap: 8px; align-items: center; flex-shrink: 0; }
    .footer .buttons { grid-column: 2; text-align: right; }
</style>
</head>
<body>
<div class="dialog-container">
    <div class="header">
        <label>現在のパス:</label>
        <input type="text" id="path-input" readonly>
    </div>
    <div class="main-content">
        <table class="file-table">
            <thead><tr><th>名前</th><th>更新日時</th><th>サイズ</th></tr></thead>
            <tbody id="file-list"></tbody>
        </table>
    </div>
    <div class="footer">
        <label for="filename-input">ファイル名:</label>
        <input type="text" id="filename-input">
        <div class="buttons">
            <button id="action-btn">開く</button>
            <button id="cancel-btn">キャンセル</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const getEl = id => document.getElementById(id);
    const pathInput = getEl('path-input');
    const fileListBody = getEl('file-list');
    const filenameInput = getEl('filename-input');
    const actionBtn = getEl('action-btn');
    const cancelBtn = getEl('cancel-btn');

    let currentDir = '/';
    const params = new URLSearchParams(window.location.search);
    const sourceWindowId = params.get('source');
    const mode = params.get('mode') || 'open';
    const initialPath = params.get('path');

    document.title = mode === 'open' ? 'ファイルを開く' : '名前を付けて保存';
    actionBtn.textContent = mode === 'open' ? '開く' : '保存';
    if (mode === 'save' && initialPath) filenameInput.value = initialPath.split('/').pop();

    const api = async (path) => {
        const formData = new FormData(); formData.append('path', path);
        return fetch('?action=list_files', { method: 'POST', body: formData }).then(res => res.json());
    };

    const renderFiles = async (dir) => {
        currentDir = dir;
        pathInput.value = dir;
        fileListBody.innerHTML = '<tr><td colspan="3">読み込み中...</td></tr>';
        const result = await api(dir);
        fileListBody.innerHTML = '';
        if (dir !== '/') {
            const upRow = fileListBody.insertRow();
            upRow.innerHTML = `<td colspan="3" style="cursor:pointer;">.. (上のフォルダーへ)</td>`;
            upRow.dataset.type = 'dir';
            upRow.dataset.name = '..';
        }
        if (result.success) {
            result.items.forEach(item => {
                const row = fileListBody.insertRow();
                row.dataset.name = item.name;
                row.dataset.type = item.is_dir ? 'dir' : 'file';
                row.innerHTML = `<td>${item.name}</td><td>${item.modified}</td><td>${item.is_dir ? '' : (item.size + ' B')}</td>`;
            });
        }
    };
    
    fileListBody.addEventListener('click', e => {
        const row = e.target.closest('tr');
        if (!row) return;
        document.querySelectorAll('.file-table tr.selected').forEach(r => r.classList.remove('selected'));
        row.classList.add('selected');
        if (row.dataset.type === 'file') filenameInput.value = row.dataset.name;
    });

    fileListBody.addEventListener('dblclick', e => {
        const row = e.target.closest('tr');
        if (!row) return;
        if (row.dataset.type === 'dir') {
            let newDir = row.dataset.name === '..'
                ? currentDir.substring(0, currentDir.lastIndexOf('/')) || '/'
                : (currentDir === '/' ? '' : currentDir) + '/' + row.dataset.name;
            renderFiles(newDir);
        } else {
            actionBtn.click();
        }
    });

    const sendResponseAndClose = (filePath = null) => {
        if (sourceWindowId && window.parent) {
             window.parent.postMessage({ type: 'fileDialogResponse', filePath: filePath, mode, sourceWindowId }, '*');
        }
    };

    actionBtn.addEventListener('click', () => {
        const filename = filenameInput.value.trim();
        if (!filename) { alert('ファイル名を入力してください。'); return; }
        const normalizedDir = currentDir.endsWith('/') ? currentDir.slice(0, -1) : currentDir;
        const fullPath = (normalizedDir === '/' ? '' : normalizedDir) + '/' + filename;
        sendResponseAndClose(fullPath);
    });
    
    cancelBtn.addEventListener('click', () => {
         sendResponseAndClose(null);
    });
    
    renderFiles(initialPath ? ('/' + initialPath.split('/').slice(0, -1).join('/')) : '/');
});
</script>
</body>
</html>
