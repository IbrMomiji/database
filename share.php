<?php
date_default_timezone_set('Asia/Tokyo');
require_once __DIR__ . '/system/boot.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'], $_SESSION['user_uuid'])) {
    die('アクセス権がありません。ログインしてください。');
}

$db = Database::getInstance()->getConnection();
$user_dir = USER_DIR_PATH . '/' . $_SESSION['user_uuid'];

$item_path_req = $_GET['item_path'] ?? null;
if (!$item_path_req) {
    die('共有するアイテムが指定されていません。');
}

// ... （getSafePath_Share関数の定義は省略。必要であれば追加してください）
$item_name = basename($item_path_req);

$stmt_get = $db->prepare("SELECT * FROM shares WHERE owner_user_id = :owner_user_id AND source_path = :source_path");
$stmt_get->execute([':owner_user_id' => $_SESSION['user_id'], ':source_path' => $item_path_req]);
$existing_share = $stmt_get->fetch(PDO::FETCH_ASSOC);

if ($existing_share) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $base_uri = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $existing_share['url'] = $protocol . $host . $base_uri . "/share.php?id=" . $existing_share['share_id'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>共有: <?php echo htmlspecialchars($item_name); ?></title>
    <style>
        body { font-family: 'Yu Gothic UI', sans-serif; background: #ffffff; color: #000000; padding: 20px; font-size: 14px; }
        body::-webkit-scrollbar { width: 16px; }
        body::-webkit-scrollbar-track { background: #cccccc; }
        body::-webkit-scrollbar-thumb { background: #666666; border: 1px solid #444444; }
        .container { max-width: 600px; margin: auto; }
        h2 { border-bottom: 1px solid #cccccc; padding-bottom: 10px; font-weight: normal; }
        label, p { display: block; margin: 15px 0 5px; }
        input[type="text"], input[type="password"], input[type="datetime-local"] { width: 100%; padding: 8px; background-color: #f0f0f0; border: 1px solid #cccccc; color: #000000; border-radius: 4px; box-sizing: border-box; font-family: inherit; }
        .footer-buttons { margin-top: 20px; display: flex; justify-content: space-between; }
        button { padding: 8px 12px; border-radius: 4px; border: 1px solid #adadad; cursor: pointer; background-color: #f0f0f0; color: black; font-size: 14px; }
        button:hover { background-color: #e5f3ff; border-color: #0078d7; }
        button#stop-share-btn { background-color: #e11f1f; color: white; border-color: #c00c00; }
        button#stop-share-btn:hover { background-color: #c00c00; }
        .message { padding: 10px; border-radius: 4px; margin-top: 15px; display: none; }
        .message.success { background: #dff0d8; color: #3c763d; border: 1px solid #d6e9c6; }
        .message.error { background: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
    </style>
</head>
<body>
    <div class="container">
        <h2>「<?php echo htmlspecialchars($item_name); ?>」を共有</h2>
        <p>共有設定:</p>
        <div>
            <input type="radio" id="share-type-public" name="share-type" value="public" <?php echo (!$existing_share || $existing_share['share_type'] === 'public') ? 'checked' : ''; ?>>
            <label for="share-type-public" style="display: inline-block;">リンクを知っている全員</label>
        </div>
        <div>
            <label for="share-password">パスワード (変更する場合のみ入力):</label>
            <input type="password" id="share-password" autocomplete="new-password" placeholder="<?php echo $existing_share && $existing_share['password_hash'] ? 'パスワード設定済み' : '任意'; ?>">
        </div>
        <div>
            <label for="share-expires">有効期限 (任意):</label>
            <input type="datetime-local" id="share-expires" value="<?php echo $existing_share && $existing_share['expires_at'] ? str_replace(' ', 'T', $existing_share['expires_at']) : ''; ?>">
        </div>
        <hr style="margin: 20px 0; border-color: #cccccc;">
        <p>生成されたリンク:</p>
        <input type="text" id="share-link-input" value="<?php echo htmlspecialchars($existing_share['url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly>
        <div id="message-area" class="message"></div>
        <div class="footer-buttons">
          <button id="stop-share-btn" <?php echo !$existing_share ? 'style="display:none;"' : ''; ?>>共有を停止</button>
          <button id="create-share-btn">リンクを作成・更新</button>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const getEl = id => document.getElementById(id);
            const itemPath = new URLSearchParams(window.location.search).get('item_path');

            const handleAction = (action) => {
                const formData = {
                    item_path: itemPath,
                    share_type: document.querySelector('input[name="share-type"]:checked').value,
                    password: getEl('share-password').value,
                    expires_at: getEl('share-expires').value
                };
                window.parent.postMessage({ type: 'submitShareForm', action: action, formData: formData }, '*');
            };

            window.addEventListener('message', (event) => {
                if (event.data.type === 'shareFormResult') {
                    const result = event.data.result;
                    const messageArea = getEl('message-area');
                    messageArea.textContent = result.message;
                    messageArea.className = `message ${result.success ? 'success' : 'error'}`;
                    messageArea.style.display = 'block';

                    if (result.success) {
                        if (event.data.action === 'create_share') {
                            getEl('share-link-input').value = result.url;
                            getEl('share-password').placeholder = result.password_hash ? 'パスワード設定済み' : '任意';
                            getEl('share-password').value = '';
                            getEl('stop-share-btn').style.display = 'inline-block';
                        } else if (event.data.action === 'stop_share') {
                            getEl('share-link-input').value = '';
                            getEl('stop-share-btn').style.display = 'none';
                            getEl('share-password').placeholder = '任意';
                            getEl('share-password').value = '';
                            getEl('share-expires').value = '';
                        }
                    }
                }
            });

            getEl('create-share-btn').addEventListener('click', () => handleAction('create_share'));
            getEl('stop-share-btn').addEventListener('click', () => {
                if (confirm('この共有を停止しますか？')) {
                    handleAction('stop_share');
                }
            });
        });
    </script>
</body>
</html>