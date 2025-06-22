<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$fileToOpen = $_GET['file'] ?? null;
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('NOTEPAD_USER_BASE_DIR', __DIR__ . '/../../user');
define('SETTINGS_DIR', '.settings');
define('NOTEPAD_SETTINGS_FILE', SETTINGS_DIR . '/.notepad.json');

if (!isset($_SESSION['user_id'], $_SESSION['user_uuid'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}
$username = $_SESSION['username'];
$user_dir = NOTEPAD_USER_BASE_DIR . '/' . $_SESSION['user_uuid'];
if (!is_dir($user_dir)) {
    mkdir($user_dir, 0775, true);
}
$settings_path = $user_dir . '/' . SETTINGS_DIR;
if (!is_dir($settings_path)) {
    mkdir($settings_path, 0775, true);
}

function getSafePath_Notepad($baseDir, $path) {
    $realBaseDir = realpath($baseDir);
    if ($realBaseDir === false) {
        error_log("Notepad Security Alert: Base directory '{$baseDir}' not found or inaccessible.");
        return false;
    }
    $userPath = str_replace('\\', '/', $path);
    if (strpos($userPath, "\0") !== false || strpos($userPath, '..') !== false || preg_match('/[:*?"<>|]/', $userPath)) {
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

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    try {
        if (!in_array($action, ['get_notepad_settings', 'save_notepad_settings'])) {
            if (!isset($_POST['path'])) {
                throw new InvalidArgumentException('Path parameter is missing.', 400);
            }
            $path = $_POST['path'];
            $safe_path = getSafePath_Notepad($user_dir, $path);
            if ($safe_path === false) {
                throw new InvalidArgumentException('Invalid or disallowed file path.', 400);
            }
        }

        switch ($action) {
            case 'get_content':
                if (!is_file($safe_path)) {
                    throw new RuntimeException('File not found.', 404);
                }
                if (!is_readable($safe_path)) {
                    throw new RuntimeException('Permission denied. Cannot read file.', 403);
                }
                $content = file_get_contents($safe_path);
                $encoding = mb_detect_encoding($content, mb_detect_order(), true);
                if ($encoding === false) $encoding = 'UTF-8';
                $content_utf8 = mb_convert_encoding($content, 'UTF-8', $encoding);
                echo json_encode(['success' => true, 'content' => $content_utf8, 'encoding' => $encoding]);
                break;
            case 'save_content':
                $dir_to_save = dirname($safe_path);
                if (!is_dir($dir_to_save)) {
                    if (!@mkdir($dir_to_save, 0775, true) && !is_dir($dir_to_save)) {
                        throw new RuntimeException('Failed to create destination directory. Check permissions.', 500);
                    }
                }

                if ((is_file($safe_path) && !is_writable($safe_path)) || (!is_file($safe_path) && !is_writable($dir_to_save))) {
                    throw new RuntimeException('Permission denied. Cannot write to this location.', 403);
                }

                $content = $_POST['content'] ?? '';
                $encoding = $_POST['encoding'] ?? 'UTF-8';
                $content_encoded = mb_convert_encoding($content, $encoding, 'UTF-8');
                if (file_put_contents($safe_path, $content_encoded) === false) {
                    throw new RuntimeException('Failed to save file due to a server error.', 500);
                }
                echo json_encode(['success' => true, 'message' => 'File saved.']);
                break;
            case 'get_notepad_settings':
                $settings_file_path = $user_dir . '/' . NOTEPAD_SETTINGS_FILE;
                if (file_exists($settings_file_path)) {
                    if (!is_readable($settings_file_path)) {
                        throw new RuntimeException('Cannot read settings file.', 403);
                    }
                    $settings = json_decode(file_get_contents($settings_file_path), true);
                    echo json_encode(['success' => true, 'settings' => $settings]);
                } else {
                    echo json_encode(['success' => true, 'settings' => null]);
                }
                break;
            case 'save_notepad_settings':
                if (!isset($_POST['settings'])) {
                    throw new InvalidArgumentException('Settings data is missing.', 400);
                }
                $settings = json_decode($_POST['settings'] ?? '{}', true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException('Invalid settings data. Malformed JSON.', 400);
                }
                $settings_file_path = $user_dir . '/' . NOTEPAD_SETTINGS_FILE;
                $settings_dir = dirname($settings_file_path);

                if (!is_writable($settings_dir)) {
                    throw new RuntimeException('Permission denied. Cannot write settings.', 403);
                }

                if (file_put_contents($settings_file_path, json_encode($settings, JSON_PRETTY_PRINT)) === false) {
                     throw new RuntimeException('Failed to save settings due to a server error.', 500);
                }
                echo json_encode(['success' => true, 'message' => 'Settings saved.']);
                break;
        }
    } catch (Exception $e) {
        $code = ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;

        error_log(
            sprintf(
                "Notepad API Error: [%d] %s in %s:%d (User: %s)",
                $code,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $username ?? 'N/A'
            )
        );

        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Notepad</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <style>
        :root {
            --bg-main: #FFFFFF;
            --bg-menu: #F0F0F0;
            --text-main: #000000;
            --border-color: #A0A0A0;
            --menu-highlight-bg: #D6E8F9;
            --menu-highlight-border: #92C0E0;
            --button-border: #C0C0C0;
            --dialog-bg: #F0F0F0;
        }

        @font-face {
          font-family: 'MS Gothic';
          src: local('MS Gothic'),
               local('ＭＳ ゴシック'),
               local('Osaka-mono');
        }

        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            font-family: 'MS UI Gothic', 'Segoe UI', Meiryo, sans-serif;
            font-size: 13px;
            background-color: var(--bg-main);
            color: var(--text-main);
        }

        .notepad-container {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .menu-bar {
            background: var(--bg-menu);
            padding: 2px;
            display: flex;
            flex-shrink: 0;
            user-select: none;
            border-bottom: 1px solid var(--border-color);
        }

        .menu-item {
            padding: 4px 8px;
            cursor: default;
            position: relative;
        }

        .menu-item:hover, .menu-item.open {
            background: var(--menu-highlight-bg);
            border: 1px solid var(--menu-highlight-border);
            padding: 3px 7px;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: var(--bg-menu);
            border: 1px solid var(--border-color);
            box-shadow: 2px 2px 5px rgba(0,0,0,0.2);
            min-width: 200px;
            padding: 2px;
            z-index: 100;
        }

        .menu-item.open > .dropdown-menu {
            display: block;
        }

        .dropdown-item {
            padding: 4px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: default;
            position: relative;
        }

        .dropdown-item:hover:not(.disabled) {
            background: var(--menu-highlight-bg);
        }

        .dropdown-item.disabled {
            color: #A0A0A0;
        }

        .dropdown-item.checked::before {
            content: '✔';
            margin-left: -16px;
            position: absolute;
        }

        .dropdown-separator {
            height: 1px;
            background: var(--border-color);
            margin: 4px 1px;
        }
        
        .has-submenu::after { content: '▶'; float: right; }
        .submenu { display: none; position: absolute; left: 100%; top: -3px; }
        .menu-item:hover > .dropdown-menu, .dropdown-item:hover > .dropdown-menu { display: block; }

        .textarea-container {
            flex-grow: 1;
            position: relative;
            background: var(--bg-main);
        }

        .main-textarea {
            box-sizing: border-box;
            width: 100%;
            height: 100%;
            border: none;
            outline: none;
            resize: none;
            font-family: 'MS Gothic', monospace;
            font-size: 15px;
            line-height: 1.3;
            padding: 2px 4px;
            white-space: pre;
            word-wrap: normal;
            overflow: auto;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 2;
            background: transparent;
            color: var(--text-main);
            caret-color: black;
        }

        .main-textarea.preview-mode {
            color: rgba(0,0,0,0);
            -webkit-text-fill-color: transparent;
        }

        .main-textarea.wrap {
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .main-textarea::selection {
            background: #add8e6;
        }

        .main-textarea.preview-mode::selection {
            background: rgba(173, 216, 230, 0.4);
        }
        
        #code-highlight-pre, #markdown-preview {
            box-sizing: border-box; width: 100%; height: 100%;
            margin: 0; padding: 2px 4px;
            overflow: auto;
            position: absolute; top: 0; left: 0;
            display: none;
            pointer-events: none;
            z-index: 1;
        }

        #code-highlight-pre {
            background-color: var(--bg-main);
            white-space: pre;
            word-wrap: normal;
        }

        #code-highlight-pre.wrap {
             white-space: pre-wrap;
             word-wrap: break-word;
        }

        #markdown-preview {
            background: white; color: black;
            padding: 16px;
            pointer-events: all;
        }

        .status-bar {
            background: var(--bg-menu);
            padding: 2px 10px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            border-top: 1px solid var(--border-color);
            flex-shrink: 0;
            gap: 20px;
        }

        .status-item {
            padding: 2px 8px;
            border-left: 1px solid var(--button-border);
            border-top: 1px solid var(--button-border);
        }

        .status-item:last-child {
            border-right: 1px solid var(--button-border);
        }
        
        #status-eol {
            min-width: 60px;
            text-align: center;
        }

        .dialog-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.1);
            z-index: 200;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .font-dialog {
            background: var(--dialog-bg);
            padding: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 2px 2px 8px rgba(0,0,0,0.3);
            width: 400px;
        }

        .font-dialog-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }

        .font-dialog-grid label {
            display: block;
            margin-bottom: 2px;
        }

        .font-dialog-grid input, .font-dialog-grid select {
            width: 100%;
            box-sizing: border-box;
        }

        .font-preview {
            border: 1px inset;
            padding: 12px;
            margin-top: 12px;
            height: 60px;
            background: var(--bg-main);
            overflow: hidden;
        }

        .font-dialog-buttons {
            text-align: right;
            margin-top: 12px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .font-dialog-buttons button {
            min-width: 80px;
            height: 34px;
            padding: 6px 0;
            font-size: 14px;
            background: #f8f8f8;
            color: #222;
            border: 1px solid #bbb;
            border-radius: 3px;
            cursor: pointer;
            outline: none;
            transition: background 0.15s, box-shadow 0.15s;
            box-sizing: border-box;
        }
        
        .font-dialog-buttons button:active {
            background: #e0e0e0;
        }
        
        .font-dialog-buttons button:focus {
            box-shadow: 0 0 0 2px #b5d5ff;
        }

        .font-family-container { position: relative; }
        
        .font-family-list {
            display: none;
            position: absolute;
            background: var(--bg-main);
            border: 1px solid var(--border-color);
            z-index: 300;
            max-height: 150px;
            overflow-y: auto;
            width: 100%;
            box-sizing: border-box;
        }

        .font-family-item {
            padding: 4px 8px;
            cursor: pointer;
        }
        
        .font-family-item:hover {
            background-color: var(--menu-highlight-bg);
        }
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
            <div class="menu-item" id="menu-view">表示(V)
                 <div class="dropdown-menu">
                    <div class="dropdown-item" data-action="view-markdown">Markdownプレビュー</div>
                     <div class="dropdown-item has-submenu">
                        言語
                        <div class="dropdown-menu submenu">
                            <div class="dropdown-item" data-action="view-lang" data-lang="plaintext">プレーン テキスト</div>
                            <div class="dropdown-separator"></div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="html">HTML</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="css">CSS</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="javascript">JavaScript</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="php">PHP</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="python">Python</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="java">Java</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="cpp">C++</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="csharp">C#</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="ruby">Ruby</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="go">Go</div>
                            <div class="dropdown-item" data-action="view-lang" data-lang="sql">SQL</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="textarea-container">
            <textarea class="main-textarea" spellcheck="false"></textarea>
            <pre id="code-highlight-pre" aria-hidden="true"><code id="code-highlight-output" class="language-none"></code></pre>
            <div id="markdown-preview"></div>
        </div>
        <div class="status-bar">
            <div class="status-item" id="status-pos">行 1, 列 1</div>
            <div class="status-item" id="status-zoom">100%</div>
            <div class="status-item" id="status-encoding">UTF-8</div>
            <div class="status-item" id="status-eol">CRLF</div>
        </div>
    </div>
    <div class="dialog-overlay" id="font-dialog">
        <div class="font-dialog">
            <div class="font-dialog-grid">
                <div class="font-family-container">
                    <label for="font-family">フォント:</label>
                    <input type="text" id="font-family-input" autocomplete="off">
                    <div class="font-family-list" id="font-family-list"></div>
                </div>
                <div><label for="font-style">スタイル:</label><select id="font-style-select"><option value="normal">標準</option><option value="italic">斜体</option><option value="bold">太字</option></select></div>
                <div><label for="font-size">サイズ:</label><input type="number" id="font-size-input" value="15" step="1"></div>
            </div>
            <fieldset class="font-preview"><legend>プレビュー</legend><div id="font-preview-text">AaBbYyZz あいうえお</div></fieldset>
            <div class="font-dialog-buttons">
                <button id="font-ok-btn" type="button">OK</button>
                <button id="font-cancel-btn" type="button">キャンセル</button>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const getEl = id => document.getElementById(id);
        const textArea = document.querySelector('.main-textarea');
        const statusPos = getEl('status-pos'), statusEncoding = getEl('status-encoding'), statusZoom = getEl('status-zoom'), statusEol = getEl('status-eol');
        const menuItems = document.querySelectorAll('.menu-item');
        const markdownPreview = getEl('markdown-preview');
        const codeHighlightPre = getEl('code-highlight-pre');
        const codeHighlightOutput = getEl('code-highlight-output');
        let isDirty = false;
        let currentFilePath = null;
        let currentEncoding = 'UTF-8';
        let myWindowId = window.name;
        let baseFontSize = 15;
        let currentViewMode = 'text';
        let currentLanguage = 'plaintext';
        let currentEol = 'CRLF';
        const prismLangMap = {
            plaintext: 'none',
            html: 'markup',
            css: 'css',
            javascript: 'javascript',
            php: 'php',
            python: 'python',
            java: 'java',
            cpp: 'cpp',
            csharp: 'csharp',
            ruby: 'ruby',
            go: 'go',
            sql: 'sql'
        };
        const api = async (action, data = {}) => {
            try {
                const formData = new FormData();
                for (const key in data) formData.append(key, data[key]);
                const response = await fetch(`?action=${action}`, { method: 'POST', body: formData });
                if (!response.ok) {
                    const errJson = await response.json().catch(() => null);
                    const message = errJson?.message || `サーバーとの通信に失敗しました。 (Status: ${response.status})`;
                    throw new Error(message);
                }
                return response.json();
            } catch (error) {
                console.error(`API Action '${action}' failed:`, error);
                throw error;
            }
        };
        const updateTitle = () => {
            const dirtyMarker = isDirty ? '*' : '';
            const fileName = currentFilePath ? currentFilePath.split(/[\\/]/).pop() : '無題';
            const newTitle = `${dirtyMarker}${fileName} - メモ帳`;
            try {
                window.parent.postMessage({
                    type: 'setWindowTitle',
                    title: newTitle
                }, '*');
            } catch (e) {
                console.error("Failed to post message to parent:", e);
            }
        };
        const updateStatus = () => {
            const text = textArea.value;
            const cursorPos = textArea.selectionStart;
            let line = (text.substring(0, cursorPos).match(/\n/g) || []).length + 1;
            let col = cursorPos - text.lastIndexOf('\n', cursorPos - 1);
            statusPos.textContent = `行 ${line}, 列 ${col}`;
            updateEolStatus();
        };
        const updateZoomStatus = () => {
            const currentSize = parseInt(getComputedStyle(textArea).fontSize);
            const zoom = Math.round((currentSize / baseFontSize) * 100);
            statusZoom.textContent = `${zoom}%`;
        };
        const detectEol = (text) => {
            if (!text || (!text.includes('\n') && !text.includes('\r'))) return 'CRLF';
            let crlf = (text.match(/\r\n/g) || []).length;
            let lf = (text.match(/(?<!\r)\n/g) || []).length;
            let cr = (text.match(/\r(?!\n)/g) || []).length;
            if (crlf > 0 && crlf >= lf && crlf >= cr) return 'CRLF';
            if (lf > 0 && lf >= cr) return 'LF';
            if (cr > 0) return 'CR';
            return 'CRLF';
        };
        const updateEolStatus = () => {
            let text = textArea.value;
            let eol = detectEol(text);
            currentEol = eol;
            statusEol.textContent = eol;
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
        textArea.addEventListener('input', () => {
            if (!isDirty) { isDirty = true; updateTitle(); }
            if (currentViewMode === 'markdown') renderMarkdown();
            if (currentViewMode === 'code') renderCode();
            updateEolStatus();
        });
        textArea.addEventListener('keyup', updateStatus);
        textArea.addEventListener('mouseup', updateStatus);
        textArea.addEventListener('selectionchange', updateEditMenu);
        textArea.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                document.execCommand('insertText', false, '    ');
            }
        });
        menuItems.forEach(item => {
            item.addEventListener('mouseenter', (e) => {
                menuItems.forEach(i => i.classList.remove('open'));
                item.classList.add('open');
            });
            item.addEventListener('mouseleave', (e) => {
                item.classList.remove('open');
            });
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                menuItems.forEach(i => i.classList.remove('open'));
                item.classList.add('open');
            });
        });
        document.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (item.classList.contains('disabled')) return;
                const action = item.dataset.action;
                const lang = item.dataset.lang;
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
                    case 'word-wrap':
                        item.classList.toggle('checked');
                        textArea.classList.toggle('wrap');
                        codeHighlightPre.classList.toggle('wrap');
                        saveSettings();
                        break;
                    case 'font': showFontDialog(); break;
                    case 'view-markdown':
                        document.querySelectorAll('[data-action="view-lang"]').forEach(i => i.classList.remove('checked'));
                        item.classList.toggle('checked');
                        switchViewMode(item.classList.contains('checked') ? 'markdown' : 'text');
                        break;
                    case 'view-lang':
                        document.querySelectorAll('[data-action="view-lang"]').forEach(i => i.classList.remove('checked'));
                        item.classList.add('checked');
                        document.querySelector('[data-action="view-markdown"]').classList.remove('checked');
                        currentLanguage = lang;
                        switchViewMode(lang === 'plaintext' ? 'text' : 'code');
                        break;
                }
            });
        });
        const fontDialog = getEl('font-dialog'), fontPreview = getEl('font-preview-text'),
              fontFamilyContainer = document.querySelector('.font-family-container'),
              fontFamilyInput = getEl('font-family-input'), fontStyleSelect = getEl('font-style-select'),
              fontSizeInput = getEl('font-size-input'), fontFamilyList = getEl('font-family-list');
        const availableFonts = [
            'MS Gothic', 'MS Mincho', 'Meiryo', 'Yu Gothic', 'Arial', 'Arial Black',
            'Comic Sans MS', 'Courier New', 'Georgia', 'Impact', 'Times New Roman',
            'Trebuchet MS', 'Verdana'
        ];
        const populateFontList = () => {
            fontFamilyList.innerHTML = '';
            availableFonts.forEach(font => {
                const item = document.createElement('div');
                item.className = 'font-family-item';
                item.textContent = font;
                item.style.fontFamily = font;
                item.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    fontFamilyInput.value = font;
                    fontFamilyList.style.display = 'none';
                    updateFontPreview();
                });
                fontFamilyList.appendChild(item);
            });
        };
        const updateFontPreview = () => {
            const style = fontStyleSelect.value.split(' ');
            fontPreview.style.fontFamily = fontFamilyInput.value;
            fontPreview.style.fontStyle = style.includes('italic') ? 'italic' : 'normal';
            fontPreview.style.fontWeight = style.includes('bold') ? 'bold' : 'normal';
            fontPreview.style.fontSize = `${fontSizeInput.value}px`;
        };
        const showFontDialog = () => {
            const computedStyle = getComputedStyle(textArea);
            fontFamilyInput.value = computedStyle.fontFamily.split(',')[0].replace(/"/g, '');
            fontSizeInput.value = parseInt(computedStyle.fontSize);
            fontDialog.style.display = 'flex';
            updateFontPreview();
        };
        fontFamilyInput.addEventListener('click', (e) => {
            e.stopPropagation();
            fontFamilyList.style.display = 'block';
        });
        [fontStyleSelect, fontSizeInput].forEach(el => el.addEventListener('input', updateFontPreview));
        fontFamilyInput.addEventListener('input', updateFontPreview);
        getEl('font-ok-btn').addEventListener('click', () => {
            baseFontSize = parseInt(fontSizeInput.value);
            applyFontSettings();
            saveSettings();
            fontDialog.style.display = 'none';
        });
        getEl('font-cancel-btn').addEventListener('click', () => {
            fontDialog.style.display = 'none';
        });
        const applyFontSettings = () => {
            const style = fontStyleSelect.value.split(' ');
            const newFontSizePx = `${fontSizeInput.value}px`;
            const newFontFamily = fontFamilyInput.value;
            const newFontStyle = style.includes('italic') ? 'italic' : 'normal';
            const newFontWeight = style.includes('bold') ? 'bold' : 'normal';
            textArea.style.fontFamily = newFontFamily;
            textArea.style.fontStyle = newFontStyle;
            textArea.style.fontWeight = newFontWeight;
            textArea.style.fontSize = newFontSizePx;
            codeHighlightPre.style.fontFamily = newFontFamily;
            codeHighlightPre.style.fontSize = newFontSizePx;
            codeHighlightPre.style.fontStyle = newFontStyle;
            codeHighlightPre.style.fontWeight = newFontWeight;
            updateZoomStatus();
        };
        const saveSettings = async () => {
            try {
                const settings = {
                    fontFamily: textArea.style.fontFamily,
                    fontSize: parseInt(getComputedStyle(textArea).fontSize),
                    fontStyle: textArea.style.fontStyle,
                    fontWeight: textArea.style.fontWeight,
                    wordWrap: textArea.classList.contains('wrap'),
                    baseFontSize: baseFontSize
                };
                await api('save_notepad_settings', { settings: JSON.stringify(settings) });
            } catch (e) {
                alert(`設定の保存に失敗しました: ${e.message}`);
            }
        };
        const loadSettings = async () => {
            try {
                const result = await api('get_notepad_settings');
                if (result.success && result.settings) {
                    const { fontFamily, fontSize, fontStyle, fontWeight, wordWrap, baseFontSize: savedBaseSize } = result.settings;
                    baseFontSize = savedBaseSize || 15;
                    const newFontSizePx = `${fontSize || baseFontSize}px`;
                    textArea.style.fontFamily = fontFamily || "'MS Gothic', monospace";
                    textArea.style.fontSize = newFontSizePx;
                    textArea.style.fontStyle = fontStyle || 'normal';
                    textArea.style.fontWeight = fontWeight || 'normal';
                    codeHighlightPre.style.fontFamily = textArea.style.fontFamily;
                    codeHighlightPre.style.fontSize = textArea.style.fontSize;
                    codeHighlightPre.style.fontStyle = textArea.style.fontStyle;
                    codeHighlightPre.style.fontWeight = textArea.style.fontWeight;
                    if (wordWrap) {
                        textArea.classList.add('wrap');
                        codeHighlightPre.classList.add('wrap');
                        document.querySelector('[data-action="word-wrap"]').classList.add('checked');
                    } else {
                        textArea.classList.remove('wrap');
                        codeHighlightPre.classList.remove('wrap');
                        document.querySelector('[data-action="word-wrap"]').classList.remove('checked');
                    }
                } else {
                     textArea.style.fontSize = `${baseFontSize}px`;
                     codeHighlightPre.style.fontSize = `${baseFontSize}px`;
                }
            } catch (e) {
                 alert(`設定の読み込みに失敗しました: ${e.message}`);
                 textArea.style.fontSize = `${baseFontSize}px`;
                 codeHighlightPre.style.fontSize = `${baseFontSize}px`;
            } finally {
                updateZoomStatus();
            }
        };
        document.addEventListener('click', (e) => {
            if (!fontFamilyContainer.contains(e.target)) {
                fontFamilyList.style.display = 'none';
            }
            menuItems.forEach(i => {
                if(!i.contains(e.target)) {
                    i.classList.remove('open');
                }
            });
        });
        document.querySelectorAll('[data-action="view-lang"]').forEach(item => {
            item.addEventListener('click', (e) => {
                document.querySelectorAll('[data-action="view-lang"]').forEach(i => i.classList.remove('checked'));
                item.classList.add('checked');
                document.querySelector('[data-action="view-markdown"]').classList.remove('checked');
                currentLanguage = item.dataset.lang;
                switchViewMode(currentLanguage === 'plaintext' ? 'text' : 'code');
            });
        });
        const switchViewMode = (mode) => {
            currentViewMode = mode;
            markdownPreview.style.display = 'none';
            codeHighlightPre.style.display = 'none';
            textArea.classList.remove('preview-mode');
            if (mode === 'markdown') {
                markdownPreview.style.display = 'block';
                textArea.classList.add('preview-mode');
                renderMarkdown();
            } else if (mode === 'code') {
                codeHighlightPre.style.display = 'block';
                textArea.classList.add('preview-mode');
                renderCode();
            }
        };
        const renderMarkdown = () => {
            markdownPreview.innerHTML = marked.parse(textArea.value);
        };
        const renderCode = () => {
            codeHighlightPre.style.fontFamily = textArea.style.fontFamily;
            codeHighlightPre.style.fontSize = textArea.style.fontSize;
            codeHighlightPre.style.fontStyle = textArea.style.fontStyle;
            codeHighlightPre.style.fontWeight = textArea.style.fontWeight;
            const prismLang = prismLangMap[currentLanguage] || 'none';
            codeHighlightOutput.textContent = textArea.value;
            codeHighlightOutput.className = `language-${prismLang}`;
            Prism.highlightElement(codeHighlightOutput);
        };
        textArea.addEventListener('scroll', () => {
            if(currentViewMode === 'code') {
                codeHighlightPre.scrollTop = textArea.scrollTop;
                codeHighlightPre.scrollLeft = textArea.scrollLeft;
            }
        });
        window.addEventListener('message', (event) => {
            const { type, filePath, mode, sourceWindowId } = event.data;
            if (type === 'fileDialogResponse' && sourceWindowId === myWindowId) {
                if (filePath) {
                    if (mode === 'open') {
                        confirmAndSaveIfNeeded(() => loadFile(filePath));
                    } else if (mode === 'save') {
                        currentFilePath = filePath;
                        updateTitle();
                        saveFile(filePath);
                    }
                }
            }
        });
        document.addEventListener('keydown', e => {
            if (e.ctrlKey && (e.key === '+' || e.key === '=')) {
                e.preventDefault();
                const currentSize = parseInt(getComputedStyle(textArea).fontSize);
                const newSize = Math.min(Math.round(baseFontSize * 10), currentSize + 1);
                textArea.style.fontSize = `${newSize}px`;
                codeHighlightPre.style.fontSize = `${newSize}px`;
                updateZoomStatus();
            }
            if (e.ctrlKey && e.key === '-') {
                e.preventDefault();
                const currentSize = parseInt(getComputedStyle(textArea).fontSize);
                const newSize = Math.max(Math.round(baseFontSize * 0.1), currentSize - 1);
                textArea.style.fontSize = `${newSize}px`;
                codeHighlightPre.style.fontSize = `${newSize}px`;
                updateZoomStatus();
            }
            if (e.ctrlKey && e.key === '0') {
                e.preventDefault();
                textArea.style.fontSize = `${baseFontSize}px`;
                codeHighlightPre.style.fontSize = `${baseFontSize}px`;
                updateZoomStatus();
            }
        });
        const initializeApp = async () => {
            await loadSettings();
            const fileToOpenOnInit = <?php echo json_encode($fileToOpen); ?>;
            if(fileToOpenOnInit) {
                loadFile(fileToOpenOnInit);
            } else {
                resetDocument();
            }
            populateFontList();
            updateEditMenu();
            updateStatus();
        };
        initializeApp();
    });
    </script>
</body>
</html>

}