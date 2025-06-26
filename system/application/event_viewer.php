<?php
require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../MessagePackUnpacker.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_uuid'])) {
    http_response_code(403);
    die('アクセス権がありません。ログインしてください。');
}

$user_uuid = $_SESSION['user_uuid'];
$log_dir = USER_DIR_PATH . '/' . $user_uuid . '/.logs';
$settings_dir = USER_DIR_PATH . '/' . $user_uuid . '/.settings';
$views_file = $settings_dir . '/event_viewer_views.json';

class UserSettings
{
    private static function getSettingsFile(string $user_uuid, string $fileName): string
    {
        return USER_DIR_PATH . '/' . $user_uuid . '/.settings/' . $fileName;
    }

    public static function get(string $user_uuid, string $key, $default = null, string $fileName = 'config.json')
    {
        $settingsFile = self::getSettingsFile($user_uuid, $fileName);
        if (!file_exists($settingsFile)) return $default;
        $settings = json_decode(file_get_contents($settingsFile), true);
        return $settings[$key] ?? $default;
    }

    public static function set(string $user_uuid, string $key, $value, string $fileName = 'config.json'): bool
    {
        $settingsFile = self::getSettingsFile($user_uuid, $fileName);
        $settingsDir = dirname($settingsFile);
        if (!is_dir($settingsDir) && !@mkdir($settingsDir, 0775, true)) return false;
        
        $settings = [];
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true) ?? [];
        }
        $settings[$key] = $value;
        return file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT)) !== false;
    }
    
    public static function getAll(string $user_uuid, string $fileName) {
        $settingsFile = self::getSettingsFile($user_uuid, $fileName);
        if (!file_exists($settingsFile)) return [];
        return json_decode(file_get_contents($settingsFile), true) ?? [];
    }

    public static function delete(string $user_uuid, string $key, string $fileName): bool {
        $settingsFile = self::getSettingsFile($user_uuid, $fileName);
        if (!file_exists($settingsFile)) return true;
        $settings = json_decode(file_get_contents($settingsFile), true) ?? [];
        unset($settings[$key]);
        return file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT)) !== false;
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

function filter_logs(array $logs, array $filters): array
{
    if (empty($filters)) return $logs;
    $filtered = array_filter($logs, function ($log) use ($filters) {
        if (!empty($filters['start_date']) && $log['timestamp'] < strtotime($filters['start_date'])) return false;
        if (!empty($filters['end_date']) && $log['timestamp'] > strtotime($filters['end_date'])) return false;
        if (!empty($filters['levels']) && !in_array($log['level_name'], $filters['levels'])) return false;
        if (!empty($filters['event_id'])) {
            $event_ids_str = preg_replace('/\s+/', '', $filters['event_id']);
            $event_ids_to_check = [];
            $parts = explode(',', $event_ids_str);
            foreach($parts as $part){
                if(strpos($part, '-') !== false){
                    list($start, $end) = explode('-', $part);
                    if(is_numeric($start) && is_numeric($end)){
                        for($i = $start; $i <= $end; $i++){
                            $event_ids_to_check[] = (int)$i;
                        }
                    }
                } elseif(is_numeric($part)) {
                    $event_ids_to_check[] = (int)$part;
                }
            }
            if (!empty($event_ids_to_check) && !in_array($log['event_id'], $event_ids_to_check)) return false;
        }
        if (!empty($filters['source']) && $filters['source'] !== 'All' && strcasecmp($log['source'], $filters['source']) !== 0) return false;
        if (!empty($filters['category']) && stripos($log['category'], $filters['category']) === false) return false;
        if (!empty($filters['message']) && stripos($log['message'], $filters['message']) === false) return false;
        return true;
    });
    return array_values($filtered);
}

