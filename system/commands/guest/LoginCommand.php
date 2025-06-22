<?php

class LoginCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        if ($interactionState && $interactionState['type'] === 'login' && isset($args['input'])) {
            if ($interactionState['step'] === 'get_username') {
                $interactionState['username'] = $args['input'];
                $interactionState['step'] = 'get_password';
                return [
                    'output' => 'パスワードを入力してください:',
                    'prompt_text' => '> ',
                    'input_type' => 'password',
                    'interactive_final' => true,
                ];
            }
            if ($interactionState['step'] === 'get_password') {
                $username = $interactionState['username'];
                $password = $args['input'];
                $result = $auth->login($username, $password);
                $interactionState = null;
                return ['output' => $result['message'], 'clear' => false];
            }
        }

        if (!empty($args['u']) && !empty($args['p'])) {
            $result = $auth->login($args['u'], $args['p']);
            return ['output' => $result['message'], 'clear' => false];
        } else {
            $interactionState = ['type' => 'login', 'step' => 'get_username'];
            return [
                'output' => 'ユーザー名を入力してください:',
                'prompt_text' => '> ',
                'input_type' => 'text',
                'interactive_final' => true,
            ];
        }
    }

    public function getArgumentDefinition(): array
    {
        return ['u', 'p'];
    }

    public function getDescription(): string
    {
        return "アカウントにログインします。";
    }

    public function getUsage(): string
    {
        return "usage: login [-u ユーザー名] [-p パスワード]\n\n説明:\n  ユーザーアカウントにログインします。\n  引数を省略した場合、対話形式でユーザー名とパスワードを求められます。";
    }
}
