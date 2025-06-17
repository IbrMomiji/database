<?php
class NotepadCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        return [
            'output' => "メモ帳を起動します...",
            'clear' => false,
            'action' => [
                'type' => 'open_app',
                'app' => 'notepad',
                'options' => []
            ]
        ];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "テキストエディタ（メモ帳）を起動します。";
    }

    public function getUsage(): string
    {
        return "usage: notepad\n\n説明:\n  シンプルなテキストエディタを新しいウィンドウで開きます。ファイルの作成、編集、保存ができます。";
    }
}
