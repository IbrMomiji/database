<?php // system/commands/guest/ClearCommand.php

class ClearCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $_SESSION['history'] = [];
        return ['output' => '', 'clear' => true];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "コンソール画面をきれいにします。";
    }

    public function getUsage(): string
    {
        return "usage: clear\n\n説明:\n  コンソール画面の表示履歴を全て消去します。";
    }
}