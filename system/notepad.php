<?php
$fileToOpen = $_GET['file'] ?? null;
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('NOTEPAD_USER_BASE_DIR', __DIR__ . '/../user');

// 認証チェック
if (!isset($_SESSION['username'])) {
    http_response_code(403); die('Authentication required.');
}
$username = $_SESSION['username'];
$user_dir = NOTEPAD_USER_BASE_DIR . '/' . $username;
if (!is_dir($user_dir)) {
    mkdir($user_dir, 0775, true);
}

function getSafePath_Notepad($baseDir, $path) {
    $realBaseDir = realpath($baseDir);
    if ($realBaseDir === false) {
        return false;
    }

    $userPath = str_replace('\\\\', '/', $path);

    if (strpos($userPath, '..') !== false || preg_match('/[:*?"<>|]/', $userPath)) {
        return false;
    }

    $fullPath = $realBaseDir . DIRECTORY_SEPARATOR . ltrim($userPath, '/');
    
    $parts = explode(DIRECTORY_SEPARATOR, $fullPath);
    $absolutes = [];
    foreach ($parts as $part) {
        if ('.' == $part || '' == $part) continue;
        if ('..' == $part) {
            array_pop($absolutes);
        } else {
            $absolutes[] = $part;
        }
    }
    
    $canonicalPath = implode(DIRECTORY_SEPARATOR, $absolutes);
    if (DIRECTORY_SEPARATOR !== '\\' && substr($canonicalPath, 0, 1) !== DIRECTORY_SEPARATOR) {
         $canonicalPath = DIRECTORY_SEPARATOR . $canonicalPath;
    }
    
    if (strpos($canonicalPath, $realBaseDir) !== 0) {
        return false;
    }
    
    return $canonicalPath;
}

// アクションAPI
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $path = $_POST['path'] ?? '/';
    try {
        $safe_path = getSafePath_Notepad($user_dir, $path);
        if ($safe_path === false) { throw new Exception('Invalid file path.'); }
        switch ($action) {
            case 'get_content':
                if (!is_file($safe_path) || !is_readable($safe_path)) { 
                    throw new Exception('Cannot read file: ' . htmlspecialchars($path));
                }
                $content = @file_get_contents($safe_path);
                if ($content === false) {
                    $error = error_get_last();
                    throw new Exception('Failed to read file. ' . ($error ? $error['message'] : ''));
                }
                $encoding = mb_detect_encoding($content, mb_detect_order(), true);
                if ($encoding === false) $encoding = 'UTF-8';
                $content_utf8 = mb_convert_encoding($content, 'UTF-8', $encoding);
                echo json_encode(['success' => true, 'content' => $content_utf8, 'encoding' => $encoding]);
                break;
            case 'save_content':
                $dir_to_save = dirname($safe_path);
                if (!is_dir($dir_to_save)) {
                    if (!mkdir($dir_to_save, 0775, true)) {
                        $error = error_get_last();
                        throw new Exception('Failed to create destination directory. ' . ($error ? $error['message'] : ''));
                    }
                }
                $content = $_POST['content'] ?? '';
                $encoding = $_POST['encoding'] ?? 'UTF-8';
                $content_encoded = mb_convert_encoding($content, $encoding, 'UTF-8');
                if (@file_put_contents($safe_path, $content_encoded) === false) {
                    $error = error_get_last();
                    throw new Exception('Failed to save file. ' . ($error ? $error['message'] : ''));
                }
                echo json_encode(['success' => true, 'message' => 'File saved.']);
                break;
            default:
                throw new Exception('Unknown action: ' . htmlspecialchars($action));
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'trace' => getenv('APP_DEBUG') ? $e->getTraceAsString() : null
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Notepad</title>
    <style>
        :root {
            --bg-main: #FFFFFF; --bg-menu: #F0F0F0; --text-main: #000000;
            --border-color: #A0A0A0; --menu-highlight-bg: #D6E8F9; --menu-highlight-border: #92C0E0;
            --button-border: #C0C0C0; --dialog-bg: #F0F0F0;
        }
        html, body {
            margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden;
            font-family: 'MS UI Gothic', 'Segoe UI', Meiryo, sans-serif; font-size: 9pt;
            background-color: var(--bg-main);
            color: var(--text-main);
        }
        .notepad-container { 
            display: flex; 
            flex-direction: column; 
            height: 100%;
        }
        .menu-bar { 
            background: var(--bg-menu); padding: 2px; display: flex; flex-shrink: 0;
            user-select: none; border-bottom: 1px solid var(--border-color); 
        }
        .menu-item { padding: 4px 8px; cursor: default; position: relative; }
        .menu-item:hover, .menu-item.open { background: var(--menu-highlight-bg); border: 1px solid var(--menu-highlight-border); padding: 3px 7px; }
        .dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: var(--bg-menu); border: 1px solid var(--border-color); box-shadow: 2px 2px 5px rgba(0,0,0,0.2); min-width: 120px; z-index: 10;}
        .menu-item.open .dropdown-menu { display: block; }
        .dropdown-item { padding: 4px 20px; display: flex; justify-content: space-between; align-items: center; cursor: default; }
        .dropdown-item:hover:not(.disabled) { background: var(--menu-highlight-bg); }
        .dropdown-item.disabled { color: #A0A0A0; }
        .dropdown-item.checked::before { content: '\u2714'; margin-left: -16px; position: absolute; }
        .dropdown-separator { height: 1px; background: var(--border-color); margin: 4px 1px; }

        .textarea-container { 
            flex-grow: 1;
            position: relative; 
        }
        .main-textarea { 
            box-sizing: border-box; width: 100%; height: 100%; 
            border: none; outline: none; resize: none; 
            font-family: 'MS Gothic', monospace; font-size: 10.5pt; line-height: 1.3; 
            padding: 2px 4px; white-space: pre; word-wrap: normal; 
            overflow: auto; 
        }
        .main-textarea.wrap { white-space: pre-wrap; word-wrap: break-word; }

        .status-bar { 
            background: var(--bg-menu); padding: 2px 10px; display: flex; 
            justify-content: flex-end; align-items: center; 
            border-top: 1px solid var(--border-color); 
            flex-shrink: 0;
            gap: 20px; 
        }
        .status-item { padding: 2px 8px; border-left: 1px solid var(--button-border); border-top: 1px solid var(--button-border); }
        
        .dialog-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.1); z-index: 200; display: none; align-items: center; justify-content: center; }
        .font-dialog { background: var(--dialog-bg); padding: 12px; border: 1px solid var(--border-color); box-shadow: 2px 2px 8px rgba(0,0,0,0.3); width: 400px; }
        .font-dialog-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
        .font-dialog-grid label { display: block; margin-bottom: 2px; }
        .font-dialog-grid input, .font-dialog-grid select { width: 100%; box-sizing: border-box; }
        .font-preview { border: 1px inset; padding: 12px; margin-top: 12px; height: 60px; background: var(--bg-main); overflow: hidden; }
        .font-dialog-buttons { text-align: right; margin-top: 12px; }
    </style>
