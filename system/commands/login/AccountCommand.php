<?php
// system/commands/login/AccountCommand.php

class AccountCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        // アカウントモードに移行する
        $interactionState = ['mode' => 'account'];
        $output = "アカウント管理モードに移行しました。\n'help'で利用可能なコマンドを確認できます。";
        return ['output' => $output, 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "アカウント管理モードを開始します。";
    }

    public function getUsage(): string
    {
        return "usage: account\n\n説明:\n  ユーザー名の変更、パスワードの変更など、アカウント情報を管理するための専用モードに移行します。";
    }
}