<?php
class DeleteCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $step = $interactionState['step'] ?? 'start';
        $input = $args['input'] ?? null;

        switch ($step) {
            case 'start':
                $interactionState['type'] = 'delete';
                $interactionState['step'] = 'confirm_delete';
                return [
                    'output' => '本当にアカウントを削除しますか？この操作は元に戻せません。(yes/no):',
                    'interactive_final' => true
                ];

            case 'confirm_delete':
                if (strtolower($input) !== 'yes') {
                    $interactionState = ['mode' => 'account'];
                    return ['output' => 'アカウントの削除をキャンセルしました。', 'clear' => false];
                }
                $interactionState['step'] = 'get_password';
                return [
                    'output' => '本人確認のため、パスワードを入力してください:',
                    'input_type' => 'password',
                    'interactive_final' => true
                ];

            case 'get_password':
                $password = $input;
                $result = $auth->deleteAccount($password);

                if ($result['success']) {
                    $logoutMessage = $auth->logout();
                    $interactionState = null;
                    return [
                        'output' => $result['message'] . "<br>" . $logoutMessage,
                        'logout' => true
                    ];
                } else {
                    $interactionState = ['mode' => 'account'];
                    return ['output' => 'エラー: ' . $result['message'], 'clear' => false];
                }
        }

        $interactionState = ['mode' => 'account'];
        return ['output' => '不明なエラーが発生しました。', 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "現在のアカウントを削除します。";
    }

    public function getUsage(): string
    {
        return "usage: delete\n\n説明:\n  対話形式で確認の後、アカウントを完全に削除します。この操作は取り消せません。";
    }
}
