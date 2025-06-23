<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
defined('DB_PATH') || define('DB_PATH', BASE_PATH . '/db/database.sqlite');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

require_once BASE_PATH . '/system/Database.php';
require_once BASE_PATH . '/system/auth.php';

if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'root') {
    die('アクセス権がありません。このページは管理者(root)専用です。');
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$usersBasePath = realpath(BASE_PATH . '/user') ?: (BASE_PATH . '/users');

function listDirectoryFiles($dirPath) {
    if (!is_dir($dirPath) || !is_readable($dirPath)) {
        return "エラー: ディレクトリを読み込めません。";
    }
    $items = array_diff(scandir($dirPath), ['.', '..']);
    if (empty($items)) {
        return 'ディレクトリは空です。';
    }
    $html = '';
    foreach ($items as $item) {
        $full = $dirPath . DIRECTORY_SEPARATOR . $item;
        $type = is_dir($full) ? '<DIR>' : '     ';
        $html .= $type . '   ' . htmlspecialchars($item) . "\n";
    }
    return "<pre>" . $html . "</pre>";
}

$selected_user = isset($_GET['user']) ? $_GET['user'] : null;
$safe_selected_user = $selected_user && preg_match('/^[a-zA-Z0-9_\-]+$/', $selected_user) ? $selected_user : null;

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>管理者ページ - メインメニュー</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background-color: #0000AA;
            color: #FFFFFF;
            font-family: 'MS Gothic', 'Osaka-mono', 'Courier New', monospace;
            display: flex;
            justify-content: center;
            align-items: flex-start; /* 上寄せに変更 */
            min-height: 100vh;
            margin: 0;
            padding: 1rem;
            box-sizing: border-box;
            font-size: 16px;
        }
        .bios-screen {
            width: 100%;
            /* max-width: 900px; */
            max-width: 100%;
            background: #0000AA;
            border: 2px solid #FFFFFF;
            padding: 0.5rem;
        }
        .bios-header, .bios-footer {
            background-color: #888888;
            color: #FFFFFF;
            text-align: center;
            padding: 0.2rem 0;
            margin-bottom: 1rem;
            font-weight: bold;
        }
        .bios-footer {
            margin-top: 1rem;
            margin-bottom: 0;
            display: flex;
            justify-content: space-around;
        }
        .main-content {
            padding: 0 1.5rem;
            display: flex;
            gap: 2rem;
        }
        .left-panel {
            flex: 1;
        }
        .right-panel {
            flex: 2;
            border-left: 1px solid #fff;
            padding-left: 2rem;
            min-height: 400px;
        }
        .menu-title {
            background-color: #888888;
            padding: 2px 8px;
            margin-bottom: 1rem;
            display: inline-block;
        }
        .menu-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .menu-list li a {
            display: block;
            color: #FFFFFF;
            text-decoration: none;
            padding: 0.3rem 0;
        }
        .menu-list li a.active, .menu-list li a:hover {
            background-color: #FFFFFF;
            color: #0000AA;
        }
        .content-box {
            height: 100%;
        }
        .content-title {
            color: #FFFF55; /* 黄色 */
            margin-bottom: 1rem;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 0.9em;
        }
        th, td {
            border: 1px solid #FFFFFF;
            padding: 5px 8px;
            text-align: left;
        }
        th {
            background: #AAAAAA;
            color: #0000AA;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: inherit;
            margin: 0;
        }
        .user-link-list li a {
            padding: 2px;
        }
         a { color: inherit; text-decoration: none; }
    </style>
</head>
<body>

<div class="bios-screen">
    <header class="bios-header">
        管理者ページ - メインメニュー
    </header>

    <main class="main-content">
        <div class="left-panel">
            <h2 class="menu-title">情報カテゴリ</h2>
            <ul class="menu-list">
                <li><a href="?view=db" class="<?= (!isset($_GET['view']) || $_GET['view'] === 'db') ? 'active' : '' ?>">データベース情報</a></li>
                <li><a href="?view=users" class="<?= (isset($_GET['view']) && $_GET['view'] === 'users') ? 'active' : '' ?>">ユーザーフォルダ</a></li>
            </ul>

            <h2 class="menu-title" style="margin-top: 2rem;">操作メニュー</h2>
            <ul class="menu-list">
                 <li><a href="delusr/index.php">ユーザー削除</a></li>
            </ul>
        </div>

        <div class="right-panel">
            <?php 
            $view = $_GET['view'] ?? 'db';
            if ($view === 'users'):
            ?>
                <div id="file-explorer" class="content-box">
                    <h3 class="content-title">ユーザーフォルダ 一覧</h3>
                     <?php
                    $userDirs = array_filter(scandir($usersBasePath), fn($d) => is_dir($usersBasePath . '/' . $d) && !in_array($d, ['.', '..']));
                    if (empty($userDirs)) {
                        echo "<div>ユーザーディレクトリが見つかりません。</div>";
                    } else {
                        echo '<ul class="menu-list user-link-list">';
                        foreach ($userDirs as $userDir) {
                            $safe = htmlspecialchars($userDir);
                            echo "<li><a href=\"?view=users&user={$safe}\" class=\"" . ($safe_selected_user === $safe ? 'active' : '') . "\">{$safe}</a></li>";
                        }
                        echo '</ul>';
                    }
                    ?>
                    
                    <?php if ($safe_selected_user): ?>
                        <div style="margin-top: 2rem;">
                            <h3 class="content-title">ファイル一覧: <?= htmlspecialchars($safe_selected_user) ?></h3>
                             <?php
                                $targetDir = $usersBasePath . DIRECTORY_SEPARATOR . $safe_selected_user;
                                echo listDirectoryFiles($targetDir);
                             ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: // Default to db view ?>

                <div id="database-info" class="content-box">
                    <h3 class="content-title">データベース テーブル一覧</h3>
                    <?php
                    try {
                        $tablesQuery = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                        $tables = $tablesQuery->fetchAll(PDO::FETCH_COLUMN);

                        if (empty($tables)) {
                            echo "<div>テーブルが見つかりません。</div>";
                        } else {
                            foreach ($tables as $table) {
                                echo "<h4>テーブル: " . htmlspecialchars($table) . "</h4>";
                                $stmt = $pdo->query("SELECT * FROM \"$table\" LIMIT 10"); // 10件に制限
                                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                if (empty($rows)) {
                                    echo "<p>データがありません。</p>";
                                    continue;
                                }

                                echo "<table><thead><tr>";
                                foreach (array_keys($rows[0]) as $col) echo "<th>" . htmlspecialchars($col) . "</th>";
                                echo "</tr></thead><tbody>";
                                foreach ($rows as $row) {
                                    echo "<tr>";
                                    foreach ($row as $value) echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
                                    echo "</tr>";
                                }
                                echo "</tbody></table><br>";
                            }
                        }
                    } catch (PDOException $e) {
                        echo "<p>データベースエラー: " . htmlspecialchars($e->getMessage()) . "</p>";
                    }
                    ?>
                </div>

            <?php endif; ?>
        </div>
    </main>

    <footer class="bios-footer">
        <div><a href="../index.php">メインページに戻る</a></div>
        <div><a href="logout.php">ログアウト</a></div>
    </footer>
</div>
</body>
</html>
