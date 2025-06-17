<?php // system/commands/guest/WhoamiCommand.php

class WhoamiCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        return ['output' => $auth->whoami(), 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "現在ログインしているユーザーを表示します。";
    }

    public function getUsage(): string
    {
        return "usage: whoami\n\n説明:\n  現在ログインしているユーザー名を表示します。ログインしていない場合はその旨を表示します。";
    }
}