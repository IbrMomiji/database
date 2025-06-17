<?php // system/commands/login/LogoutCommand.php

class LogoutCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        return ['output' => $auth->logout(), 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "現在のアカウントからログアウトします。";
    }

    public function getUsage(): string
    {
        return "usage: logout\n\n説明:\n  現在のセッションを終了し、ログアウトします。引数は不要です。";
    }
}