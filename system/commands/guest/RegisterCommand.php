<?php

class RegisterCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $input = $args['input'] ?? null;
        $step = $interactionState['step'] ?? 'start';

        if ($step === 'start' && !empty($args['u']) && !empty($args['p'])) {
            $interactionState = [
                'type' => 'register',
                'step' => 'awaiting_consent',
                'username' => $args['u'],
                'password' => $args['p']
            ];
            return ['output' => '', 'action' => ['type' => 'show_privacy_policy']];
        }

        switch ($step) {
            case 'start':
                $interactionState = ['type' => 'register', 'step' => 'get_username'];
                return [
                    'output' => '登録するユーザー名を入力してください:',
                    'interactive_final' => true,
                    'prompt_text' => '> '
                ];
            
            case 'get_username':
                $interactionState['username'] = $input;
                $interactionState['step'] = 'get_password';
                return [
                    'output' => 'パスワードを入力してください:',
                    'input_type' => 'password',
                    'interactive_final' => true,
                    'prompt_text' => '> '
                ];

            case 'get_password':
                $interactionState['password'] = $input;
                $interactionState['step'] = 'prompt_consent';
                return [
                    'output' => "プライバシーポリシーに同意してアカウントを作成します。\nEnterキーでポリシーを表示、Escキーでキャンセルします。",
                    'interactive_final' => true,
                ];

            case 'prompt_consent':
                if ($input === '') {
                    $interactionState['step'] = 'awaiting_consent';
                    return ['output' => '', 'action' => ['type' => 'show_privacy_policy']];
                } else {
                    $interactionState = null;
                    return ['output' => 'アカウントの作成をキャンセルしました。'];
                }

            case 'awaiting_consent':
                if (isset($args['consent']) && $args['consent'] === true) {
                    $username = $interactionState['username'];
                    $password = $interactionState['password'];
                    $result = $auth->register($username, $password);
                    $interactionState = null;
                    return ['output' => $result['message']];
                } else {
                    $interactionState = null;
                    return ['output' => 'アカウントの作成をキャンセルしました。'];
                }
        }

        $interactionState = null;
        return ['output' => "予期せぬエラーが発生しました。"];
    }

    public function getArgumentDefinition(): array { return ['u', 'p']; }
    public function getDescription(): string { return "新しいユーザーアカウントを作成します。"; }
    public function getUsage(): string { return "usage: register [-u ユーザー名] [-p パスワード]\n\n説明:\n  新しいユーザーアカウントを作成します。\n  引数を省略した場合、対話形式でユーザー名とパスワードを求められます。"; }
}