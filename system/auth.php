<?php
class Auth
{
    private $db;
    const MAX_STORAGE_BYTES = 100 * 1024 * 1024; // 100MB

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function login($username, $password)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            return ['success' => true, 'message' => "ようこそ、" . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . "さん！"];
        } else {
            return ['success' => false, 'message' => "ログイン失敗: ユーザー名またはパスワードが間違っています。"];
        }
    }

    public function register($username, $password)
    {
        if (preg_match('/[\\\\\/:\*\?"<>|.]/', $username) || $username === '..') {
            return ['success' => false, 'message' => "エラー: ユーザー名に無効な文字 (\\ / : * ? \" < > | .) が含まれています。"];
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => "エラー: ユーザー名 '" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "' は既に使用されています。"];
        }

        $this->db->beginTransaction();
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->execute([$username, $hashedPassword]);

            $userSpecificDir = USER_DIR_PATH . '/' . $username;
            if (!is_dir(USER_DIR_PATH)) mkdir(USER_DIR_PATH, 0775, true);
            if (!is_writable(USER_DIR_PATH)) throw new Exception("'user' ディレクトリに書き込み権限がありません。");
            if (!is_dir($userSpecificDir)) mkdir($userSpecificDir, 0775);
            
            $this->db->commit();
            return ['success' => true, 'message' => "ユーザー '" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "' を登録しました。'login'コマンドでログインしてください。"];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("User registration failed for '$username': " . $e->getMessage());
            return ['success' => false, 'message' => "エラー: 登録中にサーバーで問題が発生しました。"];
        }
    }
    
    public function logout()
    {
        if (isset($_SESSION['user_id'])) {
            session_unset();
            session_destroy();
            return "ログアウトしました。";
        } else {
            return "ログインしていません。";
        }
    }

    public function deleteAccount()
    {
        if (!isset($_SESSION['user_id'])) {
            return ['success' => false, 'message' => "エラー: ログインしていません。"];
        }

        $userId = $_SESSION['user_id'];
        $username = $_SESSION['username'];
        $userSpecificDir = USER_DIR_PATH . '/' . $username;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            if (is_dir($userSpecificDir)) {
                $this->deleteDirectoryRecursively($userSpecificDir);
            }

            $this->db->commit();
            $this->logout();
            return ['success' => true, 'message' => "アカウントを削除しました。"];

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Account deletion failed for user ID '$userId' ($username): " . $e->getMessage());
            return ['success' => false, 'message' => "エラー: アカウント削除中にサーバーで問題が発生しました。"];
        }
    }

    public function renameUser($newUsername)
    {
        if (!isset($_SESSION['user_id'])) {
            return ['success' => false, 'message' => "エラー: ログインしていません。"];
        }
        if (preg_match('/[\\\\\/:\*\?"<>|.]/', $newUsername) || $newUsername === '..') {
            return ['success' => false, 'message' => "エラー: 新しいユーザー名に無効な文字が含まれています。"];
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$newUsername]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => "エラー: そのユーザー名は既に使用されています。"];
        }

        $this->db->beginTransaction();
        try {
            $userId = $_SESSION['user_id'];
            $oldUsername = $_SESSION['username'];
            $oldDir = USER_DIR_PATH . '/' . $oldUsername;
            $newDir = USER_DIR_PATH . '/' . $newUsername;

            $stmt = $this->db->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->execute([$newUsername, $userId]);

            if (is_dir($oldDir)) {
                rename($oldDir, $newDir);
            }

            $this->db->commit();
            $this->logout();
            return ['success' => true, 'message' => "ユーザー名を変更しました。再度ログインしてください。"];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => "エラー: ユーザー名の変更中にエラーが発生しました。"];
        }
    }

    public function changePassword($currentPassword, $newPassword)
    {
        if (!isset($_SESSION['user_id'])) {
            return ['success' => false, 'message' => "エラー: ログインしていません。"];
        }

        $userId = $_SESSION['user_id'];
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => "エラー: 現在のパスワードが間違っています。"];
        }

        $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$newHashedPassword, $userId]);

        return ['success' => true, 'message' => "パスワードを変更しました。"];
    }

    public function getStorageUsage()
    {
        if (!isset($_SESSION['username'])) {
            return ['used' => 0, 'total' => self::MAX_STORAGE_BYTES];
        }
        $user_dir = USER_DIR_PATH . '/' . $_SESSION['username'];
        $size = 0;
        if (is_dir($user_dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($user_dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $size += $file->getSize();
            }
        }
        return ['used' => $size, 'total' => self::MAX_STORAGE_BYTES];
    }
    
    private function deleteDirectoryRecursively($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.','..']);
        foreach ($files as $file) {
          (is_dir("$dir/$file")) ? $this->deleteDirectoryRecursively("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
    
    public function getPrompt()
    {
        $interactionState = $_SESSION['interaction_state'] ?? null;

        if ($interactionState) {
            if (isset($interactionState['mode']) && $interactionState['mode'] === 'account') {
                return 'account&gt; ';
            }
            return ''; // 対話モード中はプロンプトなし
        }

        $username = $_SESSION['username'] ?? null;
        return $username ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '@database&gt; ' : 'database&gt; ';
    }
    
    public function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
    
    public function whoami()
    {
        return isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : 'ログインしていません。';
    }

    public function getInitialState()
    {
        $history = ['<div>データベースクライアントへようこそ。</div>', "<div>'help' と入力するとコマンドの一覧を表示します。</div>", '<div><br></div>'];
        
        return [
            'history' => $history,
            'prompt' => 'database&gt; ',
        ];
    }
}
