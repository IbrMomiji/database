<?php // system/commands/login/DeleteAccountCommand.php

class DeleteAccountCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        // handleInteractionから渡された入力を処理
        if ($interactionState && $interactionState['type'] === 'delete_account' && isset($args['input'])) {
            if (strtolower($args['input']) === 'yes') {
                $result = $auth->deleteAccount();
                $output = $result['message'];
            } else {
                $output = "アカウントの削除を中止しました。";
            }
            $interactionState = null; // 対話モード終了
            return ['output' => $output, 'clear' => false];
        }

        // 対話モードを開始
        $interactionState = ['type' => 'delete_account', 'step' => 'confirm'];
        return [
            'output' => "本当にアカウントを削除しますか？ この操作は取り消せません。<br>よろしい場合は 'yes' と入力してください: ",
            'clear' => false,
            'input_type' => 'text',
            'interactive_final' => true,
        ];
    }
    // ... getDescription, getUsage, getArgumentDefinition は変更なし ...
    public function getArgumentDefinition(): array { return []; }
    public function getDescription(): string { return "現在のアカウントを完全に削除します。"; }
    public function getUsage(): string { return "usage: delete-account\n\n説明:\n  現在ログインしているユーザーアカウントをデータベースとファイルシステムから完全に削除します。この操作は取り消せません。実行すると確認メッセージが表示されます。"; }
}