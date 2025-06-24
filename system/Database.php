<?php
class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        try {
            if (!is_dir(dirname(DB_PATH))) {
                mkdir(dirname(DB_PATH), 0775, true);
            }
            if (!is_writable(dirname(DB_PATH))) {
                throw new Exception("権限エラー: Webサーバーが 'db' ディレクトリに書き込めません。");
            }
            if (!in_array('sqlite', PDO::getAvailableDrivers())) {
                throw new Exception("PHP設定エラー: SQLite PDOドライバが有効になっていません。");
            }

            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            $this->initSchema();
            
        } catch (PDOException $e) {
            throw new Exception("データベース接続に失敗しました: " . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->pdo;
    }

    private function initSchema()
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            uuid TEXT NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            share_id TEXT NOT NULL UNIQUE,
            owner_user_id INTEGER NOT NULL,
            source_path TEXT NOT NULL,
            share_type TEXT NOT NULL CHECK(share_type IN ('public', 'private')) DEFAULT 'public',
            password_hash TEXT DEFAULT NULL,
            expires_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE
        );");
/*
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS share_recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            share_id INTEGER NOT NULL,
            recipient_user_id INTEGER NOT NULL,
            FOREIGN KEY (share_id) REFERENCES shares (id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE CASCADE,
            UNIQUE (share_id, recipient_user_id)
        );");
*/
    }

    private function __clone() {}

    public function __wakeup() {}
}