<?php
require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../MessagePackUnpacker.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_uuid'])) {
    http_response_code(403);
    die('アクセス権がありません。ログインしてください。');
}

$user_uuid = $_SESSION['user_uuid'];
$log_dir = USER_DIR_PATH . '/' . $user_uuid . '/.logs';

class UserSettings
{
    private static function getSettingsFile(string $user_uuid): string
    {
        return USER_DIR_PATH . '/' . $user_uuid . '/.settings/config.json';
    }
    public static function get(string $user_uuid, string $key, $default = null)
    {
        $settingsFile = self::getSettingsFile($user_uuid);
        if (!file_exists($settingsFile)) return $default;
        $settings = json_decode(file_get_contents($settingsFile), true);
        return $settings[$key] ?? $default;
    }
}

function cleanup_user_logs(string $user_uuid): void
{
    $retention_days = UserSettings::get($user_uuid, 'log_retention_days', 30);
    if ($retention_days <= 0) return;

    $logDir = USER_DIR_PATH . '/' . $user_uuid . '/.logs';
    if (!is_dir($logDir)) return;

    $cutoff_time = time() - ($retention_days * 86400);
    $unpacker = new MessagePackUnpacker();
    $log_files = glob($logDir . '/*.log');

    foreach ($log_files as $log_file) {
        $good_lines = [];
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) continue;
        foreach ($lines as $line) {
            try {
                $log_entry = $unpacker->unpack($line);
                if (isset($log_entry['timestamp']) && $log_entry['timestamp'] >= $cutoff_time) {
                    $good_lines[] = $line;
                }
            } catch (Exception $e) { continue; }
        }
        if (count($lines) !== count($good_lines)) {
            file_put_contents($log_file, implode(PHP_EOL, $good_lines) . (empty($good_lines) ? '' : PHP_EOL));
        }
    }
}

