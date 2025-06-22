<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('FILEDIALOG_USER_BASE_DIR', __DIR__ . '/../../user');
define('SETTINGS_DIR', '.settings');

if (!isset($_SESSION['user_id'], $_SESSION['user_uuid'])) {
    http_response_code(403);
    die('Authentication required.');
}

$user_dir = FILEDIALOG_USER_BASE_DIR . '/' . $_SESSION['user_uuid'];

function getSafePath_Dialog($baseDir, $path) {
    $path = str_replace('\\', '/', $path);
    $path = '/' . ltrim($path, '/');
    $realBaseDir = realpath($baseDir);
    
    if (strpos($path, '..') !== false) {
        return false;
    }

    $fullPath = $realBaseDir . $path;
    
    $finalPath = realpath($fullPath);
    if ($finalPath === false) {
        $parent = dirname($fullPath);
        if(!is_dir($parent)) return false;
        $finalPath = realpath($parent) . '/' . basename($fullPath);
    }

    if ($realBaseDir === false || strpos($finalPath, $realBaseDir) !== 0) {
        return false;
    }
    return rtrim($finalPath, '/');
}

function deleteDirectoryRecursively_Dialog($dir) {
    if (!is_dir($dir)) return false;
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $path = $fileinfo->getRealPath();
            if ($fileinfo->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    } catch(Exception $e) {
        return false;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $path = $_POST['path'] ?? '/';
    $safe_path = getSafePath_Dialog($user_dir, $path);

    if ($safe_path === false) {
        echo json_encode(['success' => false, 'message' => 'Invalid path provided.']);
        exit;
    }
    
    try {
        switch ($action) {
            case 'list_files':
                $items = [];
                if (is_dir($safe_path)) {
                    $files = scandir($safe_path);
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') continue;
                        if (strpos($file, SETTINGS_DIR) === 0) continue;
                        
                        $item_path = $safe_path . '/' . $file;
                        $is_dir = is_dir($item_path);
                        $items[] = [
                            'name' => $file,
                            'is_dir' => $is_dir,
                            'modified' => date('Y/m/d H:i', filemtime($item_path)),
                            'size' => $is_dir ? 0 : filesize($item_path)
                        ];
                    }
                }
                echo json_encode(['success' => true, 'items' => $items]);
                break;
            case 'create_folder':
                $name = '新しいフォルダー';
                $i = 1;
                $new_path = $safe_path . '/' . $name;
                while(file_exists($new_path)) {
                    $name = '新しいフォルダー (' . ++$i . ')';
                    $new_path = $safe_path . '/' . $name;
                }
                if(mkdir($new_path, 0775, true)) {
                    echo json_encode(['success' => true, 'name' => $name]);
                } else {
                    throw new Exception('Failed to create folder.');
                }
                break;
             case 'create_file':
                $name = '新規テキストドキュメント.txt';
                $i = 1;
                $new_path = $safe_path . '/' . $name;
                while(file_exists($new_path)) {
                    $name = '新規テキストドキュメント (' . ++$i . ').txt';
                    $new_path = $safe_path . '/' . $name;
                }
                if(touch($new_path)) {
                    echo json_encode(['success' => true, 'name' => $name]);
                } else {
                    throw new Exception('Failed to create file.');
                }
                break;
            case 'rename_item':
                $old_name = $_POST['old_name'];
                $new_name = $_POST['new_name'];
                $old_path = getSafePath_Dialog($user_dir, $path . '/' . $old_name);
                $new_path = getSafePath_Dialog($user_dir, $path . '/' . $new_name);

                if ($old_path && $new_path && file_exists($old_path) && !file_exists($new_path)) {
                    if (rename($old_path, $new_path)) {
                        echo json_encode(['success' => true]);
                    } else {
                        throw new Exception('Failed to rename item.');
                    }
                } else {
                    throw new Exception('Invalid old or new name, or item not found.');
                }
                break;
            case 'delete_item':
                 $name = $_POST['name'];
                 $item_path = getSafePath_Dialog($user_dir, $path . '/' . $name);
                 if ($item_path && file_exists($item_path)) {
                     if (is_dir($item_path)) {
                         if(deleteDirectoryRecursively_Dialog($item_path)) {
                             echo json_encode(['success' => true]);
                         } else {
                             throw new Exception('Failed to delete directory.');
                         }
                     } else {
                         if (unlink($item_path)) {
                            echo json_encode(['success' => true]);
                         } else {
                             throw new Exception('Failed to delete file.');
                         }
                     }
                 } else {
                     throw new Exception('Item not found.');
                 }
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ファイルを開く</title>
    <style>
        body, html {
            margin: 0; padding: 0; font-family: 'MS UI Gothic', sans-serif;
            background: #f0f0f0; font-size: 13px;
        }
        .dialog-container { display: flex; flex-direction: column; height: 100vh; }
        .header, .footer { flex-shrink: 0; padding: 12px; background: #f0f0f0; }
        .main-content {
            flex-grow: 1; border: 1px solid #999;
            background: white; overflow-y: auto;
        }
        .file-table { width: 100%; border-collapse: collapse; }
        .file-table th { background: #f0f0f0; text-align: left; padding: 4px; border-bottom: 1px solid #ccc; }
        .file-table td { padding: 4px; border-bottom: 1px solid #eee; cursor: default; }
        .file-table tr.selected td { background-color: #0078d7; color: white; }
        #path-input { width: 100%; margin-bottom: 8px; }
        .footer { border-top: 1px solid #ccc; display: flex; justify-content: flex-end; align-items: center; gap: 8px; }
        .footer label { white-space: nowrap; }
        .footer input[type="text"] { flex-grow: 1; }
        .footer .buttons { display: flex; gap: 8px; }
        .context-menu {
            position: fixed; z-index: 1000; background: #f0f0f0;
            border: 1px solid #999; min-width: 180px; padding: 2px;
            box-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }
        .context-menu-item { padding: 4px 12px; cursor: default; }
        .context-menu-item:hover { background: #0078d7; color: white; }
        .submenu { display: none; position: absolute; left: 100%; top: -3px; }
        .context-menu-item.has-submenu::after { content: '▶'; float: right; }
        .context-menu-item:hover > .submenu { display: block; }
    </style>
</head>
<body>
<div class="dialog-container">
    <div class="header">
        <input type="text" id="path-input" readonly>
    </div>
    <div class="main-content" id="main-content">
        <table class="file-table">
            <thead><tr><th>名前</th><th>更新日時</th><th>サイズ</th></tr></thead>
            <tbody id="file-list"></tbody>
        </table>
    </div>
    <div class="footer">
        <label for="filename-input">ファイル名:</label>
        <input type="text" id="filename-input">
        <label for="encoding-select" id="encoding-label">文字コード:</label>
        <select id="encoding-select">
            <option value="UTF-8">UTF-8</option>
            <option value="Shift_JIS">Shift-JIS</option>
        </select>
        <div class="buttons">
            <button id="action-btn" type="button">開く</button>
            <button id="cancel-btn" type="button">キャンセル</button>
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
    const mainContent = getEl('main-content');
    const encodingSelect = getEl('encoding-select');

    let currentDir = '/';
    let contextMenu = null;
    const params = new URLSearchParams(window.location.search);
    const sourceWindowId = params.get('source');
    const mode = params.get('mode') || 'open';
    const initialPath = params.get('path');

    document.title = mode === 'open' ? 'ファイルを開く' : '名前を付けて保存';
    actionBtn.textContent = mode === 'open' ? '開く' : '保存';
    if (mode === 'save' && initialPath) {
        filenameInput.value = initialPath.split('/').pop();
        currentDir = '/' + initialPath.split('/').slice(0, -1).join('/');
    }
    
    const api = async (action, data = {}) => {
        const formData = new FormData();
        formData.append('path', currentDir);
        for (const key in data) {
            formData.append(key, data[key]);
        }
        const response = await fetch(`?action=${action}`, { method: 'POST', body: formData });
        if (!response.ok) {
            const err = await response.json().catch(() => ({ message: 'サーバーとの通信に失敗しました。' }));
            throw new Error(err.message || `サーバーエラー: ${response.status}`);
        }
        return response.json();
    };

    const renderFiles = async (dir) => {
        currentDir = dir;
        pathInput.value = dir;
        fileListBody.innerHTML = '<tr><td colspan="3">読み込み中...</td></tr>';
        const result = await api('list_files');
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
        if (!row || row.querySelector('input')) return;
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

    const sendResponseAndClose = (data = {}) => {
        if (sourceWindowId && window.parent) {
             window.parent.postMessage({ type: 'fileDialogResponse', ...data, sourceWindowId }, '*');
        }
    };

    actionBtn.addEventListener('click', () => {
        const filename = filenameInput.value.trim();
        if (mode === 'open' && !Array.from(fileListBody.rows).some(r => r.classList.contains('selected') && r.dataset.type === 'file')) {
            alert('ファイルを選択してください。'); return;
        }
        if (!filename) { alert('ファイル名を入力してください。'); return; }

        const normalizedDir = currentDir.endsWith('/') ? currentDir.slice(0, -1) : currentDir;
        const fullPath = (normalizedDir === '' || normalizedDir === '/') ? '/' + filename : normalizedDir + '/' + filename;
        sendResponseAndClose({ filePath: fullPath, encoding: encodingSelect.value, mode: mode });
    });
    
    cancelBtn.addEventListener('click', () => {
         sendResponseAndClose({ filePath: null });
    });

    const hideContextMenu = () => {
        if (contextMenu) {
            contextMenu.remove();
            contextMenu = null;
        }
    };

    const initiateRename = (row) => {
        const oldName = row.dataset.name;
        const nameCell = row.cells[0];
        nameCell.innerHTML = `<div class="item-name-container"><input type="text" value="${oldName}"></div>`;
        const input = nameCell.querySelector('input');
        input.focus();
        input.select();

        const finishRename = async () => {
            const newName = input.value.trim();
            if (newName && newName !== oldName) {
                try {
                    const result = await api('rename_item', { old_name: oldName, new_name: newName });
                    if (!result.success) throw new Error(result.message);
                } catch (e) {
                    alert(`エラー: ${e.message}`);
                }
            }
            renderFiles(currentDir);
        };
        input.addEventListener('blur', finishRename);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') input.blur(); });
    };

    const positionMenu = (menu, x, y, parentElement = null) => {
        const menuRect = menu.getBoundingClientRect();
        let newX = x, newY = y;
        if (parentElement) {
            const parentRect = parentElement.getBoundingClientRect();
            newX = parentRect.right;
            if (newX + menuRect.width > window.innerWidth) newX = parentRect.left - menuRect.width;
            newY = parentRect.top;
        } else {
             if (newX + menuRect.width > window.innerWidth) newX = window.innerWidth - menuRect.width - 5;
        }
        if (newY + menuRect.height > window.innerHeight) newY = window.innerHeight - menuRect.height - 5;
        if (newX < 0) newX = 5; if (newY < 0) newY = 5;
        menu.style.left = `${newX}px`; menu.style.top = `${newY}px`;
    };

    mainContent.addEventListener('contextmenu', e => {
        e.preventDefault();
        hideContextMenu();
        
        const targetRow = e.target.closest('tr');
        document.querySelectorAll('.file-table tr.selected').forEach(r => r.classList.remove('selected'));
        if (targetRow) {
            targetRow.classList.add('selected');
            if (targetRow.dataset.type === 'file') filenameInput.value = targetRow.dataset.name;
        }
        
        contextMenu = document.createElement('div');
        contextMenu.className = 'context-menu';

        if(targetRow && targetRow.dataset.name !== '..') {
             contextMenu.innerHTML = `<div class="context-menu-item" data-action="rename">名前の変更</div><div class="context-menu-item" data-action="delete">削除</div>`;
        } else {
            contextMenu.innerHTML = `<div class="context-menu-item has-submenu" data-action="create"><span>新規作成</span><div class="submenu"><div class="context-menu-item" data-action="create_folder">フォルダー</div><div class="context-menu-item" data-action="create_file">テキストドキュメント</div></div></div>`;
        }
        document.body.appendChild(contextMenu);
        positionMenu(contextMenu, e.clientX, e.clientY);
        
        contextMenu.querySelectorAll('.has-submenu').forEach(item => {
            item.addEventListener('mouseenter', () => { const submenu = item.querySelector('.submenu'); if (submenu) positionMenu(submenu, 0, 0, item); });
        });
        
        contextMenu.addEventListener('click', async e => {
            const item = e.target.closest('.context-menu-item');
            if (!item || item.classList.contains('has-submenu')) return;
            const action = item.dataset.action;
            hideContextMenu();
            try {
                switch(action) {
                    case 'rename': initiateRename(targetRow); break;
                    case 'delete':
                        if (confirm(`'${targetRow.dataset.name}' を削除しますか？`)) {
                            const result = await api('delete_item', { name: targetRow.dataset.name });
                            if (!result.success) throw new Error(result.message);
                            renderFiles(currentDir);
                        }
                        break;
                    case 'create_folder':
                    case 'create_file':
                        const result = await api(action);
                        if (!result.success) throw new Error(result.message);
                        await renderFiles(currentDir);
                        const newRow = Array.from(fileListBody.rows).find(r => r.dataset.name === result.name);
                        if (newRow) { newRow.classList.add('selected'); initiateRename(newRow); }
                        break;
                }
            } catch (err) { alert(`エラー: ${err.message}`); }
        });
    });
    document.addEventListener('click', (e) => {
        if (contextMenu && !contextMenu.contains(e.target)) hideContextMenu();
        if (!mainContent.contains(e.target)) document.querySelectorAll('.file-table tr.selected').forEach(r => r.classList.remove('selected'));
    });
    renderFiles(initialPath ? ('/' + initialPath.split('/').slice(0, -1).join('/')) : '/');
});
</script>
</body>
</html>