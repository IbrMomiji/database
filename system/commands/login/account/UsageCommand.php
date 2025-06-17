<?php
// system/commands/login/account/UsageCommand.php

class UsageCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $usage = $auth->getStorageUsage();
        $used = $this->formatBytes($usage['used']);
        $total = $this->formatBytes($usage['total']);
        $percentage = $usage['total'] > 0 ? round(($usage['used'] / $usage['total']) * 100, 2) : 0;

        $output = "ディスク使用量:\n";
        $output .= "  {$used} / {$total} ({$percentage}%)";
        
        return ['output' => $output, 'clear' => false];
    }

    private function formatBytes($bytes) {
        if ($bytes <= 0) return '0 Bytes';
        $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }
    public function getDescription(): string
    {
        return "現在のディスク使用量を表示します。";
    }
    public function getUsage(): string
    {
        return "usage: usage\n\n説明:\n  現在のユーザーが使用しているディスク容量と、割り当てられている総容量を表示します。";
    }
}