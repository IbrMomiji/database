<?php
class UsageCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $usage = $auth->getStorageUsage();
        $used_bytes = $usage['used'];
        $total_bytes = $usage['total'];

        $percentage = ($total_bytes > 0) ? ($used_bytes / $total_bytes) * 100 : 0;

        $output = "ディスク使用量:\n";
        $output .= "  " . $this->formatBytes($used_bytes) . " / " . $this->formatBytes($total_bytes) . "\n";
        $output .= "  " . $this->createProgressBar($percentage);

        return ['output' => $output, 'clear' => false];
    }

    private function formatBytes($bytes, $precision = 2)
    {
        if ($bytes <= 0) return '0 Bytes';
        $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private function createProgressBar($percentage, $width = 40)
    {
        $filled_width = round($width * $percentage / 100);
        $empty_width = $width - $filled_width;

        $bar = '[' . str_repeat('=', $filled_width) . str_repeat(' ', $empty_width) . ']';
        return $bar . ' ' . sprintf('%.2f%%', $percentage);
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
