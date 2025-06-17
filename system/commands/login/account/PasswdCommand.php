<?php
// system/commands/login/account/PasswdCommand.php

class PasswdCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        // 対話モードの各ステップを処理
        $step = $interactionState['step'] ?? 'start';
        $input = $args['input'] ?? null;

        switch ($step) {
            case 'start':
                $interactionState['step'] = 'get_current_password';
                return ['output' => "現在のパスワードを入力してください:", 'input_type' => 'password', 'interactive_final' => true];

            case 'get_current_password':
                $interactionState['current_password'] = $input;
                $interactionState['step'] = 'get_new_password';
                return ['output' => "新しいパスワードを入力してください:", 'input_type' => 'password', 'interactive_final' => true];

            case 'get_new_password':
                $interactionState['new_password'] = $input;
                $interactionState['step'] = 'confirm_new_password';
                return ['output' => "新しいパスワードをもう一度入力してください:", 'input_type' => 'password', 'interactive_final' => true];

            case 'confirm_new_password':
                if ($interactionState['new_password'] !== $input) {
                    $interactionState = ['mode' => 'account']; // 最初からやり直し
                    return ['output' => "新しいパスワードが一致しません。やり直してください。", 'clear' => false];
                }
                
                $result = $auth->changePassword($interactionState['current_password'], $interactionState['new_password']);
                $interactionState = ['mode' => 'account']; // モードをリセット
                return ['output' => $result['message'], 'clear' => false];
        }
        
        return ['output' => '', 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }
    public function getDescription(): string
    {
        return "パスワードを変更します。";
    }
    public function getUsage(): string
    {
        return "usage: passwd\n\n説明:\n  対話形式で現在のパスワードと新しいパスワードを確認し、ログインパスワードを変更します。";
    }
}