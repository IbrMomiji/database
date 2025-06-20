<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('FILEDIALOG_USER_BASE_DIR', __DIR__ . '/../user');
define('SETTINGS_DIR', '.settings');

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    die('Authentication required.');
}
$username = $_SESSION['username'];
$user_dir = FILEDIALOG_USER_BASE_DIR . '/' . $username;

function getSafePath_Dialog($baseDir, $path) {
    $path = str_replace('\\', '/', $path);
    $path = '/' . ltrim($path, '/');
    $realBaseDir = realpath($baseDir);
    
    // Prevent directory traversal
    if (strpos($path, '..') !== false) {
        return false;
    }

    $fullPath = $realBaseDir . $path;
    
    // Normalize the path to prevent issues
    $finalPath = realpath($fullPath);
    if ($finalPath === false) {
        // If path doesn't exist, check parent
        $parent = dirname($fullPath);
        if(!is_dir($parent)) return false;
        $finalPath = realpath($parent) . '/' . basename($fullPath);
    }

    if (strpos($finalPath, $realBaseDir) !== 0) {
        return false;
    }
    return rtrim($finalPath, '/');
}

function deleteDirectoryRecursively_Dialog($dir) {
    if (!is_dir($dir)) return false;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $fileinfo) {
        $path = $fileinfo->getRealPath();
        if ($fileinfo->isDir()) { @rmdir($path); }
        else { @unlink($path); }
    }
    return @rmdir($dir);
}


