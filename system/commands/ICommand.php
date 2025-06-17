<?php
// =================================================================
// コマンドインターフェース (ICommand.php)
//
// - 全てのコマンドクラスがこのインターフェースを実装する
// - コマンドの実行、引数の定義、ヘルプテキストなどを規定
// =================================================================

interface ICommand
{
    /**
     * コマンドを実行する
     * @param array $args - パースされた引数
     * @param Auth $auth - 認証クラスのインスタンス
     * @param mixed &$interactionState - 対話状態 (参照渡し)
     * @return array - フロントエンドに返すレスポンス
     */
    public function execute(array $args, Auth $auth, &$interactionState): array;

    /**
     * コマンドで許可される引数を定義する
     * @return array - ['u', 'p'] のような形式の配列
     */
    public function getArgumentDefinition(): array;

    /**
     * コマンドの短い説明を返す (helpコマンドの一覧表示用)
     * @return string
     */
    public function getDescription(): string;

    /**
     * コマンドの詳細な使い方を返す (help <コマンド名>用)
     * @return string
     */
    public function getUsage(): string;
}