cleanup_user_logs($user_uuid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'get_log_sources':
                $log_sources = [];
                if (is_dir($log_dir)) {
                    $files = scandir($log_dir);
                    foreach ($files as $file) {
                        if (is_file($log_dir . '/' . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                            $log_sources[] = basename($file, '.log');
                        }
                    }
                    sort($log_sources);
                }
                echo json_encode(['success' => true, 'log_sources' => $log_sources]);
                break;
                
            case 'get_custom_views':
                echo json_encode(['success' => true, 'views' => UserSettings::getAll($user_uuid, 'event_viewer_views.json')]);
                break;

            case 'save_custom_view':
                $view_name = $_POST['name'] ?? null;
                $filters = json_decode($_POST['filters'] ?? '{}', true);
                $old_name = $_POST['old_name'] ?? null;
                if(!$view_name || empty($filters)) throw new Exception('ビュー名またはフィルターが無効です。');

                if($old_name && $old_name !== $view_name) {
                    UserSettings::delete($user_uuid, $old_name, 'event_viewer_views.json');
                }
                UserSettings::set($user_uuid, $view_name, $filters, 'event_viewer_views.json');
                echo json_encode(['success' => true, 'message' => "カスタムビュー「${view_name}」を保存しました。"]);
                break;

            case 'delete_custom_view':
                $view_name = $_POST['name'] ?? null;
                if(!$view_name) throw new Exception('ビュー名が無効です。');
                UserSettings::delete($user_uuid, $view_name, 'event_viewer_views.json');
                echo json_encode(['success' => true, 'message' => "カスタムビュー「${view_name}」を削除しました。"]);
                break;

            case 'get_logs':
                $filters = json_decode($_POST['filters'] ?? '{}', true);
                $log_files = glob($log_dir . '/*.log');
                $all_logs = [];

                if($log_files){
                    $unpacker = new MessagePackUnpacker();
                    foreach ($log_files as $log_file) {
                        $file_content = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        if ($file_content) {
                            foreach ($file_content as $line) {
                                try {
                                    $all_logs[] = $unpacker->unpack($line);
                                } catch (Exception $e) { continue; }
                            }
                        }
                    }
                }
                
                $filtered_logs = filter_logs($all_logs, $filters);

                usort($filtered_logs, function($a, $b) {
                    return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
                });

                echo json_encode(['success' => true, 'logs' => $filtered_logs]);
                break;
            
            case 'clear_log':
                $log_source_to_clear = $_POST['log_source'] ?? null;
                if(!$log_source_to_clear) throw new Exception('ログソースが指定されていません。');
                
                if($log_source_to_clear === 'All') {
                    $files = glob($log_dir . '/*.log');
                    foreach($files as $file) {
                        if(is_file($file)) unlink($file);
                    }
                } else {
                    $log_file_to_clear = $log_dir . '/' . $log_source_to_clear . '.log';
                    if(file_exists($log_file_to_clear)) unlink($log_file_to_clear);
                }
                 echo json_encode(['success' => true, 'message' => "ログ「${log_source_to_clear}」を消去しました。"]);
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
        :root { --font-family: 'Yu Gothic UI', 'Segoe UI', Meiryo, system-ui, sans-serif; --bg-color: #f0f0f0; --border-color: #cccccc; --text-color: #000000; --text-secondary-color: #666666; --selection-bg: #d4e8f7; --selection-border: #99c2e4; --header-bg: #f0f0f0; --scrollbar-track-color: var(--bg-color); --scrollbar-thumb-color: #c1c1c1; --scrollbar-thumb-hover-color: #a8a8a8; --button-bg: #f0f0f0; --button-border: #adadad; --button-hover-bg: #e5f3ff; --button-hover-border: #0078d7; }
        html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; font-family: var(--font-family); background-color: var(--bg-color); color: var(--text-color); font-size: 13px; }
        ::-webkit-scrollbar { width: 16px; height: 16px; background-color: var(--scrollbar-track-color); }
        ::-webkit-scrollbar-track { background-color: var(--scrollbar-track-color); }
        ::-webkit-scrollbar-thumb { background-color: var(--scrollbar-thumb-color); border: 4px solid var(--scrollbar-track-color); border-radius: 0; }
        ::-webkit-scrollbar-thumb:hover { background-color: var(--scrollbar-thumb-hover-color); }
        .event-viewer-container { display: flex; height: 100%; width: 100%; box-sizing: border-box; }
        .left-pane { width: 240px; min-width: 180px; height: 100%; border-right: 1px solid var(--border-color); background-color: var(--bg-color); overflow-y: auto; padding: 5px; box-sizing: border-box; user-select: none; flex-shrink: 0; }
        .tree-item-header { padding: 6px 8px; font-weight: bold; color: var(--text-secondary-color); text-transform: uppercase; font-size: 11px; }
        .tree-item { padding: 5px 8px 5px 22px; cursor: pointer; border: 1px solid transparent; white-space: nowrap; display: flex; align-items: center; gap: 6px; }
        .tree-item:hover { background-color: #e9e9e9; }
        .tree-item.active { background-color: var(--selection-bg); border: 1px solid var(--selection-border); }
        .tree-item-icon { width: 16px; height: 16px; flex-shrink: 0; }
        .main-content { flex-grow: 1; display: flex; overflow: hidden; }
        .main-content-inner { flex-grow: 1; display: flex; flex-direction: column; height: 100%; overflow: hidden; }
        .main-content-header { padding: 8px 12px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fff; flex-shrink: 0; }
        .main-content-header h3 { margin: 0; font-size: 1.2em; font-weight: normal; }
        .header-actions button { background: none; border: 1px solid transparent; padding: 4px; border-radius: 0; cursor: pointer; }
        .header-actions button:hover { background-color: #e0e0e0; }
        .center-pane-splitter { flex-grow: 1; display: flex; flex-direction: column; height: 100%; overflow: hidden; }
        .log-list-container { overflow: auto; border-bottom: 1px solid var(--border-color); background: #fff; }
        .splitter { height: 6px; background: var(--bg-color); cursor: ns-resize; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
        .log-details-pane { flex-grow: 1; overflow-y: auto; padding: 15px; box-sizing: border-box; background-color: #fff; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; }
        .log-table { width: 100%; border-collapse: collapse; }
        .log-table th { position: sticky; top: 0; background-color: var(--header-bg); text-align: left; padding: 8px; border-bottom: 1px solid var(--border-color); font-weight: normal; }
        .log-table td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 0; cursor: default; }
        .log-table tr:hover { background-color: #f5f5f5; }
        .log-table tr.selected { background-color: var(--selection-bg) !important; color: #000; }
        .level-cell { display: flex; align-items: center; gap: 6px; } .level-icon { width: 16px; height: 16px; flex-shrink: 0; }
        .detail-group { margin-bottom: 15px; } .detail-label { font-weight: bold; color: var(--text-secondary-color); margin: 0 0 5px 0; }
        .detail-content { white-space: pre-wrap; word-wrap: break-word; padding: 8px; background: #f9f9f9; border: 1px solid #eee; border-radius: 0; }
        .right-pane { display: none; width: 280px; flex-shrink: 0; border-left: 1px solid var(--border-color); background: var(--bg-color); padding: 12px; overflow-y: auto; }
        .right-pane.visible { display: block; }
        .filter-group { margin-bottom: 15px; } .filter-group label { display: block; margin-bottom: 5px; }
        .filter-group input, .filter-group select { width: 100%; box-sizing: border-box; padding: 5px; border: 1px solid #999; border-radius: 0; }
        #filter-levels { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; }
        #filter-levels label { font-weight: normal; }
        .filter-buttons { margin-top: 20px; display: flex; justify-content: flex-end; gap: 8px; }
        .filter-buttons button { padding: 6px 12px; border: 1px solid var(--button-border); background: var(--button-bg); border-radius: 0; cursor: pointer; }
        .filter-buttons button:hover { background: var(--button-hover-bg); border-color: var(--button-hover-border); }
        .dialog-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.2); z-index: 1001; display: none; justify-content: center; align-items: center; }
        .dialog-content { background: var(--bg-color); padding: 20px; border: 1px solid var(--border-color); min-width: 300px;}
        .dialog-content h4 { margin-top: 0; font-weight: normal; } .dialog-content input { width: 95%; padding: 8px; margin-bottom: 10px; border: 1px solid #999; }
        .context-menu { position: fixed; z-index: 1000; background: #f0f0f0; border: 1px solid #999; min-width: 180px; padding: 2px; box-shadow: 1px 1px 3px rgba(0, 0, 0, 0.2); user-select: none; }
        .context-menu-item { padding: 4px 12px; cursor: default; }
        .context-menu-item:hover { background: #0078d7; color: white; }
    </style>
</head>
<body>
    <div class="event-viewer-container">
        <div class="left-pane" id="left-pane">
            <div id="custom-views-container">
                <div class="tree-item-header">カスタム ビュー</div>
                <div id="custom-views-list"></div>
            </div>
             <div id="log-sources-container">
                <div class="tree-item-header">Database ログ</div>
                <div id="log-sources-list"></div>
            </div>
        </div>
        <div class="main-content" id="main-content">
            <div class="main-content-inner">
                <div class="main-content-header">
                    <h3 id="current-view-title">ログ</h3>
                    <div class="header-actions">
                        <button id="filter-pane-toggle" title="フィルター">フィルター</button>
                        <button id="save-view-btn" title="現在のフィルターからビューを作成...">ビューを保存...</button>
                        <button id="clear-log-btn" title="ログの消去...">ログを消去...</button>
                    </div>
                </div>
                <div class="center-pane-splitter">
                    <div class="log-list-container" id="log-list-container">
                        <table class="log-table">
                            <thead>
                                <tr><th>レベル</th><th>日時</th><th>ソース</th><th>イベント ID</th><th>タスクのカテゴリ</th></tr>
                            </thead>
                            <tbody id="log-list-body"></tbody>
                        </table>
                    </div>
                    <div class="splitter" id="splitter"></div>
                    <div class="log-details-pane" id="log-details-pane"></div>
                </div>
            </div>
            <div class="right-pane" id="right-pane">
                <h4><span id="filter-title"></span> のフィルター</h4>
                <div class="filter-group">
                    <label for="filter-start-date">ログの日時:</label>
                    <input type="datetime-local" id="filter-start-date" step="1">
                    <span>から</span>
                    <input type="datetime-local" id="filter-end-date" step="1">
                </div>
                <div class="filter-group">
                    <label>レベル:</label>
                    <div id="filter-levels">
                        <label><input type="checkbox" value="緊急"> 緊急</label> <label><input type="checkbox" value="警告"> 警告</label>
                        <label><input type="checkbox" value="警報"> 警報</label> <label><input type="checkbox" value="通知"> 通知</label>
                        <label><input type="checkbox" value="重大"> 重大</label> <label><input type="checkbox" value="情報"> 情報</label>
                        <label><input type="checkbox" value="エラー"> エラー</label> <label><input type="checkbox" value="デバッグ"> デバッグ</label>
                    </div>
                </div>
                <div class="filter-group">
                    <label for="filter-source">ソース:</label>
                    <select id="filter-source"></select>
                </div>
                 <div class="filter-group">
                    <label for="filter-event-id">イベントID (例: 1001, 2002-2004):</label>
                    <input type="text" id="filter-event-id">
                </div>
                 <div class="filter-group">
                    <label for="filter-message">メッセージに含まれる文字列:</label>
                    <input type="text" id="filter-message">
                </div>
                <div class="filter-buttons">
                    <button id="apply-filter-btn">OK</button>
                    <button id="clear-filter-btn">キャンセル</button>
                </div>
            </div>
        </div>
    </div>
    <div id="save-view-dialog" class="dialog-overlay">
        <div class="dialog-content">
            <h4>カスタムビューの保存</h4>
            <p>現在のフィルター設定を新しいビューとして保存します。</p>
            <input type="text" id="view-name-input" placeholder="ビューの名前を入力">
            <div class="filter-buttons">
                <button id="confirm-save-view-btn">保存</button>
                <button id="cancel-save-view-btn">キャンセル</button>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const getEl = id => document.getElementById(id);
            const leftPane = getEl('left-pane'), logListBody = getEl('log-list-body'), logDetailsPane = getEl('log-details-pane'),
                  splitter = getEl('splitter'), logListContainer = getEl('log-list-container'), mainContent = getEl('main-content'), rightPane = getEl('right-pane'),
                  currentViewTitle = getEl('current-view-title'), customViewsList = getEl('custom-views-list'), logSourcesList = getEl('log-sources-list'),
                  filterTitle = getEl('filter-title'), filterSourceSelect = getEl('filter-source');
            
            const ICONS = {
                '緊急': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#dc3545"/><path d="M5.35 5.35l5.3 5.3m-5.3 0l5.3-5.3" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>`,
                '警報': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#dc3545"/><path d="M5.35 5.35l5.3 5.3m-5.3 0l5.3-5.3" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>`,
                '重大': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#dc3545"/><path d="M5.35 5.35l5.3 5.3m-5.3 0l5.3-5.3" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>`,
                'エラー': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#dc3545"/><path d="M5.35 5.35l5.3 5.3m-5.3 0l5.3-5.3" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>`,
                '警告': `<svg class="level-icon" viewBox="0 0 16 16"><path d="M.93 14h14.14L8 1.5.93 14z" fill="#ffc107"/><path d="M7.25 6h1.5v4h-1.5zm0 5h1.5v1.5h-1.5z" fill="#000"/></svg>`,
                '通知': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#0d6efd"/><text x="8" y="12" font-size="10" fill="#fff" text-anchor="middle" font-weight="bold">i</text></svg>`,
                '情報': `<svg class="level-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#0d6efd"/><text x="8" y="12" font-size="10" fill="#fff" text-anchor="middle" font-weight="bold">i</text></svg>`,
                'デバッグ': `<svg class="level-icon" viewBox="0 0 16 16" fill="#6c757d"><path d="M12 9H4v5h8V9zM5 10h1v1H5zm2 0h1v1H7z"/><path d="M10 2a1 1 0 00-1 1v1H7V3a1 1 0 00-2 0v1H4v2h1v1H4v1h1v1H4v1h1.5l.5.5.5-.5H8v-1H7v-1h1V8h1v1h-1v1H8v1h1.5l.5.5.5-.5H12V8h-1V7h1V5h-1V4h-1V3a1 1 0 00-1-1z" /></svg>`,
            };
            
            let currentFilterContext = { type: 'source', value: 'All' };
            let allLogSources = [];
            let contextMenu = null;

            const apiCall = async (action, body) => {
                const formData = new FormData();
                formData.append('action', action);
                if (body) for (const key in body) formData.append(key, body[key]);
                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    if (!response.ok) throw new Error(`サーバーエラー: ${response.status}`);
                    return await response.json();
                } catch(e) { console.error(e); alert('API通信に失敗しました。'); return {success: false}; }
            };

            const fetchAndRenderViews = async () => {
                const result = await apiCall('get_custom_views');
                if(!result.success) return;
                customViewsList.innerHTML = '';
                Object.entries(result.views).forEach(([viewName, filters]) => {
                    const item = document.createElement('div');
                    item.className = 'tree-item';
                    item.textContent = viewName;
                    item.dataset.viewName = viewName;
                    item.dataset.filters = JSON.stringify(filters);
                    item.addEventListener('click', () => {
                        setActiveItem(item);
                        currentFilterContext = { type: 'custom_view', value: viewName, filters };
                        currentViewTitle.textContent = `カスタムビュー: ${viewName}`;
                        filterTitle.textContent = viewName;
                        populateFilterPane(filters);
                        fetchAndRenderLogs();
                    });
                    item.addEventListener('contextmenu', (e) => { e.preventDefault(); e.stopPropagation(); showContextMenu(e, item); });
                    customViewsList.appendChild(item);
                });
            };

            const fetchAndRenderSources = async () => {
                const result = await apiCall('get_log_sources');
                if(!result.success) return;
                allLogSources = result.log_sources;
                logSourcesList.innerHTML = '';

                const createSourceItem = (sourceName, text) => {
                    const item = document.createElement('div');
                    item.className = 'tree-item';
                    item.textContent = text;
                    item.dataset.sourceName = sourceName;
                    item.addEventListener('click', () => {
                        setActiveItem(item);
                        currentFilterContext = { type: 'source', value: sourceName };
                        currentViewTitle.textContent = text;
                        filterTitle.textContent = text;
                        let initialFilters = sourceName === 'All' ? {} : { source: sourceName };
                        populateFilterPane(initialFilters);
                        fetchAndRenderLogs();
                    });
                    item.addEventListener('contextmenu', (e) => { e.preventDefault(); e.stopPropagation(); showContextMenu(e, item);});
                    return item;
                };

                logSourcesList.appendChild(createSourceItem('All', "すべてのログ"));
                allLogSources.forEach(source => logSourcesList.appendChild(createSourceItem(source, source)));
                updateFilterSourceDropdown();
            };
            
            const updateFilterSourceDropdown = () => {
                filterSourceSelect.innerHTML = '<option value="All">すべてのソース</option>';
                allLogSources.forEach(source => {
                    const option = document.createElement('option');
                    option.value = source;
                    option.textContent = source;
                    filterSourceSelect.appendChild(option);
                });
            };

            const setActiveItem = (item) => {
                document.querySelectorAll('.tree-item.active').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
            };
            
            const fetchAndRenderLogs = async () => {
                logListBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">読み込み中...</td></tr>';
                const filters = getFiltersFromPane();
                const logResponse = await apiCall('get_logs', { filters: JSON.stringify(filters) });
                if (logResponse.success) {
                    renderLogs(logResponse.logs);
                } else {
                     logListBody.innerHTML = `<tr><td colspan="5" style="text-align:center;">ログの読み込みに失敗しました。</td></tr>`;
                }
            };

            const renderLogs = (logs) => {
                logListBody.innerHTML = '';
                logDetailsPane.innerHTML = '<div style="padding:10px; color:#666;">ログエントリを選択して詳細を表示します。</div>';
                if (!logs || logs.length === 0) {
                    logListBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">ログエントリはありません。</td></tr>';
                    return;
                }
                logs.forEach(log => {
                    const row = document.createElement('tr');
                    const levelName = log.level_name || '情報';
                    const icon = ICONS[levelName] || ICONS['情報'];
                    row.innerHTML = `<td><div class="level-cell">${icon} ${escapeHtml(levelName)}</div></td>
                                     <td>${new Date(log.timestamp * 1000).toLocaleString('ja-JP', {year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false})}</td>
                                     <td>${escapeHtml(log.source || '')}</td><td>${log.event_id || ''}</td><td>${escapeHtml(log.category || '')}</td>`;
                    row.addEventListener('click', () => {
                        document.querySelectorAll('.log-table tr.selected').forEach(r => r.classList.remove('selected'));
                        row.classList.add('selected');
                        renderLogDetails(log);
                    });
                    logListBody.appendChild(row);
                });
            };

            const renderLogDetails = (log) => {
                logDetailsPane.innerHTML = `<div class="detail-group"><p class="detail-label">全般</p><div class="detail-content">
                                            ${escapeHtml(log.message)}<br><br>
                                            ログ名: ${escapeHtml(log.source)}<br>ソース: ${escapeHtml(log.source)}<br>
                                            イベントID: ${escapeHtml(log.event_id)}<br>レベル: ${escapeHtml(log.level_name)}<br>
                                            タスクのカテゴリ: ${escapeHtml(log.category)}<br>キーワード: (0)</div></div>
                                            <div class="detail-group"><p class="detail-label">詳細</p><div class="detail-content"><pre>${escapeHtml(JSON.stringify(log.details, null, 2))}</pre></div></div>`;
            };

            const getFiltersFromPane = () => {
                const startDate = getEl('filter-start-date').value;
                const endDate = getEl('filter-end-date').value;
                return {
                    start_date: startDate, end_date: endDate,
                    levels: Array.from(document.querySelectorAll('#filter-levels input:checked')).map(cb => cb.value),
                    event_id: getEl('filter-event-id').value.trim(),
                    source: filterSourceSelect.value,
                    message: getEl('filter-message').value,
                };
            };
            
            const populateFilterPane = (filters) => {
                getEl('filter-start-date').value = filters.start_date ? filters.start_date.slice(0, 16) : '';
                getEl('filter-end-date').value = filters.end_date ? filters.end_date.slice(0, 16) : '';
                document.querySelectorAll('#filter-levels input').forEach(cb => cb.checked = (filters.levels || []).includes(cb.value));
                getEl('filter-event-id').value = filters.event_id || '';
                filterSourceSelect.value = filters.source || 'All';
                getEl('filter-message').value = filters.message || '';
            };

            getEl('filter-pane-toggle').addEventListener('click', () => {
                mainContent.classList.toggle('has-right-pane');
                rightPane.classList.toggle('visible');
            });
            getEl('apply-filter-btn').addEventListener('click', () => {
                fetchAndRenderLogs();
                rightPane.classList.remove('visible');
                mainContent.classList.remove('has-right-pane');
            });
            getEl('clear-filter-btn').addEventListener('click', () => { 
                rightPane.classList.remove('visible');
                mainContent.classList.remove('has-right-pane');
            });

            const saveViewDialog = getEl('save-view-dialog');
            getEl('save-view-btn').addEventListener('click', () => { saveViewDialog.style.display = 'flex'; });
            getEl('cancel-save-view-btn').addEventListener('click', () => { saveViewDialog.style.display = 'none'; });
            getEl('confirm-save-view-btn').addEventListener('click', async () => {
                const viewName = getEl('view-name-input').value.trim();
                const oldViewName = currentFilterContext.type === 'custom_view' ? currentFilterContext.value : null;
                if(!viewName) { alert('ビュー名を入力してください。'); return; }
                const result = await apiCall('save_custom_view', { name: viewName, old_name: oldViewName, filters: JSON.stringify(getFiltersFromPane()) });
                if(result.success) {
                    saveViewDialog.style.display = 'none'; getEl('view-name-input').value = '';
                    fetchAndRenderViews();
                } else { alert(`保存に失敗しました: ${result.message}`); }
            });

            getEl('clear-log-btn').addEventListener('click', async () => {
                 if(confirm(`表示中のログ「${currentFilterContext.value}」を完全に消去しますか？この操作は元に戻せません。`)){
                    const result = await apiCall('clear_log', {log_source: currentFilterContext.value});
                    if(result.success) {
                        fetchAndRenderLogs();
                        if (currentFilterContext.value !== 'All') {
                             fetchAndRenderSources();
                        }
                    } else { alert(`ログの消去に失敗しました: ${result.message}`); }
                 }
            });
            
            const showContextMenu = (e, item) => {
                 hideContextMenu();
                 let menuHtml = '';
                 if(item.dataset.viewName) {
                     menuHtml = `<div class="context-menu-item" data-action="rename_view">名前の変更</div>
                                 <div class="context-menu-item" data-action="delete_view">削除</div>`;
                 } else if (item.dataset.sourceName) {
                      menuHtml = `<div class="context-menu-item" data-action="clear_log">ログの消去</div>`;
                 }
                 if(!menuHtml) return;

                 contextMenu = document.createElement('div');
                 contextMenu.className = 'context-menu';
                 contextMenu.innerHTML = menuHtml;
                 document.body.appendChild(contextMenu);
                 contextMenu.style.left = `${e.clientX}px`;
                 contextMenu.style.top = `${e.clientY}px`;
                 
                 const clickHandler = async (evt) => {
                     const actionItem = evt.target.closest('.context-menu-item');
                     if(actionItem) {
                         const action = actionItem.dataset.action;
                         if (action === 'rename_view') {
                             const newName = prompt('新しいビュー名を入力してください:', item.dataset.viewName);
                             if(newName && newName.trim() !== ''){
                                 const filters = JSON.parse(item.dataset.filters);
                                 await apiCall('save_custom_view', {name: newName.trim(), old_name: item.dataset.viewName, filters: JSON.stringify(filters) });
                                 fetchAndRenderViews();
                             }
                         } else if (action === 'delete_view') {
                             if(confirm(`カスタムビュー「${item.dataset.viewName}」を削除しますか？`)){
                                 await apiCall('delete_custom_view', {name: item.dataset.viewName});
                                 fetchAndRenderViews();
                             }
                         } else if (action === 'clear_log') {
                             if(confirm(`ログ「${item.dataset.sourceName}」を完全に消去しますか？この操作は元に戻せません。`)){
                                 await apiCall('clear_log', {log_source: item.dataset.sourceName});
                                 fetchAndRenderLogs();
                                 if (item.dataset.sourceName !== 'All') fetchAndRenderSources();
                             }
                         }
                     }
                     hideContextMenu();
                 };
                 setTimeout(() => document.addEventListener('click', clickHandler, { once: true }), 0);
            };
            
            const hideContextMenu = () => { if(contextMenu) { contextMenu.remove(); contextMenu = null; } };
            document.addEventListener('click', (e) => { if(contextMenu && !contextMenu.contains(e.target)) hideContextMenu(); });

            const escapeHtml = (unsafe) => {
                if (typeof unsafe !== 'string') {
                    if (unsafe === null || unsafe === undefined) return '';
                    try { return String(unsafe); } catch (e) { return ''; }
                }
                return unsafe.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]);
            };
            
            splitter.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const splitterParent = splitter.parentElement;
                const startY = e.clientY;
                const startHeight = logListContainer.offsetHeight;
                
                const doDrag = (e) => {
                    const newHeight = startHeight + (e.clientY - startY);
                    const parentHeight = splitterParent.offsetHeight;
                    if (newHeight > 50 && newHeight < parentHeight - 50) {
                        logListContainer.style.height = `${newHeight}px`;
                    }
                };
                const stopDrag = () => { document.removeEventListener('mousemove', doDrag); document.removeEventListener('mouseup', stopDrag); };
                document.addEventListener('mousemove', doDrag); document.addEventListener('mouseup', stopDrag);
            });
            
            const init = async () => {
                await Promise.all([fetchAndRenderViews(), fetchAndRenderSources()]);
                const firstLogSource = logSourcesList.querySelector('.tree-item');
                if(firstLogSource) firstLogSource.click(); else fetchAndRenderLogs();
            };
            
            const myWindowIdForMessaging = window.name;
            document.addEventListener('keydown', (e) => {
                if(e.altKey && (e.key.toLowerCase() === 'w' || ['ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key))) {
                    try {
                        window.parent.postMessage({ type: 'forwardedKeydown', key: e.key, altKey: e.altKey, ctrlKey: e.ctrlKey, shiftKey: e.shiftKey, metaKey: e.metaKey, windowId: myWindowIdForMessaging }, '*');
                         e.preventDefault();
                    } catch(err) { console.error("Failed to forward keydown event to parent:", err); }
                }
            }, true);
            
            init();
        });
    </script>
</body>
</html>