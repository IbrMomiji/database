<?php

class ExplorerCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        return [
            'output' => "エクスプローラーを起動します...",
            'clear' => false,
            'action' => [
                'type' => 'open_app',
                'app' => 'explorer',
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
        return "GUIのファイルエクスプローラーを起動します。";
    }

    public function getUsage(): string
    {
        return "usage: explorer\n\n説明:\n  ファイルやフォルダを管理するためのグラフィカルなエクスプローラーを新しいウィンドウで開きます。";
    }
}
