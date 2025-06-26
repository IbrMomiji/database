<?php

require_once __DIR__ . '/Logger.php';

function get_client_ip()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ip_list[0]);
    }
    elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    }
    return 'UNKNOWN';
}

class Auth
{
    private $pdo;
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    private function validateUsername($username)
    {
        if (empty($username)) return "ユーザー名を入力してください。";
        if (strlen($username) < 3 || strlen($username) > 20) return "ユーザー名は3文字以上20文字以下で設定してください。";
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $username)) return "ユーザー名は英数字とハイフン(-)のみ使用できます。";
        return true;
    }

    private function validatePassword($password)
    {
        if (empty($password)) return "パスワードを入力してください。";
        if (strlen($password) < 8) return "パスワードは最低8文字以上で設定してください。";
        if (!preg_match('/[A-Z]/', $password)) return "パスワードには少なくとも1つの大文字を含めてください。";
        if (!preg_match('/[a-z]/', $password)) return "パスワードには少なくとも1つの小文字を含めてください。";
        if (!preg_match('/[0-9]/', $password)) return "パスワードには少なくとも1つの数字を含めてください。";
        return true;
    }

    private function generateUuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    public function register($username, $password)
    {
        $usernameValidation = $this->validateUsername($username);
        if ($usernameValidation !== true) return ['success' => false, 'message' => $usernameValidation];
        $passwordValidation = $this->validatePassword($password);
        if ($passwordValidation !== true) return ['success' => false, 'message' => $passwordValidation];

        try {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            if ($stmt->fetch()) return ['success' => false, 'message' => 'エラー: そのユーザー名は既に使用されています。'];

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $uuid = $this->generateUuid();
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("INSERT INTO users (username, password, uuid) VALUES (:username, :password, :uuid)");
            $stmt->execute([':username' => $username, ':password' => $hashedPassword, ':uuid' => $uuid]);

            $userDir = USER_DIR_PATH . '/' . $uuid;
            if (!is_dir(USER_DIR_PATH)) mkdir(USER_DIR_PATH, 0775, true);
            if (!is_writable(USER_DIR_PATH)) throw new Exception("'users' ディレクトリに書き込み権限がありません。");
            if (!mkdir($userDir, 0775)) throw new Exception("ユーザーディレクトリの作成に失敗しました。");
            mkdir($userDir . '/.settings', 0775, true);

            $this->pdo->commit();

            Logger::log($uuid, 1001, 'Auth', 'アカウント管理', Logger::INFO, "ユーザー({$username})が正常に登録されました。", ['ip_address' => get_client_ip()]);
            return ['success' => true, 'message' => 'アカウントが正常に作成されました。' . "'login'" . 'コマンドでログインしてください。'];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['success' => false, 'message' => 'エラー: ' . $e->getMessage()];
        }
    }

    public function login($username, $password)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_uuid'] = $user['uuid'];

                    Logger::log($user['uuid'], 2001, 'Auth', '認証', Logger::INFO, "ユーザー({$username})がログインに成功しました。", ['ip_address' => get_client_ip()]);
                    return ['success' => true, 'message' => "ようこそ、" . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . "さん！"];
                } else {
                    Logger::log(($user['uuid'] ?? 'N/A'), 2002, 'Auth', '認証', Logger::WARNING, "ユーザー({$username})がパスワードの入力ミスによりログインに失敗しました。", ['ip_address' => get_client_ip()]);
                    return ['success' => false, 'message' => "ログイン失敗: ユーザー名またはパスワードが間違っています。"];
                }
            } else {
                Logger::log('N/A', 2003, 'Auth', '認証', Logger::WARNING, "存在しないユーザー({$username})へのログイン試行がありました。", ['ip_address' => get_client_ip()]);
                return ['success' => false, 'message' => "ログイン失敗: ユーザー名またはパスワードが間違っています。"];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'データベースエラーが発生しました。'];
        }
    }

    public function logout()
    {
        if (isset($_SESSION['user_id'])) {
            $uuid = $_SESSION['user_uuid'];
            $username = $_SESSION['username'];
            Logger::log($uuid, 2004, 'Auth', '認証', Logger::INFO, "ユーザー({$username})がログアウトしました。", ['ip_address' => get_client_ip()]);
            session_unset();
            session_destroy();
            return "ログアウトしました。";
        } else {
            return "ログインしていません。";
        }
    }

    public function renameUser($newUsername, $password)
    {
        if (!isset($_SESSION['user_id'])) return ['success' => false, 'message' => "エラー: ログインしていません。"];
        $uuid = $_SESSION['user_uuid'];
        $oldUsername = $_SESSION['username'];

        try {
            $stmt = $this->pdo->prepare("SELECT password FROM users WHERE id = :user_id");
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password'])) {
                 Logger::log($uuid, 1002, 'Auth', 'アカウント管理', Logger::WARNING, "パスワード不一致のためユーザー名変更に失敗しました。", ['ip_address' => get_client_ip()]);
                return ['success' => false, 'message' => 'エラー: パスワードが間違っています。'];
            }
        } catch (PDOException $e) { return ['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]; }

        $usernameValidation = $this->validateUsername($newUsername);
        if ($usernameValidation !== true) return ['success' => false, 'message' => $usernameValidation];
        if (strtolower($newUsername) === strtolower($oldUsername)) return ['success' => false, 'message' => 'エラー: 新しいユーザー名が現在のユーザー名と同じです。'];

        try {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->execute([':username' => $newUsername]);
            if ($stmt->fetch()) return ['success' => false, 'message' => 'エラー: そのユーザー名は既に使用されています。'];

            $stmt = $this->pdo->prepare("UPDATE users SET username = :new_username WHERE id = :user_id");
            $stmt->execute([':new_username' => $newUsername, ':user_id' => $_SESSION['user_id']]);
            $_SESSION['username'] = $newUsername;
            
            Logger::log($uuid, 1003, 'Auth', 'アカウント管理', Logger::INFO, "ユーザー名が{$oldUsername}から{$newUsername}に変更されました。", ['ip_address' => get_client_ip()]);
            return ['success' => true, 'message' => 'ユーザー名を変更しました。新しいユーザー名: ' . htmlspecialchars($newUsername, ENT_QUOTES, 'UTF-8'), 'prompt' => $this->getPrompt()];
        } catch (PDOException $e) { return ['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]; }
    }

    public function changePassword($currentPassword, $newPassword)
    {
        if (!isset($_SESSION['user_id'])) return ['success' => false, 'message' => "エラー: ログインしていません。"];
        $uuid = $_SESSION['user_uuid'];
        $username = $_SESSION['username'];

        try {
            $stmt = $this->pdo->prepare("SELECT password FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($currentPassword, $user['password'])) {
                 Logger::log($uuid, 1004, 'Auth', 'アカウント管理', Logger::WARNING, "現在のパスワード不一致のためパスワード変更に失敗しました。", ['ip_address' => get_client_ip()]);
                return ['success' => false, 'message' => 'エラー: 現在のパスワードが間違っています。'];
            }

            $passwordValidation = $this->validatePassword($newPassword);
            if ($passwordValidation !== true) return ['success' => false, 'message' => $passwordValidation];

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("UPDATE users SET password = :password WHERE username = :username");
            $stmt->execute([':password' => $hashedPassword, ':username' => $username]);

            Logger::log($uuid, 1005, 'Auth', 'アカウント管理', Logger::INFO, "パスワードが変更されました。", ['ip_address' => get_client_ip()]);
            return ['success' => true, 'message' => 'パスワードが変更されました。'];
        } catch (PDOException $e) { return ['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]; }
    }

    public function deleteAccount($password)
    {
        if (!isset($_SESSION['user_id'])) return ['success' => false, 'message' => "エラー: ログインしていません。"];
        try {
            $username = $_SESSION['username'];
            $uuid = $_SESSION['user_uuid'];

            $stmt = $this->pdo->prepare("SELECT password FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password'])) {
                return ['success' => false, 'message' => 'パスワードが間違っています。アカウントを削除できませんでした。'];
            }

            Logger::log($uuid, 1006, 'Auth', 'アカウント管理', Logger::CRITICAL, "ユーザー({$username})のアカウントが削除されます。", ['ip_address' => get_client_ip()]);
            $this->pdo->beginTransaction();

            $userDir = USER_DIR_PATH . '/' . $uuid;
            if (is_dir($userDir)) $this->recursiveDelete($userDir);

            $stmt = $this->pdo->prepare("DELETE FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $this->pdo->commit();
            return ['success' => true, 'message' => 'アカウントを削除しました。'];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['success' => false, 'message' => 'エラー: アカウント削除中にサーバーで問題が発生しました。'];
        }
    }

    private function recursiveDelete($dir)
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function getStorageUsage()
    {
        $max_storage_bytes = 100 * 1024 * 1024;
        if (!isset($_SESSION['user_uuid'])) return ['used' => 0, 'total' => $max_storage_bytes];
        $user_dir = USER_DIR_PATH . '/' . $_SESSION['user_uuid'];
        $size = 0;
        if (is_dir($user_dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($user_dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) $size += $file->getSize();
        }
        return ['used' => $size, 'total' => $max_storage_bytes];
    }

    public function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }

    public function getPrompt()
    {
        $interactionState = $_SESSION['interaction_state'] ?? null;
        if ($interactionState) {
            if (isset($interactionState['mode']) && $interactionState['mode'] === 'account') return 'account&gt; ';
            return '';
        }
        $username = $_SESSION['username'] ?? null;
        return $username ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '@database&gt; ' : 'database&gt; ';
    }

    public function whoami()
    {
        return isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : 'ログインしていません。';
    }

    public function getInitialState()
    {
        $history = ['<div>データベースクライアントへようこそ。</div>', "<div>'help' と入力するとコマンドの一覧を表示します。</div>", '<div><br></div>'];
        return ['history' => $history, 'prompt' => 'database&gt; '];
    }
}