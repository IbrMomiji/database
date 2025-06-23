<?php
date_default_timezone_set('Asia/Tokyo');
require_once __DIR__ . '/system/boot.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['user_uuid'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'アクセス権がありません。']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '無効なリクエストです。']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$response = ['success' => false, 'message' => '不明なエラーです。'];
$db = Database::getInstance()->getConnection();

try {
    $action = $_POST['action'] ?? '';
    $item_path = $_POST['item_path'] ?? null;

    if (empty($item_path)) {
        throw new Exception('アイテムパスが指定されていません。');
    }

    $stmt_delete = $db->prepare("DELETE FROM shares WHERE owner_user_id = :owner_user_id AND source_path = :source_path");
    $stmt_delete->execute([':owner_user_id' => $_SESSION['user_id'], ':source_path' => $item_path]);

    if ($action === 'create_share') {
        $share_type = $_POST['share_type'] ?? 'public';
        $password = $_POST['password'] ?? '';
        $expires_at = $_POST['expires_at'] ?? null;

        $share_id = bin2hex(random_bytes(8));
        $password_hash = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;

        $stmt_insert = $db->prepare(
            "INSERT INTO shares (share_id, owner_user_id, source_path, share_type, password_hash, expires_at)
            VALUES (:share_id, :owner_user_id, :source_path, :share_type, :password_hash, :expires_at)"
        );
        $stmt_insert->execute([
            ':share_id' => $share_id,
            ':owner_user_id' => $_SESSION['user_id'],
            ':source_path' => $item_path,
            ':share_type' => $share_type,
            ':password_hash' => $password_hash,
            ':expires_at' => empty($expires_at) ? null : $expires_at
        ]);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $base_uri = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $share_url = $protocol . $host . $base_uri . "/share.php?id=" . $share_id;

        $response = ['success' => true, 'message' => '共有リンクを作成・更新しました。', 'url' => $share_url, 'password_hash' => !empty($password_hash)];
    } else if ($action === 'stop_share') {
        $response = ['success' => true, 'message' => '共有を停止しました。'];
    }

} catch (Exception $e) {
    http_response_code(500);
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
exit;