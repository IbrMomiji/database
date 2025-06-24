<?php

class ExitCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        return [
            'output' => '',
            'clear' => false,
            'action' => [
                'type' => 'close_window'
            ]
        ];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "現在のウィンドウを閉じます。";
    }

    public function getUsage(): string
    {
        return "usage: exit\n\n説明:\n  現在作業しているコンソールウィンドウを閉じます。";
    }
}