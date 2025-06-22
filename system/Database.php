<?php
// =================================================================
// データベース管理クラス (Database.php)
//
// - シングルトンパターンでデータベース接続を管理
// - 初期スキーマの作成
// =================================================================

class Database
{
    private static $instance = null;
    private $pdo;

    /**
     * コンストラクタ（privateにして外部からのnewを防ぐ）
     */
    private function __construct()
    {
        try {
            // dbディレクトリが存在しない場合は作成
            if (!is_dir(dirname(DB_PATH))) {
                mkdir(dirname(DB_PATH), 0775, true);
            }
            // dbディレクトリに書き込み権限があるかチェック
            if (!is_writable(dirname(DB_PATH))) {
                throw new Exception("権限エラー: Webサーバーが 'db' ディレクトリに書き込めません。");
            }
            // SQLite PDOドライバが有効かチェック
            if (!in_array('sqlite', PDO::getAvailableDrivers())) {
                throw new Exception("PHP設定エラー: SQLite PDOドライバが有効になっていません。");
            }

            // PDOインスタンスを作成
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // データベースの初期スキーマをセットアップ
            $this->initSchema();
            
        } catch (PDOException $e) {
            throw new Exception("データベース接続に失敗しました: " . $e->getMessage());
        }
    }

    /**
     * 唯一のインスタンスを取得する静的メソッド
     * @return Database
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * PDO接続オブジェクトを取得する
     * @return PDO
     */
    public function getConnection()
    {
        return $this->pdo;
    }

    /**
     * データベーススキーマを初期化する
     */
    private function initSchema()
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            uuid TEXT NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );");
    }

    /**
     * クローンを禁止
     */
    private function __clone() {}

    /**
     * アンシリアライズを禁止
     */
    public function __wakeup() {}
}