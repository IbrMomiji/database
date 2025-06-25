<?php

class Logger
{
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';

    public static function log(string $user_uuid, string $type, string $message, string $level = self::INFO, array $details = []): void
    {
        if (empty($user_uuid) || empty($type) || empty($message)) {
            return;
        }

        $logDir = USER_DIR_PATH . '/' . $user_uuid . '/.logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logFile = $logDir . '/' . $type . '.log';

        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level'     => $level,
            'message'   => $message,
            'details'   => $details
        ];

        file_put_contents($logFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}