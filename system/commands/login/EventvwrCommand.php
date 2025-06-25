<?php
class EventvwrCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        return [
            'output' => "イベント ビューアーを起動します...",
            'clear' => false,
            'action' => [
                'type' => 'open_app',
                'app' => 'event_viewer',
                'options' => [
                    'width' => 800,
                    'height' => 600
                ]
            ]
        ];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "イベント ビューアーを起動して、ログを確認します。";
    }

    public function getUsage(): string
    {
        return "usage: eventvwr\n\n説明:\n  アプリケーションやファイル操作のログを閲覧するためのイベント ビューアーを新しいウィンドウで開きます。";
    }
}