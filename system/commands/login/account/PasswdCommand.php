<?php
class PasswdCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        if (isset($args['c']) && isset($args['n']) && isset($args['f'])) {
            if ($args['n'] !== $args['f']) {
                return ['output' => "エラー: 新しいパスワードが一致しません。", 'clear' => false];
            }
            $result = $auth->changePassword($args['c'], $args['n']);
            return ['output' => $result['message'], 'clear' => false];
        }

        $step = $interactionState['step'] ?? 'start';
        $input = $args['input'] ?? null;

        switch ($step) {
            case 'start':
                $interactionState['type'] = 'passwd';
                $interactionState['step'] = 'get_current';
                return ['output' => "現在のパスワードを入力してください:", 'input_type' => 'password', 'interactive_final' => true];

            case 'get_current':
                $interactionState['current_password'] = $input;
                $interactionState['step'] = 'get_new';
                return ['output' => "新しいパスワードを入力してください:", 'input_type' => 'password', 'interactive_final' => true];

            case 'get_new':
                $interactionState['new_password'] = $input;
                $interactionState['step'] = 'confirm_new';
                return ['output' => "新しいパスワードをもう一度入力してください:", 'input_type' => 'password', 'interactive_final' => true];

            case 'confirm_new':
                if ($interactionState['new_password'] !== $input) {
                    $interactionState = ['mode' => 'account'];
                    return ['output' => "新しいパスワードが一致しません。やり直してください。", 'clear' => false];
                }
                
                $result = $auth->changePassword($interactionState['current_password'], $interactionState['new_password']);
                $interactionState = ['mode' => 'account'];
                return ['output' => $result['message'], 'clear' => false];
        }
        
        return ['output' => '', 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return ['c', 'n', 'f'];
    }
    public function getDescription(): string
    {
        return "パスワードを変更します。";
    }
    public function getUsage(): string
    {
        return "usage: passwd [-c 現在のパスワード] [-n 新パスワード] [-f 確認用パスワード]\n\n説明:\n  ログインパスワードを変更します。引数を省略した場合は対話形式で尋ねられます。";
    }
}
