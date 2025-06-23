<?php
class RenameCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $newName = null;
        if (isset($args['n'])) {
            $newName = $args['n'];
        } elseif (isset($args['input'])) {
            $newName = $args['input'];
        }

        if ($newName !== null) {
            $result = $auth->renameUser($newName);
            if ($result['success']) {
                $interactionState = null; // accountモードを抜ける
                // $resultにはmessageとpromptが含まれている
                return array_merge($result, ['clear' => false]);
            } else {
                // 失敗時はアカウントモードのまま
                return ['output' => $result['message'], 'clear' => false];
            }
        }
        
        // 対話モード開始
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
        return "usage: rename [-n <新しいユーザー名>]\n\n説明:\n  現在のユーザー名を変更します。引数を省略した場合は対話形式で尋ねられます。";
    }
}