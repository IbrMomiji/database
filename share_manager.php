<?php
date_default_timezone_set('Asia/Tokyo');
require_once __DIR__ . '/system/boot.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    die('アクセス権がありません。ログインしてください。');
}
$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_share') {
    header('Content-Type: application/json; charset=utf-8');
    $share_id = $_POST['share_id'] ?? '';
    if (empty($share_id)) {
        echo json_encode(['success' => false, 'message' => '共有IDが指定されていません。']);
        exit;
    }
    try {
        $stmt = $db->prepare("DELETE FROM shares WHERE share_id = :share_id AND owner_user_id = :owner_user_id");
        $stmt->execute([':share_id' => $share_id, ':owner_user_id' => $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '共有を停止しました。']);
        } else {
            echo json_encode(['success' => false, 'message' => '共有の停止に失敗したか、権限がありません。']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
    }
    exit;
}

$stmt = $db->prepare("SELECT * FROM shares WHERE owner_user_id = :owner_user_id ORDER BY created_at DESC");
$stmt->execute([':owner_user_id' => $_SESSION['user_id']]);
$shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>共有の管理</title>
    <style>
        body { font-family: 'Yu Gothic UI', sans-serif; background: #ffffff; color: #000000; padding: 20px; font-size: 14px; }
        body::-webkit-scrollbar { width: 16px; }
        body::-webkit-scrollbar-track { background: #cccccc; }
        body::-webkit-scrollbar-thumb { background: #666666; border: 1px solid #444444; }
        .container { max-width: 800px; margin: auto; }
        h2 { border-bottom: 1px solid #cccccc; padding-bottom: 10px; font-weight: normal; }
        table { width: 100%; margin-top: 10px; border-collapse: collapse; }
        th, td { padding: 8px; border: 1px solid #cccccc; text-align: left; word-break: break-all; }
        th { background-color: #f0f0f0; }
        .stop-share-btn { font-size: 12px; padding: 4px 8px; background-color: #e11f1f; color: white; border:1px solid #c00c00; border-radius:4px; cursor:pointer;}
        .stop-share-btn:hover { background-color: #c00c00; }
        .message { padding: 10px; border-radius: 4px; margin-bottom: 15px; display: none; }
        .message.success { background: #dff0d8; color: #3c763d; border: 1px solid #d6e9c6; }
        .message.error { background: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
    </style>
</head>
<body>
    <div class="container">
        <h2>共有の管理</h2>
        <div id="message-area" class="message"></div>
        <table>
            <thead>
                <tr><th>アイテムパス</th><th>共有方法</th><th>パスワード</th><th>有効期限</th><th>操作</th></tr>
            </thead>
            <tbody id="manage-shares-list">
                <?php if (empty($shares)): ?>
                    <tr><td colspan="5" style="text-align: center;">共有中のアイテムはありません。</td></tr>
                <?php else: ?>
                    <?php foreach ($shares as $share): ?>
                    <tr data-share-id="<?php echo htmlspecialchars($share['share_id']); ?>">
                        <td><?php echo htmlspecialchars($share['source_path']); ?></td>
                        <td><?php echo $share['share_type'] === 'public' ? '全員' : 'プライベート'; ?></td>
                        <td><?php echo $share['password_hash'] ? 'あり' : 'なし'; ?></td>
                        <td><?php echo $share['expires_at'] ? htmlspecialchars($share['expires_at']) : '無期限'; ?></td>
                        <td><button class="stop-share-btn" data-share-id="<?php echo htmlspecialchars($share['share_id']); ?>">停止</button></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
        document.getElementById('manage-shares-list').addEventListener('click', async (e) => {
            if (e.target.classList.contains('stop-share-btn')) {
                const shareId = e.target.dataset.shareId;
                if (!shareId || !confirm(`この共有を停止しますか？`)) return;

                const formData = new FormData();
                formData.append('action', 'delete_share');
                formData.append('share_id', shareId);

                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    const result = await response.json();
                    const msgArea = document.getElementById('message-area');
                    msgArea.textContent = result.message;
                    msgArea.className = `message ${result.success ? 'success' : 'error'}`;
                    msgArea.style.display = 'block';

                    if (result.success) {
                        e.target.closest('tr').remove();
                    }
                } catch (err) {
                    alert('エラーが発生しました: ' + err.message);
                }
            }
        });
    </script>
</body>
</html>