</head>
<body>
    <div class="notepad-container">
        <div class="menu-bar">
            <div class="menu-item" id="menu-file">ファイル(F)
                <div class="dropdown-menu">
                    <div class="dropdown-item" data-action="new">新規(N)</div>
                    <div class="dropdown-item" data-action="open">開く(O)...</div>
                    <div class="dropdown-item" data-action="save">上書き保存(S)</div>
                    <div class="dropdown-item" data-action="save-as">名前を付けて保存(A)...</div>
                    <div class="dropdown-separator"></div>
                    <div class="dropdown-item" data-action="exit">メモ帳の終了(X)</div>
                </div>
            </div>
            <div class="menu-item" id="menu-edit">編集(E)
                 <div class="dropdown-menu">
                    <div class="dropdown-item disabled" data-action="undo">元に戻す(U)</div>
                    <div class="dropdown-separator"></div>
                    <div class="dropdown-item disabled" data-action="cut">切り取り(T)</div>
                    <div class="dropdown-item disabled" data-action="copy">コピー(C)</div>
                    <div class="dropdown-item" data-action="paste">貼り付け(P)</div>
                    <div class="dropdown-item disabled" data-action="delete">削除(L)</div>
                    <div class="dropdown-separator"></div>
                    <div class="dropdown-item" data-action="select-all">すべて選択(A)</div>
                </div>
            </div>
            <div class="menu-item" id="menu-format">書式(O)
                 <div class="dropdown-menu">
                    <div class="dropdown-item" data-action="word-wrap">右端で折り返す(W)</div>
                    <div class="dropdown-item" data-action="font">フォント(F)...</div>
                </div>
            </div>
        </div>
        
        <div class="textarea-container">
            <textarea class="main-textarea" spellcheck="false"></textarea>
        </div>
    
        <div class="status-bar">
            <div class="status-item" id="status-pos">行 1, 列 1</div>
            <div class="status-item" id="status-zoom">100%</div>
            <div class="status-item" id="status-encoding">UTF-8</div>
        </div>
    </div>
    
    <div class="dialog-overlay" id="font-dialog">
        <div class="font-dialog">
            <div class="font-dialog-grid">
                <div><label for="font-family">フォント:</label><input type="text" id="font-family-input" list="font-family-list"><datalist id="font-family-list"><option value="MS Gothic"></option><option value="Meiryo"></option><option value="Consolas"></option><option value="MS UI Gothic"></option><option value="Segoe UI"></option></datalist></div>
                <div><label for="font-style">スタイル:</label><select id="font-style-select"><option value="normal">標準</option><option value="italic">斜体</option><option value="bold">太字</option><option value="bold italic">太字斜体</option></select></div>
                <div><label for="font-size">サイズ:</label><input type="number" id="font-size-input" value="10.5" step="0.5"></div>
            </div>
            <fieldset class="font-preview"><legend>プレビュー</legend><div id="font-preview-text">AaBbYyZz</div></fieldset>
            <div class="font-dialog-buttons">
                <button id="font-ok-btn">OK</button>
                <button id="font-cancel-btn">キャンセル</button>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const getEl = id => document.getElementById(id);
        const textArea = document.querySelector('.main-textarea');
        const statusPos = getEl('status-pos');
        const statusEncoding = getEl('status-encoding');
        const menuItems = document.querySelectorAll('.menu-item');
    
        let isDirty = false;
        let currentFilePath = null;
        let currentEncoding = 'UTF-8';
        let myWindowId = window.name;
    
        // fetch時の詳細なエラーハンドリング付きAPI
        const api = async (action, data = {}) => {
            const formData = new FormData();
            for (const key in data) formData.append(key, data[key]);
            let response;
            try {
                response = await fetch(`?action=${action}`, { method: 'POST', body: formData });
            } catch (netErr) {
                throw new Error('サーバーへの接続に失敗しました。');
            }
            let result;
            try {
                result = await response.json();
            } catch (parseErr) {
                throw new Error('サーバーからの応答が不正です。');
            }
            if (!response.ok || !result.success) {
                let msg = (result && result.message) ? result.message : '不明なエラー';
                throw new Error(msg);
            }
            return result;
        };

        const updateTitle = () => {
            const dirtyMarker = isDirty ? '*' : '';
            const fileName = currentFilePath ? currentFilePath.split(/[\\/]/).pop() : '無題';
            const newTitle = `${dirtyMarker}${fileName} - メモ帳`;
            try {
                const parentWindowEl = window.parent.document.querySelector(`#${myWindowId.replace('-iframe', '')}`);
                if (parentWindowEl) parentWindowEl.querySelector('.window-title').textContent = newTitle;
            } catch (e) {}
        };

        const updateStatus = () => {
            const text = textArea.value;
            const cursorPos = textArea.selectionStart;
            let line = (text.substring(0, cursorPos).match(/\n/g) || []).length + 1;
            let col = cursorPos - text.lastIndexOf('\n', cursorPos - 1);
            statusPos.textContent = `行 ${line}, 列 ${col}`;
        };
        
        const updateEditMenu = () => {
            const hasSelection = textArea.selectionStart !== textArea.selectionEnd;
            document.querySelector('[data-action="cut"]').classList.toggle('disabled', !hasSelection);
            document.querySelector('[data-action="copy"]').classList.toggle('disabled', !hasSelection);
            document.querySelector('[data-action="delete"]').classList.toggle('disabled', !hasSelection);
        };
    
        const resetDocument = () => {
            textArea.value = '';
            isDirty = false;
            currentFilePath = null;
            currentEncoding = 'UTF-8';
            statusEncoding.textContent = currentEncoding;
            updateTitle();
            updateStatus();
        };
    
        const confirmAndSaveIfNeeded = (callback) => {
            if (!isDirty) {
                if(callback) callback();
                return;
            }
            const result = confirm(`'${currentFilePath || '無題'}' への変更内容を保存しますか？`);
            if (result === true) {
                handleSave(callback);
            } else if (result === false) {
                if(callback) callback();
            }
        };
        
        const handleSave = (callback) => {
            if (currentFilePath) {
                saveFile(currentFilePath, callback);
            } else {
                openFileDialog('save');
            }
        };
    
        const openFileDialog = (mode) => {
            try {
                window.parent.postMessage({
                    type: 'requestFileDialog',
                    sourceWindowId: myWindowId,
                    mode: mode,
                    currentPath: currentFilePath,
                }, '*');
            } catch (e) {
                alert('ファイルダイアログを開けませんでした。');
            }
        };
        
        const loadFile = async (path) => {
            try {
                const result = await api('get_content', { path });
                if (result.success) {
                    textArea.value = result.content;
                    currentFilePath = path;
                    currentEncoding = result.encoding;
                    statusEncoding.textContent = currentEncoding;
                    isDirty = false;
                    updateTitle();
                    updateStatus();
                }
            } catch(e) { alert(`エラー: ${e.message}`); }
        };
        
        const saveFile = async (path, callback) => {
            try {
                const result = await api('save_content', { path, content: textArea.value, encoding: currentEncoding });
                if (result.success) {
                    currentFilePath = path;
                    isDirty = false;
                    updateTitle();
                    if (callback) callback();
                }
            } catch(e) { alert(`エラー: ${e.message}`); }
        };
        
        textArea.addEventListener('input', () => { if (!isDirty) { isDirty = true; updateTitle(); } });
        textArea.addEventListener('keyup', updateStatus);
        textArea.addEventListener('mouseup', updateStatus);
        textArea.addEventListener('selectionchange', updateEditMenu);
    
        menuItems.forEach(item => {
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                if (item.classList.contains('open')) {
                    item.classList.remove('open');
                } else {
                    menuItems.forEach(i => i.classList.remove('open'));
                    item.classList.add('open');
                }
            });
        });
        
        document.addEventListener('click', () => menuItems.forEach(i => i.classList.remove('open')));
    
        document.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (item.classList.contains('disabled')) return;
                const action = item.dataset.action;
                switch(action) {
                    case 'new': confirmAndSaveIfNeeded(resetDocument); break;
                    case 'open': confirmAndSaveIfNeeded(() => openFileDialog('open')); break;
                    case 'save': handleSave(); break;
                    case 'save-as': openFileDialog('save'); break;
                    case 'exit': confirmAndSaveIfNeeded(() => { try { window.parent.postMessage({type: 'closeChildWindow', windowId: myWindowId}, '*'); } catch(e){} }); break;
                    case 'cut': document.execCommand('cut'); break;
                    case 'copy': document.execCommand('copy'); break;
                    case 'paste': navigator.clipboard.readText().then(text => document.execCommand('insertText', false, text)); break;
                    case 'delete': document.execCommand('delete'); break;
                    case 'select-all': textArea.select(); break;
                    case 'word-wrap': item.classList.toggle('checked'); textArea.classList.toggle('wrap'); break;
                    case 'font': showFontDialog(); break;
                }
            });
        });
    
        const fontDialog = getEl('font-dialog');
        const fontPreview = getEl('font-preview-text');
        const fontFamilyInput = getEl('font-family-input');
        const fontStyleSelect = getEl('font-style-select');
        const fontSizeInput = getEl('font-size-input');
    
        const updateFontPreview = () => {
            const style = fontStyleSelect.value.split(' ');
            fontPreview.style.fontFamily = fontFamilyInput.value;
            fontPreview.style.fontStyle = style.includes('italic') ? 'italic' : 'normal';
            fontPreview.style.fontWeight = style.includes('bold') ? 'bold' : 'normal';
            fontPreview.style.fontSize = `${fontSizeInput.value}pt`;
        };
    
        const showFontDialog = () => {
            const computedStyle = getComputedStyle(textArea);
            fontFamilyInput.value = computedStyle.fontFamily.split(',')[0].replace(/"/g, '');
            fontSizeInput.value = parseFloat(computedStyle.fontSize);
            fontDialog.style.display = 'flex';
            updateFontPreview();
        };
        
        [fontFamilyInput, fontStyleSelect, fontSizeInput].forEach(el => el.addEventListener('input', updateFontPreview));
        
        getEl('font-ok-btn').addEventListener('click', () => {
            const style = fontStyleSelect.value.split(' ');
            textArea.style.fontFamily = fontFamilyInput.value;
            textArea.style.fontStyle = style.includes('italic') ? 'italic' : 'normal';
            textArea.style.fontWeight = style.includes('bold') ? 'bold' : 'normal';
            textArea.style.fontSize = `${fontSizeInput.value}pt`;
            fontDialog.style.display = 'none';
        });
    
        getEl('font-cancel-btn').addEventListener('click', () => {
            fontDialog.style.display = 'none';
        });
        
        window.addEventListener('message', (event) => {
            const { type, filePath, mode, sourceWindowId } = event.data;
            if (type === 'fileDialogResponse' && sourceWindowId === myWindowId) {
                if (filePath) {
                    if (mode === 'open') {
                        loadFile(filePath);
                    } else if (mode === 'save') {
                        saveFile(filePath);
                    }
                }
            }
        });
    
        const fileToOpenOnInit = <?php echo json_encode($fileToOpen); ?>;
        if(fileToOpenOnInit) {
            loadFile(fileToOpenOnInit);
        } else {
            resetDocument();
        }
        updateEditMenu();
        updateStatus();
    });
    </script>
</body>
</html>