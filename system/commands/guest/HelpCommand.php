<?php
class HelpCommand implements ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array
    {
        $targetCommandName = $args['target'] ?? null;
        $mode = $interactionState['mode'] ?? 'main';

        if ($targetCommandName) {
            $commandInstance = $this->findCommandInPaths($targetCommandName, $auth->isLoggedIn(), $mode);
            if ($commandInstance) {
                $output = nl2br(htmlspecialchars($commandInstance->getUsage(), ENT_QUOTES, 'UTF-8'));
            } else {
                $output = "コマンド '" . htmlspecialchars($targetCommandName, ENT_QUOTES, 'UTF-8') . "' は見つかりません。";
            }
        } else {
            $title = ($mode === 'account') ? "アカウント管理コマンド:" : "利用可能なコマンド:";
            $output = $title . "<br>";
            $commands = $this->getAllCommands($auth->isLoggedIn(), $mode);
            
            ksort($commands);

            foreach ($commands as $name => $instance) {
                $namePadded = str_pad($name, 18, ' ');
                $output .= "  <span class=\"cmd\">" . htmlspecialchars($namePadded, ENT_QUOTES, 'UTF-8') . "</span>- " . htmlspecialchars($instance->getDescription(), ENT_QUOTES, 'UTF-8') . "<br>";
            }
            if ($mode === 'main') {
                $output .= "<br>'help &lt;コマンド名&gt;' で詳細な使い方を表示します。";
            }
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
        return "usage: help [コマンド名]\n\n説明:\n  引数なしで実行すると、現在のモードで利用可能なコマンドの一覧を表示します。\n  コマンド名を引数に指定すると、そのコマンドの詳細な使い方を表示します。";
    }

    private function getAllCommands(bool $isLoggedIn, string $mode): array
    {
        $commands = [];
        $pathPatterns = [];

        if ($mode === 'account') {
            $pathPatterns[] = __DIR__ . '/../login/account/*Command.php';
        } else {
            if ($isLoggedIn) {
                $pathPatterns[] = __DIR__ . '/../login/*Command.php';
            }
            $pathPatterns[] = __DIR__ . '/*Command.php';
        }
        
        foreach ($pathPatterns as $pattern) {
            $files = glob($pattern);
            if ($files === false) continue;

            foreach ($files as $file) {
                $className = basename($file, '.php');
                if ($mode === 'main' && $className === 'AccountCommand') {
                    continue; 
                }
                if (class_exists($className)) {
                    $instance = new $className();
                    if ($instance instanceof ICommand) {
                        $commandName = strtolower(substr($className, 0, -7));
                        $commands[$commandName] = $instance;
                    }
                }
            }
        }

        if (!isset($commands['help'])) {
             $commands['help'] = new HelpCommand();
        }

        return $commands;
    }

    private function findCommandInPaths(string $commandName, bool $isLoggedIn, string $mode): ?ICommand
    {
        $className = ucfirst($commandName) . 'Command';
        
        $paths_to_check = [];
        if ($isLoggedIn) {
             if ($mode === 'account' || $commandName === 'help') {
                $paths_to_check[] = __DIR__ . '/../login/account/' . $className . '.php';
             }
             $paths_to_check[] = __DIR__ . '/../login/' . $className . '.php';
        }
        $paths_to_check[] = __DIR__ . '/' . $className . '.php';
        
        foreach (array_unique($paths_to_check) as $path) {
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
