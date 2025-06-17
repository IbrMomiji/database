<?php // system/commands/guest/HelpCommand.php

class HelpCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $targetCommandName = $args['target'] ?? null;

        if ($targetCommandName) {
            // --- 特定のコマンドの詳細ヘルプ ---
            $commandInstance = $this->findCommandInPaths($targetCommandName, $auth->isLoggedIn());
            if ($commandInstance) {
                $output = nl2br(htmlspecialchars($commandInstance->getUsage(), ENT_QUOTES, 'UTF-8'));
            } else {
                $output = "コマンドが見つかりません: " . htmlspecialchars($targetCommandName, ENT_QUOTES, 'UTF-8');
            }
        } else {
            // --- コマンド一覧のヘルプ ---
            $output = "利用可能なコマンド:<br>";
            $commands = $this->getAllCommands($auth->isLoggedIn());
            
            ksort($commands);

            foreach ($commands as $name => $instance) {
                $namePadded = str_pad($name, 18, ' ');
                $output .= "  <span class=\"cmd\">" . htmlspecialchars($namePadded, ENT_QUOTES, 'UTF-8') . "</span>- " . htmlspecialchars($instance->getDescription(), ENT_QUOTES, 'UTF-8') . "<br>";
            }
            $output .= "<br>'help &lt;コマンド名&gt;' で詳細な使い方を表示します。";
        }

        return ['output' => $output, 'clear' => false];
    }

    public function getArgumentDefinition(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return "利用可能なコマンドのヘルプを表示します。";
    }

    public function getUsage(): string
    {
        return "usage: help [コマンド名]\n\n説明:\n  引数なしで実行すると、利用可能な全てのコマンドの一覧を表示します。\n  コマンド名を引数に指定すると、そのコマンドの詳細な使い方を表示します。";
    }

    /**
     * 利用可能な全てのコマンドインスタンスを取得するヘルパー関数
     * @param bool $isLoggedIn
     * @return array<string, ICommand>
     */
    private function getAllCommands(bool $isLoggedIn): array
    {
        $commands = [];
        $guestPath = __DIR__;
        $loginPath = __DIR__ . '/../login';

        // guestコマンドを読み込み
        $guestFiles = glob($guestPath . '/*Command.php');
        if ($guestFiles === false) $guestFiles = []; // エラーガード

        foreach ($guestFiles as $file) {
            $className = basename($file, '.php');
            if ($className === self::class) continue; // ★修正: 自分自身はスキップ

            if (class_exists($className)) {
                $instance = new $className();
                if ($instance instanceof ICommand) {
                    $commandName = strtolower(substr($className, 0, -7));
                    $commands[$commandName] = $instance;
                }
            }
        }

        // ログイン時のみloginコマンドを読み込み
        if ($isLoggedIn) {
            $loginFiles = glob($loginPath . '/*Command.php');
            if ($loginFiles === false) $loginFiles = []; // エラーガード

            if ($loginFiles) {
                foreach ($loginFiles as $file) {
                    $className = basename($file, '.php');
                    if (class_exists($className)) {
                        $instance = new $className();
                        if ($instance instanceof ICommand) {
                            $commandName = strtolower(substr($className, 0, -7));
                            $commands[$commandName] = $instance;
                        }
                    }
                }
            }
        }

        // ★修正: 最後に自分自身をリストに追加
        $commands['help'] = $this;

        return $commands;
    }

    /**
     * 指定されたコマンド名のインスタンスを探すヘルパー関数
     * @param string $commandName
     * @param bool $isLoggedIn
     * @return ICommand|null
     */
    private function findCommandInPaths(string $commandName, bool $isLoggedIn): ?ICommand
    {
        $className = ucfirst($commandName) . 'Command';
        
        $paths_to_check = [];
        if ($isLoggedIn) {
            $paths_to_check[] = __DIR__ . '/../login/' . $className . '.php';
        }
        $paths_to_check[] = __DIR__ . '/' . $className . '.php';
        
        foreach ($paths_to_check as $path) {
            if (file_exists($path)) {
                if (class_exists($className, true)) {
                    $instance = new $className();
                    if ($instance instanceof ICommand) {
                        return $instance;
                    }
                }
            }
        }
        return null;
    }
}
