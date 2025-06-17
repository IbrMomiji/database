<?php // system/commands/guest/StartCommand.php

class StartCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        return [
            'output' => "新しいコンソールウィンドウを開きます...",
            'clear' => false,
            'action' => 'open_console'
        ];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "新しいコンソールウィンドウを開きます。";
    }

    public function getUsage(): string
    {
        return "usage: start\n\n説明:\n  新しいコンソールウィンドウを現在のデスクトップに開きます。";
    }
}