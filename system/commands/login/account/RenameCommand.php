<?php
class RenameCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        if (isset($args['n'])) {
            $result = $auth->renameUser($args['n']);
            if ($result['success']) {
                $interactionState = null;
            }
            return ['output' => $result['message'], 'clear' => false];
        }

        if (isset($args['input'])) {
            $result = $auth->renameUser($args['input']);
            if ($result['success']) {
                $interactionState = null;
            }
            return ['output' => $result['message'], 'clear' => false];
        }
        
        $interactionState['type'] = 'rename';
        $interactionState['step'] = 'get_new_name';
        return ['output' => '新しいユーザー名を入力してください:', 'interactive_final' => true];
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
        return "usage: rename [-n <新しいユーザー名>]\n\n説明:\n  現在のユーザー名を変更します。引数を省略した場合は対話形式で尋ねられます。変更が成功すると自動的にログアウトします。";
    }
}