cleanup_user_logs($user_uuid);

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
                    sort($log_files);
                }
                echo json_encode(['success' => true, 'log_types' => $log_files]);
                break;

            case 'get_logs':
                $log_type = $_POST['type'] ?? '';
                if (empty($log_type) || !preg_match('/^[a-zA-Z0-9_-]+$/', $log_type)) {
                    throw new Exception('無効なログ種別です。');
                }

                $log_file_path = $log_dir . '/' . $log_type . '.log';
                $logs = [];
                if (file_exists($log_file_path)) {
                    $unpacker = new MessagePackUnpacker();
                    $file_content = file($log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if ($file_content) {
                        foreach ($file_content as $line) {
                             try {
                                $logs[] = $unpacker->unpack($line);
                            } catch(Exception $e) { /* ignore malformed lines */ }
                        }
                    }
                    usort($logs, function($a, $b) {
                        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
                    });
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
            --scrollbar-track-color: #f1f1f1;
            --scrollbar-thumb-color: #c1c1c1;
            --scrollbar-thumb-hover-color: #a8a8a8;
        }

        html,
        body {
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
        .log-table .col-id { width: 80px; }
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
                                <th class="col-id">イベントID</th>
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
                '緊急': `<svg class="level-icon" viewBox="0 0 16 16" fill="#b71c1c"><circle cx="8" cy="8" r="7"/><path d="M7.25 10.25h1.5V12h-1.5zM7.25 4h1.5v5.5h-1.5z" fill="#fff"/></svg>`,
                '警報': `<svg class="level-icon" viewBox="0 0 16 16" fill="#d32f2f"><path d="M8 1.5L1 14.5h14L8 1.5z"/><path d="M7.25 10h1.5v1.5h-1.5zM7.25 5h1.5v4h-1.5z" fill="#fff"/></svg>`,
                '重大': `<svg class="level-icon" viewBox="0 0 16 16" fill="#f44336"><circle cx="8" cy="8" r="7"/><path d="M8 4.5a.75.75 0 00-.75.75v3a.75.75 0 001.5 0v-3A.75.75 0 008 4.5zM8 10a1 1 0 100 2 1 1 0 000-2z" fill="#fff"/></svg>`,
                'エラー': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#ff5252"/><path d="M5 5l6 6M11 5l-6 6" stroke="#fff" stroke-width="1.5"/></svg>`,
                '警告': `<svg class="level-icon" viewBox="0 0 16 16"><path d="M8 1.5L1 14.5h14L8 1.5z" fill="#fbbc04"/><path d="M8 6v4" stroke="#000" stroke-width="1.5"/><circle cx="8" cy="12" r="0.75" fill="#000"/></svg>`,
                '通知': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#03a9f4"/><path d="M8 5a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1zm0 7a1 1 0 100-2 1 1 0 000 2z" fill="#fff"/></svg>`,
                '情報': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#4285f4"/><text x="8" y="11.5" font-size="10" fill="#fff" text-anchor="middle" font-weight="bold">i</text></svg>`,
                'デバッグ': `<svg class="level-icon" viewBox="0 0 16 16" fill="#888"><path d="M12 9H4v5h8V9zM5 10h1v1H5zm2 0h1v1H7z"/><path d="M10 2a1 1 0 00-1 1v1H7V3a1 1 0 00-2 0v1H4v2h1v1H4v1h1v1H4v1h1.5l.5.5.5-.5H8v-1H7v-1h1V8h1v1h-1v1H8v1h1.5l.5.5.5-.5H12V8h-1V7h1V5h-1V4h-1V3a1 1 0 00-1-1z" /></svg>`,
                'LOG': `<svg class="tree-item-icon" viewBox="0 0 16 16"><path fill="#666" d="M3 1h10v1H3zM3 3h10v1H3zM3 5h10v1H3zM3 7h10v1H3zM3 9h10v1H3zM3 11h6v1H3z"/></svg>`,
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
                    logListBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 20px; color:#666;">このログにはエントリがありません。</td></tr>';
                    return;
                }

                logs.forEach((log) => {
                    const row = document.createElement('tr');
                    const levelName = log.level_name || '情報';
                    const icon = ICONS[levelName] || ICONS['情報'];

                    row.innerHTML = `
                        <td><div class="level-cell">${icon} ${escapeHtml(levelName)}</div></td>
                        <td>${escapeHtml(new Date(log.timestamp * 1000).toLocaleString('ja-JP'))}</td>
                        <td>${escapeHtml(log.event_id || 'N/A')}</td>
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
                const detailsHtml = log.details && Object.keys(log.details).length > 0 ?
                    escapeHtml(JSON.stringify(log.details, null, 2)) : 'なし';
                logDetailsPane.innerHTML = `
                    <div class="detail-group"><p class="detail-label">メッセージ:</p><div class="detail-content">${escapeHtml(log.message)}</div></div>
                    <div class="detail-group"><p class="detail-label">レベル:</p><div class="detail-content">${escapeHtml(log.level_name)} (コード: ${log.level_code})</div></div>
                    <div class="detail-group"><p class="detail-label">日時:</p><div class="detail-content">${escapeHtml(new Date(log.timestamp * 1000).toLocaleString('ja-JP'))}</div></div>
                    <div class="detail-group"><p class="detail-label">イベントID:</p><div class="detail-content">${escapeHtml(log.event_id)}</div></div>
                    <div class="detail-group"><p class="detail-label">ソース:</p><div class="detail-content">${escapeHtml(log.source)}</div></div>
                    <div class="detail-group"><p class="detail-label">カテゴリ:</p><div class="detail-content">${escapeHtml(log.category)}</div></div>
                    <div class="detail-group"><p class="detail-label">詳細:</p><div class="detail-content"><pre>${detailsHtml}</pre></div></div>
                `;
            };

            const fetchAndRenderLogs = async (logType) => {
                logListBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 20px; color:#666;">読み込み中...</td></tr>';
                const response = await apiCall('get_logs', { type: logType });
                if (response.success) {
                    renderLogs(response.logs);
                }
            };

            const initialize = async () => {
                const response = await apiCall('get_log_types');
                if (response.success) {
                    renderLogTypes(response.log_types);
                }
            };

            const escapeHtml = (unsafe) => {
                if (typeof unsafe !== 'string') {
                    if (unsafe === null || unsafe === undefined) return '';
                    try { return String(unsafe); } catch (e) { return ''; }
                }
                return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
            };

            splitter.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const startY = e.clientY;
                const startHeight = logListContainer.offsetHeight;
                const doDrag = (e) => {
                    const newHeight = startHeight + (e.clientY - startY);
                    const parentHeight = logListContainer.parentElement.offsetHeight;
                    if (newHeight > 50 && newHeight < parentHeight - 50) {
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
            
            document.addEventListener('keydown', (e) => {
                if ((e.altKey && e.key.toLowerCase() === 'w') || (e.altKey && ['ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key))) {
                    e.preventDefault();
                    window.parent.postMessage({ type: 'forwardedKeydown', key: e.key, altKey: e.altKey, ctrlKey: e.ctrlKey, shiftKey: e.shiftKey, metaKey: e.metaKey, windowId: myWindowIdForMessaging }, '*');
                }
            });

            document.addEventListener('keyup', (e) => {
                if (e.key === 'Alt') {
                    e.preventDefault();
                    window.parent.postMessage({ type: 'forwardedKeyup', key: e.key }, '*');
                }
            });
        });
    </script>
</body>
</html>