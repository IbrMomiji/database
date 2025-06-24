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
        }
        return $this->handleNewCommand($input, 'main');
    }

    private function handleNewCommand(string $input, string $mode): array
    {
        $parts = preg_split('/\s+/', trim($input), 2);
        $commandName = strtolower($parts[0] ?? '');
        $argString = $parts[1] ?? '';
        
        if ($commandName === '') {
            $_SESSION['interaction_state'] = $this->interactionState;
            return ['output' => '', 'prompt' => $this->auth->getPrompt(), 'clear' => false];
        }

        $commandInstance = $this->findCommand($commandName, $mode);

        if ($commandInstance === null) {
            return $this->commandNotFoundResponse($commandName);
        }
        
        $args = ($commandName === 'help') ? ['target' => trim($argString), 'mode' => $mode] : $this->parseArgs($argString);
        
        if ($commandName !== 'help' && !$this->checkInvalidArgs($args, $commandInstance->getArgumentDefinition())) {
             return $this->invalidArgsResponse($commandName);
        }

        $response = $commandInstance->execute($args, $this->auth, $this->interactionState);
        
        $_SESSION['interaction_state'] = $this->interactionState;
        if (!isset($response['prompt'])) {
            $response['prompt'] = $this->auth->getPrompt();
        }
        return $response;
    }

    private function handleInteraction(string $input): array
    {
        $currentMode = $this->interactionState['mode'] ?? 'main';
        $interactionType = $this->interactionState['type'] ?? null;
        
        $postData = json_decode(file_get_contents('php://input'), true) ?? [];
        $args = ['input' => $input];
        if (isset($postData['consent'])) {
            $args['consent'] = $postData['consent'];
        }

        $response = [];

        if ($interactionType) {
            $commandInstance = $this->findCommand($interactionType, $currentMode);
            if ($commandInstance) {
                $response = $commandInstance->execute($args, $this->auth, $this->interactionState);
            } else {
                $response = ['output' => '対話セッションで致命的なエラーが発生しました。', 'clear' => false];
                $this->interactionState = null;
            }
        } 
        else if ($currentMode === 'account') {
            return $this->handleNewCommand($input, 'account');
        } 
        else {
             $response = ['output' => '不明な対話状態です。', 'clear' => false];
             $this->interactionState = null;
        }
        
        $_SESSION['interaction_state'] = $this->interactionState;
        if (!isset($response['prompt'])) {
            $response['prompt'] = $this->auth->getPrompt();
        }
        return $response;
    }
    
    private function findCommand(string $commandName, string $mode): ?ICommand
    {
        $className = ucfirst($commandName) . 'Command';
        $fileName = $className . '.php';
        $baseDir = __DIR__ . '/commands';

        $searchPaths = [];

        if ($mode === 'account') {
            $searchPaths[] = $baseDir . '/login/account/';
        }
        
        if ($this->auth->isLoggedIn()) {
            $searchPaths[] = $baseDir . '/login/';
        }
        
        $searchPaths[] = $baseDir . '/guest/';

        foreach ($searchPaths as $path) {
            $filePath = $path . $fileName;
            if (file_exists($filePath)) {
                require_once $filePath;
                if (class_exists($className)) {
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

    private function checkInvalidArgs(array $args, array $validArgs): bool {
        if (empty($validArgs) && !empty($args)) {
            return false;
        }
        foreach (array_keys($args) as $arg) {
            if (!in_array($arg, $validArgs)) {
                return false;
            }
        }
        return true;
    }

    private function commandNotFoundResponse(string $commandName): array {
        return ['output' => "コマンドが見つかりません: " . htmlspecialchars($commandName, ENT_QUOTES, 'UTF-8')];
    }

    private function invalidArgsResponse(string $commandName): array {
        return ['output' => "エラー: '" . htmlspecialchars($commandName, ENT_QUOTES, 'UTF-8') . "' コマンドに不明な引数があります。"];
    }
}