if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $path = $_POST['path'] ?? '/';
    
    try {
        $safe_dir_path = getSafePath_Dialog($user_dir, $path);
        if ($safe_dir_path === false) throw new Exception('Invalid directory path.');

        switch ($action) {
            case 'list_files':
                if (!is_dir($safe_dir_path)) {
                    echo json_encode(['success' => false, 'message' => 'Directory not found.']);
                    exit;
                }
                
                $items = scandir($safe_dir_path);
                $files = []; $dirs = [];
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $item_path = $safe_dir_path . '/' . $item;
                    $is_dir = is_dir($item_path);
                    $file_info = [
                        'name' => $item,
                        'is_dir' => $is_dir,
                        'size' => $is_dir ? '' : filesize($item_path),
                        'modified' => date('Y/m/d H:i', filemtime($item_path))
                    ];
                    if ($is_dir) $dirs[] = $file_info; else $files[] = $file_info;
                }
                usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
                usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
                
                echo json_encode(['success' => true, 'items' => array_merge($dirs, $files)]);
                break;

            case 'create_folder':
                $folderName = '新規フォルダー';
                $new_path = $safe_dir_path . '/' . $folderName;
                $counter = 2;
                while(file_exists($new_path)) {
                    $new_path = $safe_dir_path . '/' . $folderName . ' (' . $counter . ')';
                    $counter++;
                }
                if (!mkdir($new_path, 0775, true)) throw new Exception('フォルダの作成に失敗しました。');
                echo json_encode(['success' => true, 'name' => basename($new_path)]);
                break;

            case 'create_file':
                $fileName = '新規ファイル.txt';
                $new_path = $safe_dir_path . '/' . $fileName;
                 $counter = 2;
                while(file_exists($new_path)) {
                    $new_path = $safe_dir_path . '/新規ファイル (' . $counter . ').txt';
                    $counter++;
                }
                if (file_put_contents($new_path, '') === false) throw new Exception('ファイルの作成に失敗しました。');
                echo json_encode(['success' => true, 'name' => basename($new_path)]);
                break;

            case 'rename_item':
                $old_name = $_POST['old_name'] ?? '';
                $new_name = $_POST['new_name'] ?? '';
                if(empty($old_name) || empty($new_name)) throw new Exception('名前が指定されていません。');

                $old_path_full = getSafePath_Dialog($user_dir, $path . '/' . $old_name);
                if(basename($old_path_full) === SETTINGS_DIR) throw new Exception('システムフォルダの名前は変更できません。');
                $new_path_full = getSafePath_Dialog($user_dir, $path . '/' . $new_name);

                if(!$old_path_full || !file_exists($old_path_full)) throw new Exception('元のアイテムが見つかりません。');
                if(file_exists($new_path_full)) throw new Exception('同じ名前のアイテムが既に存在します。');
                if(!rename($old_path_full, $new_path_full)) throw new Exception('名前の変更に失敗しました。');
                echo json_encode(['success' => true]);
                break;

            case 'delete_item':
                $item_name = $_POST['name'] ?? '';
                if(empty($item_name)) throw new Exception('アイテムが指定されていません。');
                
                $item_path_full = getSafePath_Dialog($user_dir, $path . '/' . $item_name);
                 if(basename($item_path_full) === SETTINGS_DIR) throw new Exception('システムフォルダは削除できません。');
                if(!$item_path_full || !file_exists($item_path_full)) throw new Exception('アイテムが見つかりません。');

                if (is_dir($item_path_full)) {
                    if(!deleteDirectoryRecursively_Dialog($item_path_full)) throw new Exception("フォルダ '{$item_name}' の削除に失敗しました。");
                } else {
                    if(!unlink($item_path_full)) throw new Exception("ファイル '{$item_name}' の削除に失敗しました。");
                }
                echo json_encode(['success' => true]);
                break;
        }
    } catch (Exception $e) {
        http_response_code(500);
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
    :root { 
        --bg-main: #FFFFFF; 
        --bg-secondary: #F0F0F0; 
        --text-main: #000000; 
        --border-color: #A0A0A0; 
        --selection-bg: #0078D7; 
        --selection-text: #FFFFFF;
        --button-bg: #E1E1E1;
        --button-border: #ADADAD;
        --button-hover-bg: #E5F1FB;
        --button-hover-border: #0078D7;
    }
    html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; font-family: 'Yu Gothic UI', 'Segoe UI', Meiryo, system-ui, sans-serif; font-size: 13px; background: var(--bg-secondary); }
    .dialog-container { display: flex; flex-direction: column; height: 100%; }
    .header { padding: 8px; border-bottom: 1px solid var(--border-color); display: flex; gap: 8px; align-items: center; flex-shrink: 0; }
    .header input { flex-grow: 1; padding: 4px; }
    .main-content { flex-grow: 1; background: var(--bg-main); border: 1px inset; overflow-y: auto; }
    .file-table { width: 100%; border-collapse: collapse; }
    .file-table th { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); position: sticky; top: 0; user-select: none; }
    .file-table td { padding: 4px 8px; white-space: nowrap; cursor: default; }
    .file-table tr:hover { background: #EAF2FB; }
    .file-table tr.selected { background: var(--selection-bg); color: var(--selection-text); }
    .item-name-container input { width: 95%; border: 1px solid var(--selection-bg); outline: none; font: inherit; }

    .footer { padding: 12px; display: grid; grid-template-columns: 80px 1fr auto; grid-template-rows: auto auto; gap: 8px; align-items: center; flex-shrink: 0; }
    .footer label { text-align: right; }
    .footer #filename-input { grid-column: 2 / span 2; }
    .footer #encoding-label { grid-row: 2; grid-column: 1; }
    .footer #encoding-select { grid-row: 2; grid-column: 2; }
    .footer .buttons { grid-row: 2; grid-column: 3; text-align: right; }
    .footer .buttons button {
        min-width: 80px;
        height: 28px;
        padding: 4px 12px;
        font-size: 13px;
        background: var(--button-bg);
        border: 1px solid var(--button-border);
        border-radius: 3px;
        cursor: pointer;
        margin-left: 8px;
    }
    .footer .buttons button:hover {
        border-color: var(--button-hover-border);
        background: var(--button-hover-bg);
    }
    
    .context-menu { position: fixed; z-index: 1000; background: #F0F0F0; border: 1px solid #A0A0A0; box-shadow: 2px 2px 5px rgba(0,0,0,0.2); min-width: 200px; padding: 4px; border-radius: 4px;}
    .context-menu-item { padding: 6px 12px; cursor: default; position: relative;}
    .context-menu-item:hover { background: var(--menu-highlight-bg); }
    .context-menu-separator { height: 1px; background: var(--border-color); margin: 4px 1px; }
    .has-submenu::after { content: '▶'; position: absolute; right: 8px; }
    .submenu { display: none; position: fixed; background: #F0F0F0; border: 1px solid #A0A0A0; box-shadow: 2px 2px 5px rgba(0,0,0,0.2); min-width: 150px; padding: 2px; border-radius: 4px; }
    .context-menu-item:hover > .submenu { display: block; }
</style>
</head>
<body>
<div class="dialog-container">
    <div class="header">
        <label>現在のパス:</label>
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
        const fullPath = (normalizedDir === '' ? '' : normalizedDir) + '/' + filename;
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
        let newX = x;
        let newY = y;
        
        if (parentElement) {
            const parentRect = parentElement.getBoundingClientRect();
            newX = parentRect.right;
            if (newX + menuRect.width > window.innerWidth) {
                newX = parentRect.left - menuRect.width;
            }
            newY = parentRect.top;
        } else {
             if (newX + menuRect.width > window.innerWidth) {
                newX = window.innerWidth - menuRect.width - 5;
            }
        }
        if (newY + menuRect.height > window.innerHeight) {
            newY = window.innerHeight - menuRect.height - 5;
        }
        
        if (newX < 0) newX = 5;
        if (newY < 0) newY = 5;
        
        menu.style.left = `${newX}px`;
        menu.style.top = `${newY}px`;
    };

    mainContent.addEventListener('contextmenu', e => {
        e.preventDefault();
        hideContextMenu();
        
        const targetRow = e.target.closest('tr');
        contextMenu = document.createElement('div');
        contextMenu.className = 'context-menu';

        if(targetRow) {
             if (targetRow.dataset.name === '.settings') return;
             contextMenu.innerHTML = `
                <div class="context-menu-item" data-action="rename">名前の変更</div>
                <div class="context-menu-item" data-action="delete">削除</div>`;
        } else {
            contextMenu.innerHTML = `
                <div class="context-menu-item has-submenu" data-action="create">
                    <span>新規作成</span>
                    <div class="submenu">
                         <div class="context-menu-item" data-action="create_folder">フォルダー</div>
                         <div class="context-menu-item" data-action="create_file">テキストドキュメント</div>
                    </div>
                </div>`;
        }

        document.body.appendChild(contextMenu);
        positionMenu(contextMenu, e.clientX, e.clientY);
        
        contextMenu.querySelectorAll('.has-submenu').forEach(item => {
            item.addEventListener('mouseenter', () => {
                const submenu = item.querySelector('.submenu');
                if (submenu) positionMenu(submenu, 0, 0, item);
            });
        });
        
        contextMenu.addEventListener('click', async e => {
            const item = e.target.closest('.context-menu-item');
            if (!item || item.classList.contains('has-submenu')) return;

            const action = item.dataset.action;
            hideContextMenu();
            try {
                switch(action) {
                    case 'rename':
                        initiateRename(targetRow);
                        break;
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
                        if (newRow) {
                            newRow.classList.add('selected');
                            initiateRename(newRow);
                        }
                        break;
                }
            } catch (err) {
                alert(`エラー: ${err.message}`);
            }
        });
    });

    document.addEventListener('click', hideContextMenu);
    
    renderFiles(initialPath ? ('/' + initialPath.split('/').slice(0, -1).join('/')) : '/');
});
</script>
</body>
</html>
