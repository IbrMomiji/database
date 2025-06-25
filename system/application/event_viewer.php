<?php
require_once __DIR__ . '/../boot.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_uuid'])) {
    http_response_code(403);
    die('アクセス権がありません。ログインしてください。');
}

$user_uuid = $_SESSION['user_uuid'];
$log_dir = USER_DIR_PATH . '/' . $user_uuid . '/.logs';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'get_log_types':
                $log_files = [];
                if (is_dir($log_dir)) {
                    $files = scandir($log_dir);
                    foreach ($files as $file) {
                        if (is_file($log_dir . '/' . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                            $log_files[] = basename($file, '.log');
                        }
                    }
                }
                echo json_encode(['success' => true, 'log_types' => $log_files]);
                break;

            case 'get_logs':
                $log_type = $_POST['type'] ?? '';
                if (empty($log_type) || !preg_match('/^[a-zA-Z0-9_-]+$/', $log_type)) {
                    throw new Exception('無効なログタイプです。');
                }

                $log_file_path = $log_dir . '/' . $log_type . '.log';
                $logs = [];
                if (file_exists($log_file_path)) {
                    $file_content = file($log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if ($file_content) {
                        foreach (array_reverse($file_content) as $line) {
                            $decoded_line = json_decode($line, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $logs[] = $decoded_line;
                            }
                        }
                    }
                }
                echo json_encode(['success' => true, 'logs' => $logs]);
                break;
            
            default:
                throw new Exception('不明なアクションです。');
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
    <title>イベント ビューアー</title>
    <style>
        :root {
            --font-family: 'Yu Gothic UI', 'Segoe UI', Meiryo, system-ui, sans-serif;
            --bg-color: #ffffff;
            --bg-pane: #f0f0f0;
            --border-color: #cccccc;
            --text-color: #000000;
            --text-secondary-color: #666666;
            --selection-bg: #cce8ff;
            --selection-border: #99d1ff;
            --header-bg: #f5f5f5;
            --error-color: #d93025;
            --warning-color: #fbbc04;
            --info-color: #4285f4;
            --scrollbar-track-color: #f1f1f1;
            --scrollbar-thumb-color: #c1c1c1;
            --scrollbar-thumb-hover-color: #a8a8a8;
        }
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            font-family: var(--font-family);
            background-color: var(--bg-color);
            color: var(--text-color);
            font-size: 13px;
        }
        ::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }
        ::-webkit-scrollbar-track {
            background: var(--scrollbar-track-color);
        }
        ::-webkit-scrollbar-thumb {
            background-color: var(--scrollbar-thumb-color);
            border-radius: 6px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background-color: var(--scrollbar-thumb-hover-color);
        }
        .event-viewer-container {
            display: flex;
            height: 100%;
            width: 100%;
            box-sizing: border-box;
        }
        .left-pane {
            width: 220px;
            min-width: 150px;
            height: 100%;
            border-right: 1px solid var(--border-color);
            background-color: var(--bg-pane);
            overflow-y: auto;
            padding: 5px;
            box-sizing: border-box;
            user-select: none;
            flex-shrink: 0;
        }
        .tree-item {
            padding: 5px 8px;
            cursor: pointer;
            border-radius: 4px;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .tree-item:hover {
            background-color: #e9e9e9;
        }
        .tree-item.active {
            background-color: var(--selection-bg);
            border: 1px solid var(--selection-border);
            padding: 4px 7px;
        }
        .tree-item-icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }
        .main-content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }
        .center-pane-splitter {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }
        .log-list-container {
            height: 60%;
            overflow: auto;
            border-bottom: 1px solid var(--border-color);
        }
        .splitter {
            height: 6px;
            background: var(--bg-pane);
            cursor: ns-resize;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
        }
        .log-details-pane {
            height: 40%;
            overflow-y: auto;
            padding: 15px;
            box-sizing: border-box;
            background-color: #ffffff;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 12px;
        }
        .log-table {
            width: 100%;
            border-collapse: collapse;
        }
        .log-table th {
            position: sticky;
            top: 0;
            background-color: var(--header-bg);
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid var(--border-color);
            font-weight: normal;
        }
        .log-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e0e0e0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 0;
            cursor: default;
        }
        .log-table tr:hover {
            background-color: #f5f5f5;
        }
        .log-table tr.selected {
            background-color: var(--selection-bg) !important;
        }
        .log-table .col-level { width: 90px; }
        .log-table .col-datetime { width: 150px; }
        .log-table .col-message { width: auto; }
        .level-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .level-icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }
        .detail-group {
            margin-bottom: 15px;
        }
        .detail-label {
            font-weight: bold;
            color: var(--text-secondary-color);
            margin: 0 0 5px 0;
        }
        .detail-content {
            white-space: pre-wrap;
            word-wrap: break-word;
            padding: 8px;
            background: #f9f9f9;
            border: 1px solid #eee;
            border-radius: 4px;
        }
    </style>
