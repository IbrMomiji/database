<?php
// =================================================================
// 認証・アカウント管理クラス (Auth.php)
//
// - ユーザー登録、ログイン、ログアウト
// - アカウント削除
// - セッション管理
// - 初期状態の取得
// =================================================================

class Auth
{
    private $db;

    public function __construct()
    {
        // データベース接続を取得
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * ユーザーをログインさせる
     * @param string $username
     * @param string $password
     * @return array 処理結果
     */
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

    /**
     * 新規ユーザーを登録する
     * @param string $username
     * @param string $password
     * @return array 処理結果
     */
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

            // ユーザーディレクトリ作成
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
    
    /**
     * ユーザーをログアウトさせる
     * @return string メッセージ
     */
    public function logout()
    {
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
            unset($_SESSION['username']);
            // 対話状態もリセット
            if (isset($_SESSION['interaction_state'])) {
                unset($_SESSION['interaction_state']);
            }
            return "ログアウトしました。";
        } else {
            return "ログインしていません。";
        }
    }

    /**
     * 現在のユーザーを削除する
     * @return array 処理結果
     */
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
            // DBからユーザー削除
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            // ユーザーディレクトリを再帰的に削除
            if (is_dir($userSpecificDir)) {
                $this->deleteDirectoryRecursively($userSpecificDir);
            }

            $this->db->commit();
            $this->logout(); // ログアウト処理を呼び出す
            return ['success' => true, 'message' => "アカウントを削除しました。"];

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Account deletion failed for user ID '$userId' ($username): " . $e->getMessage());
            return ['success' => false, 'message' => "エラー: アカウント削除中にサーバーで問題が発生しました。"];
        }
    }

    /**
     * ディレクトリを再帰的に削除するヘルパー関数
     * @param string $dir
     */
    private function deleteDirectoryRecursively($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.','..']);
        foreach ($files as $file) {
          (is_dir("$dir/$file")) ? $this->deleteDirectoryRecursively("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
    
    /**
     * 現在のプロンプト文字列を取得する
     * @return string プロンプト
     */
    public function getPrompt()
    {
        if (isset($_SESSION['interaction_state'])) {
            return '';
        }
        $username = $_SESSION['username'] ?? null;
        return $username ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '@database&gt; ' : 'database&gt; ';
    }
    
    /**
     * ログインしているかどうかを返す
     * @return bool
     */
    public function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
    
    /**
     * ログインしているユーザー名を返す
     * @return string
     */
    public function whoami()
    {
        return isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : 'ログインしていません。';
    }

    /**
     * ページの初期読み込み時にJavaScriptに渡す状態を取得する
     * @return array
     */
    public function getInitialState()
    {
        // ページロード時にセッションがリセットされているため、historyは常に空
        $history = ['<div>データベースクライアントへようこそ。</div>', "<div>'help' と入力するとコマンドの一覧を表示します。</div>", '<div><br></div>'];
        
        return [
            'history' => $history,
            'prompt' => 'database&gt; ', // 初期プロンプトは常にこれ
        ];
    }
}
