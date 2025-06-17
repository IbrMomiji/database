<?php
// system/commands/login/account/ExitCommand.php

class ExitCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        // アカウントモードを終了し、通常の対話状態に戻す
        $interactionState = null; 
        return ['output' => "アカウント管理モードを終了しました。", 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }
    public function getDescription(): string
    {
        return "アカウント管理モードを終了します。";
    }
    public function getUsage(): string
    {
        return "usage: exit\n\n説明:\n  アカウント管理モードを終了し、通常のプロンプトに戻ります。";
    }
}