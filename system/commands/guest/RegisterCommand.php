<?php // system/commands/guest/RegisterCommand.php

class RegisterCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        // 対話モードの処理
        if ($interactionState && $interactionState['type'] === 'register') {
            if ($interactionState['step'] === 'get_username') {
                $interactionState['username'] = $args['input'];
                $interactionState['step'] = 'get_password';
                return [
                    'output' => '', 'clear' => false,
                    'prompt_text' => 'パスワードを入力してください: ',
                    'input_type' => 'password',
                    'interactive_final' => true,
                ];
            }
            if ($interactionState['step'] === 'get_password') {
                $username = $interactionState['username'];
                $password = $args['input'];
                $result = $auth->register($username, $password);
                $interactionState = null; // 対話モード終了
                return ['output' => $result['message'], 'clear' => false];
            }
        }

        // 引数で直接指定された場合の処理
         if (!empty($args['u']) && !empty($args['p'])) {
            $result = $auth->register($args['u'], $args['p']);
            return ['output' => $result['message'], 'clear' => false];
        } else {
            // 対話モードを開始
            $interactionState = ['type' => 'register', 'step' => 'get_username'];
            return [
                'output' => '', 'clear' => false,
                'prompt_text' => '登録するユーザー名を入力してください: ',
                'input_type' => 'text',
                'interactive_final' => true,
            ];
        }
    }
    // ... getDescription, getUsage, getArgumentDefinition は変更なし ...
    public function getArgumentDefinition(): array { return ['u', 'p']; }
    public function getDescription(): string { return "新しいユーザーアカウントを作成します。"; }
    public function getUsage(): string { return "usage: register [-u ユーザー名] [-p パスワード]\n\n説明:\n  新しいユーザーアカウントを作成します。\n  引数を省略した場合、対話形式でユーザー名とパスワードを求められます。"; }
}