</head>
<body>

    <div class="event-viewer-container">
        <div class="left-pane" id="left-pane"></div>
        <div class="main-content">
            <div class="center-pane-splitter">
                <div class="log-list-container" id="log-list-container">
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th class="col-level">レベル</th>
                                <th class="col-datetime">日時</th>
                                <th class="col-message">メッセージ</th>
                            </tr>
                        </thead>
                        <tbody id="log-list-body"></tbody>
                    </table>
                </div>
                <div class="splitter" id="splitter"></div>
                <div class="log-details-pane" id="log-details-pane"></div>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const leftPane = document.getElementById('left-pane');
    const logListBody = document.getElementById('log-list-body');
    const logDetailsPane = document.getElementById('log-details-pane');
    const splitter = document.getElementById('splitter');
    const logListContainer = document.getElementById('log-list-container');

    const ICONS = {
        ERROR: `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#d93025"/><path d="M5 5l6 6M11 5l-6 6" stroke="#fff" stroke-width="1.5"/></svg>`,
        WARNING: `<svg class="level-icon" viewBox="0 0 16 16"><path d="M8 1.5L1 14.5h14L8 1.5z" fill="#fbbc04"/><path d="M8 6v4" stroke="#000" stroke-width="1.5"/><circle cx="8" cy="12" r="0.75" fill="#000"/></svg>`,
        INFO: `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#4285f4"/><text x="8" y="11.5" font-size="10" fill="#fff" text-anchor="middle" font-weight="bold">i</text></svg>`,
        LOG: `<svg class="tree-item-icon" viewBox="0 0 16 16"><path fill="#808080" d="M3 1h10v1H3zM3 3h10v1H3zM3 5h10v1H3zM3 7h10v1H3zM3 9h10v1H3zM3 11h6v1H3z"/></svg>`,
    };
    
    const apiCall = async (action, body) => {
        const formData = new FormData();
        formData.append('action', action);
        if (body) {
            for (const key in body) {
                formData.append(key, body[key]);
            }
        }
        try {
            const response = await fetch('', { method: 'POST', body: formData });
            if (!response.ok) throw new Error(`サーバーからの応答が不正です: ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            logDetailsPane.innerHTML = `<p class="detail-label" style="color:#d93025;">エラー</p><div class="detail-content">${escapeHtml(error.message)}</div>`;
            return { success: false };
        }
    };

    const renderLogTypes = (logTypes) => {
        leftPane.innerHTML = '';
        if (logTypes.length === 0) {
            leftPane.innerHTML = '<div style="padding:10px; color:#666;">利用可能なログファイルがありません。</div>';
            return;
        }
        logTypes.forEach(type => {
            const item = document.createElement('div');
            item.className = 'tree-item';
            item.innerHTML = `${ICONS.LOG} <span>${type.charAt(0).toUpperCase() + type.slice(1)}</span>`;
            item.dataset.logType = type;
            item.addEventListener('click', () => {
                document.querySelectorAll('.tree-item.active').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                fetchAndRenderLogs(type);
            });
            leftPane.appendChild(item);
        });
        if (logTypes.length > 0) {
            leftPane.firstChild.click();
        }
    };

    const renderLogs = (logs) => {
        logListBody.innerHTML = '';
        logDetailsPane.innerHTML = '<div style="padding:10px; color:#666;">ログエントリを選択して詳細を表示します。</div>';
        if (!logs || logs.length === 0) {
             logListBody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding: 20px; color:#666;">このログにはエントリがありません。</td></tr>';
             return;
        }

        logs.forEach((log) => {
            const row = document.createElement('tr');
            
            row.innerHTML = `
                <td><div class="level-cell">${ICONS[log.level] || ICONS.INFO} ${escapeHtml(log.level)}</div></td>
                <td>${escapeHtml(log.timestamp)}</td>
                <td>${escapeHtml(log.message)}</td>
            `;

            row.addEventListener('click', () => {
                document.querySelectorAll('.log-table tr.selected').forEach(r => r.classList.remove('selected'));
                row.classList.add('selected');
                renderLogDetails(log);
            });
            logListBody.appendChild(row);
        });
    };

    const renderLogDetails = (log) => {
        logDetailsPane.innerHTML = `
            <div class="detail-group">
                <p class="detail-label">メッセージ:</p>
                <div class="detail-content">${escapeHtml(log.message)}</div>
            </div>
            <div class="detail-group">
                <p class="detail-label">日時:</p>
                <div class="detail-content">${escapeHtml(log.timestamp)}</div>
            </div>
            <div class="detail-group">
                <p class="detail-label">レベル:</p>
                <div class="detail-content">${escapeHtml(log.level)}</div>
            </div>
            <div class="detail-group">
                <p class="detail-label">詳細:</p>
                <div class="detail-content">${escapeHtml(JSON.stringify(log.details, null, 2))}</div>
            </div>
        `;
    };

    const fetchAndRenderLogs = async (logType) => {
        const response = await apiCall('get_logs', { type: logType });
        if (response.success) {
            renderLogs(response.logs);
        }
    };

    const initialize = async () => {
        // 親ウィンドウのタイトルバーを白にするよう通知
        try {
            window.parent.postMessage({ type: 'setWindowStyle', style: 'light' }, '*');
        } catch(e) {
            console.warn("Could not post message to parent window to set style.");
        }
        const response = await apiCall('get_log_types');
        if (response.success) {
            renderLogTypes(response.log_types);
        }
    };

    const escapeHtml = (unsafe) => {
        if (typeof unsafe !== 'string') {
            if (unsafe === null || unsafe === undefined) return '';
            try {
                return String(unsafe);
            } catch(e) {
                return '';
            }
        }
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    };

    splitter.addEventListener('mousedown', (e) => {
        e.preventDefault();
        const startY = e.clientY;
        const startHeight = logListContainer.offsetHeight;
        
        const doDrag = (e) => {
            const newHeight = startHeight + (e.clientY - startY);
            const parentHeight = logListContainer.parentElement.offsetHeight;
            const minHeight = 50;
            const maxHeight = parentHeight - 50;
            
            if (newHeight > minHeight && newHeight < maxHeight) {
                 logListContainer.style.height = `${newHeight}px`;
            }
        };

        const stopDrag = () => {
            document.removeEventListener('mousemove', doDrag);
            document.removeEventListener('mouseup', stopDrag);
        };
        
        document.addEventListener('mousemove', doDrag);
        document.addEventListener('mouseup', stopDrag);
    });

    initialize();

    const myWindowIdForMessaging = window.name;
    document.addEventListener('mousedown', () => {
        window.parent.postMessage({ type: 'iframeClick', windowId: myWindowIdForMessaging }, '*');
    }, true);
});
</script>
</body>
</html>