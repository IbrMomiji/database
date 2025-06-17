<?php
// system/commands/login/account/RenameCommand.php

class RenameCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $newName = $args['n'] ?? null;
        if (!$newName) {
            return ['output' => "エラー: 新しいユーザー名を -n オプションで指定してください。", 'clear' => false];
        }

        $result = $auth->renameUser($newName);
        
        // 名前の変更が成功した場合、アカウントモードを終了する
        if ($result['success']) {
            $interactionState = null;
        }

        return ['output' => $result['message'], 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return ['n'];
    }
    public function getDescription(): string
    {
        return "ユーザー名を変更します。";
    }
    public function getUsage(): string
    {
        return "usage: rename -n <新しいユーザー名>\n\n説明:\n  現在のユーザー名を指定された新しい名前に変更します。変更が成功すると、自動的にログアウトします。";
    }
}