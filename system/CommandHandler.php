<?php
class CommandHandler
{
    private $auth;
    private $interactionState = null;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->interactionState = $_SESSION['interaction_state'] ?? null;
    }

    public function handle(string $input): array
    {
        if ($this->interactionState) {
            return $this->handleInteraction($input);
        } else {
            return $this->handleNewCommand($input);
        }
    }

    private function handleNewCommand(string $input): array
    {
        $parts = preg_split('/\s+/', trim($input), 2);
        $commandName = strtolower($parts[0] ?? '');
        $argString = $parts[1] ?? '';
        $currentMode = 'main';

        if ($commandName === '') {
            return ['output' => '', 'prompt' => $this->auth->getPrompt(), 'clear' => false];
        }

        $commandInstance = $this->findCommand($commandName, $currentMode);

        if ($commandInstance === null) {
            return $this->commandNotFoundResponse($commandName);
        }
        
        $args = ($commandName === 'help') ? ['target' => trim($argString)] : $this->parseArgs($argString);
        
        if ($commandName !== 'help' && !$this->checkInvalidArgs($commandName, $args, $commandInstance->getArgumentDefinition())) {
             return $this->invalidArgsResponse($commandName);
        }

        $response = $commandInstance->execute($args, $this->auth, $this->interactionState);
        
        $_SESSION['interaction_state'] = $this->interactionState;
        $response['prompt'] = $this->auth->getPrompt();
        return $response;
    }

    private function handleInteraction(string $input): array
    {
        $currentMode = $this->interactionState['mode'] ?? 'main';
        $interactionType = $this->interactionState['type'] ?? null;
        
        $parts = preg_split('/\s+/', trim($input), 2);
        $commandName = strtolower($parts[0] ?? '');
        $argString = $parts[1] ?? '';

        if ($currentMode === 'account') {
            $commandInstance = $this->findCommand($commandName, 'account');
            
            if ($commandInstance) {
                if ($commandName === 'passwd') {
                     $this->interactionState['type'] = 'passwd'; 
                     $this->interactionState['step'] = 'start';
                     return $commandInstance->execute([], $this->auth, $this->interactionState);
                }

                $args = ($commandName === 'help') ? ['target' => trim($argString), 'mode' => 'account'] : $this->parseArgs($argString);
                
                if ($commandName !== 'help' && !$this->checkInvalidArgs($commandName, $args, $commandInstance->getArgumentDefinition())) {
                    return $this->invalidArgsResponse($commandName);
                }

                $response = $commandInstance->execute($args, $this->auth, $this->interactionState);
            } else {
                 $response = $this->commandNotFoundResponse($commandName);
            }
        } else if ($interactionType) {
            $commandInstance = $this->findCommand($interactionType, 'main');
            if ($commandInstance) {
                $response = $commandInstance->execute(['input' => $input], $this->auth, $this->interactionState);
            } else {
                $response = ['output' => '対話セッションでエラーが発生しました。', 'clear' => false];
                $this->interactionState = null;
            }
        } else {
             $response = ['output' => '不明な対話状態です。', 'clear' => false];
             $this->interactionState = null;
        }
        
        $_SESSION['interaction_state'] = $this->interactionState;
        $response['prompt'] = $this->auth->getPrompt();
        return $response;
    }
    
    private function findCommand(string $commandName, string $mode): ?ICommand
    {
        $className = ucfirst($commandName) . 'Command';
        $isLoggedIn = $this->auth->isLoggedIn();

        $paths = [];
        if ($mode === 'account' && $isLoggedIn) {
            $paths[] = __DIR__ . '/commands/login/account/' . $className . '.php';
        }
        
        // helpは常に全パスから検索可能にする
        if ($commandName === 'help') {
             $paths[] = __DIR__ . '/commands/guest/' . $className . '.php';
        }
        
        if ($mode === 'main') {
            if ($isLoggedIn) {
                $paths[] = __DIR__ . '/commands/login/' . $className . '.php';
            }
            $paths[] = __DIR__ . '/commands/guest/' . $className . '.php';
        }
        
        foreach (array_unique($paths) as $path) {
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
    
    private function parseArgs(string $argString): array
    {
        $args = [];
        preg_match_all('/-(\w+)(?:\s+("[^"]*"|[^\s-]+))?/', $argString, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2] ?? true;
            if (is_string($value)) {
                $value = trim($value, '"');
            }
            $args[$key] = $value;
        }
        return $args;
    }

    private function checkInvalidArgs(string $commandName, array $args, array $validArgs): bool {
        foreach (array_keys($args) as $arg) {
            if (!in_array($arg, $validArgs)) {
                return false;
            }
        }
        return true;
    }

    private function commandNotFoundResponse(string $commandName): array {
        return [
            'output' => "コマンドが見つかりません: " . htmlspecialchars($commandName, ENT_QUOTES, 'UTF-8'),
            'prompt' => $this->auth->getPrompt(),
            'clear' => false
        ];
    }
    private function invalidArgsResponse(string $commandName): array {
        return [
            'output' => "エラー: '" . htmlspecialchars($commandName, ENT_QUOTES, 'UTF-8') . "' コマンドに不明な引数があります。",
            'prompt' => $this->auth->getPrompt(),
            'clear' => false
        ];
    }
}
