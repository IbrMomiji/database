<?php
class DeleteCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $step = $interactionState['step'] ?? 'start';
        $input = $args['input'] ?? null;

        if ($step === 'start') {
            $interactionState['type'] = 'delete';
            $interactionState['step'] = 'confirm';
            return [
                'output' => "本当にアカウントを削除しますか？ この操作は取り消せません。\nよろしい場合は 'yes' と入力してください:",
                'interactive_final' => true
            ];
        }

        if ($step === 'confirm') {
            if (strtolower($input) === 'yes') {
                $result = $auth->deleteAccount();
                $interactionState = null; 
                return ['output' => $result['message'], 'clear' => false];
            } else {
                $interactionState = ['mode' => 'account'];
                return ['output' => "アカウントの削除を中止しました。", 'clear' => false];
            }
        }
        
        // In case of an unexpected state, reset
        $interactionState = ['mode' => 'account'];
        return ['output' => "不明なエラーが発生しました。", 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }
    public function getDescription(): string
    {
        return "現在のアカウントを完全に削除します。";
    }
    public function getUsage(): string
    {
        return "usage: delete\n\n説明:\n  現在ログインしているユーザーアカウントをデータベースとファイルシステムから完全に削除します。実行すると確認メッセージが表示されます。";
    }
}
