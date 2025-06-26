<?php

require_once __DIR__ . '/MessagePackPacker.php';

class Logger
{
    const DEBUG     = 7;
    const INFO      = 6;
    const NOTICE    = 5;
    const WARNING   = 4;
    const ERROR     = 3;
    const CRITICAL  = 2;
    const ALERT     = 1;
    const EMERGENCY = 0;

    private static $level_map = [
        self::EMERGENCY => '緊急',
        self::ALERT     => '警報',
        self::CRITICAL  => '重大',
        self::ERROR     => 'エラー',
        self::WARNING   => '警告',
        self::NOTICE    => '通知',
        self::INFO      => '情報',
        self::DEBUG     => 'デバッグ',
    ];

    public static function log(
        string $user_uuid,
        int $event_id,
        string $source,
        string $category,
        int $level,
        string $message,
        array $details = []
    ): void
    {
        if (empty($user_uuid) || empty($source)) {
            return;
        }

        $logDir = USER_DIR_PATH . '/' . $user_uuid . '/.logs';
        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, 0775, true)) {
                error_log("ログディレクトリの作成に失敗しました: " . $logDir);
                return;
            }
        }
        
        $sanitized_source = preg_replace('/[^a-zA-Z0-9_-]/', '', $source);
        $logFile = $logDir . '/' . $sanitized_source . '.log';

        $logEntry = [
            'timestamp'     => time(),
            'level_code'    => $level,
            'level_name'    => self::$level_map[$level] ?? '不明',
            'event_id'      => $event_id,
            'source'        => $source,
            'category'      => $category,
            'message'       => $message,
            'details'       => $details
        ];

        try {
            $packer = new MessagePackPacker();
            $packedData = $packer->pack($logEntry);
            file_put_contents($logFile, $packedData . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log("ユーザー{$user_uuid}のログ記録に失敗しました: " . $e->getMessage());
        }
    }
}