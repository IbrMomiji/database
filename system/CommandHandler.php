<?php
// =================================================================
// コマンドハンドラークラス (CommandHandler.php)
//
// - ユーザー入力を受け取り、適切なコマンドクラスに処理を委譲するコントローラー
// - コマンドの存在チェック、実行権限の検証、対話モードの管理を行う
// =================================================================

class CommandHandler
{
    private $auth;
    private $interactionState = null;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->interactionState = $_SESSION['interaction_state'] ?? null;
    }

    /**
     * 入力されたコマンドを処理するメインメソッド
     * @param string $input
     * @return array フロントエンドに返すレスポンス
     */
    public function handle(string $input): array
    {
        if ($this->interactionState) {
            return $this->handleInteraction($input);
        }

        $parts = preg_split('/\s+/', trim($input), 2);
        $commandName = strtolower($parts[0] ?? '');
        $argString = $parts[1] ?? '';

        if ($commandName === '') {
            return ['output' => '', 'prompt' => $this->auth->getPrompt(), 'clear' => false];
        }

        $commandInstance = $this->findCommand($commandName);

        if ($commandInstance === null) {
            return [
                'output' => "コマンドが見つかりません: " . htmlspecialchars($commandName, ENT_QUOTES, 'UTF-8'),
                'prompt' => $this->auth->getPrompt(),
                'clear' => false
            ];
        }

        // helpコマンドの引数処理を特別扱い
        if ($commandName === 'help') {
            // helpコマンドの場合は、-u などではなくコマンド名を引数として扱う
            $targetCommandName = trim($argString);
            $args = $targetCommandName ? ['target' => $targetCommandName] : [];
        } else {
            $args = $this->parseArgs($argString);
             // 不正な引数がないかチェック
            $validArgs = $commandInstance->getArgumentDefinition();
            if (!$this->checkInvalidArgs($commandName, $args, $validArgs)) {
                return [
                    'output' => "エラー: '" . htmlspecialchars($commandName, ENT_QUOTES, 'UTF-8') . "' コマンドに不明な引数があります。",
                    'prompt' => $this->auth->getPrompt(),
                    'clear' => false
                ];
            }
        }
        
        $response = $commandInstance->execute($args, $this->auth, $this->interactionState);
        
        $_SESSION['interaction_state'] = $this->interactionState;
        $response['prompt'] = $this->auth->getPrompt();
        return $response;
    }

    /**
     * 対話モードの処理
     * @param string $input
     * @return array
     */
    private function handleInteraction(string $input): array
    {
        $type = $this->interactionState['type'];
        
        // 対話モードの処理を特定のコマンドに委譲する
        $commandInstance = $this->findCommand($type);

        if ($commandInstance) {
            // ここではユーザーからの生の入力を$args['input']として渡す
            $response = $commandInstance->execute(['input' => $input], $this->auth, $this->interactionState);
            $_SESSION['interaction_state'] = $this->interactionState;
            $response['prompt'] = $this->auth->getPrompt();
            return $response;
        } else {
            // 該当するコマンドが見つからない場合、対話モードを終了
            $this->interactionState = null;
            $_SESSION['interaction_state'] = null;
            return [
                'output' => '対話セッションでエラーが発生しました。',
                'prompt' => $this->auth->getPrompt(),
                'clear' => false,
            ];
        }
    }

    /**
     * コマンド名から対応するコマンドクラスのインスタンスを探す
     * @param string $commandName
     * @return ICommand|null
     */
    private function findCommand(string $commandName): ?ICommand
    {
        $className = ucfirst($commandName) . 'Command';
        $isLoggedIn = $this->auth->isLoggedIn();

        // 検索するパスのリスト
        $paths = [];
        if ($isLoggedIn) {
            $paths[] = __DIR__ . '/commands/login/' . $className . '.php';
        }
        $paths[] = __DIR__ . '/commands/guest/' . $className . '.php';
        
        foreach ($paths as $path) {
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
    
    /**
     * 引数文字列をパースして連想配列に変換する
     * @param string $argString
     * @return array
     */
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

    /**
     * コマンドに不正な引数が含まれていないかチェックする
     * @param string $commandName
     * @param array $args
     * @param array $validArgs
     * @return bool
     */
    private function checkInvalidArgs(string $commandName, array $args, array $validArgs): bool {
        foreach (array_keys($args) as $arg) {
            if (!in_array($arg, $validArgs)) {
                return false;
            }
        }
        return true;
    }
}
