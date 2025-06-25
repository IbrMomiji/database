<?php
class RenameCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        if (isset($args['u']) || isset($args['p'])) {
            if (!isset($args['u']) || !isset($args['p']) || $args['u'] === true || $args['p'] === true) {
                return ['output' => "エラー: -u と -p の両方の引数が必要です。\n" . $this->getUsage(), 'clear' => false];
            }

            $newUsername = $args['u'];
            $password = $args['p'];

            $result = $auth->renameUser($newUsername, $password);

            if ($result['success']) {
                $interactionState = null;
                return array_merge($result, ['clear' => false]);
            } else {
                return ['output' => $result['message'], 'clear' => false];
            }
        }

        $step = $interactionState['step'] ?? 'start';
        $input = $args['input'] ?? null;

        if ($input === 'cancel') {
            $interactionState = ['mode' => 'account'];
            return ['output' => 'ユーザー名の変更をキャンセルしました。', 'clear' => false];
        }

        switch ($step) {
            case 'start':
                $interactionState['type'] = 'rename';
                $interactionState['step'] = 'get_new_name';
                return [
                    'output' => '新しいユーザー名を入力してください:',
                    'prompt_text' => '> ',
                    'interactive_final' => true
                ];

            case 'get_new_name':
                if (empty($input)) {
                    return [
                        'output' => 'ユーザー名が入力されていません。新しいユーザー名を入力してください:',
                        'prompt_text' => '> ',
                        'interactive_final' => true
                    ];
                }
                $interactionState['new_username'] = $input;
                $interactionState['step'] = 'get_password';
                return [
                    'output' => '現在のパスワードを入力してください:',
                    'prompt_text' => '> ',
                    'input_type' => 'password',
                    'interactive_final' => true
                ];

            case 'get_password':
                $newUsername = $interactionState['new_username'];
                $password = $input;

                if (empty($password)) {
                    return [
                        'output' => 'パスワードが入力されていません。現在のパスワードを入力してください:',
                        'prompt_text' => '> ',
                        'input_type' => 'password',
                        'interactive_final' => true
                    ];
                }

                $result = $auth->renameUser($newUsername, $password);

                if ($result['success']) {
                    $interactionState = null;
                    return array_merge($result, ['clear' => false]);
                } else {
                    $interactionState = ['mode' => 'account'];
                    return ['output' => $result['message'], 'clear' => false];
                }
        }

        $interactionState = null;
        return ['output' => '不明なエラーが発生しました。', 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [
            ['name' => 'u', 'description' => '新しいユーザー名'],
            ['name' => 'p', 'description' => '現在のパスワード']
        ];
    }
    public function getDescription(): string
    {
        return "ユーザー名を変更します。";
    }
    public function getUsage(): string
    {
        return "usage: rename [-u <新しいユーザー名> -p <現在のパスワード>]\n\n説明:\n  現在のユーザー名を変更します。\n  引数を省略すると対話モードで実行されます。";
    }
}
