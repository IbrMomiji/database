<?php
require_once BASE_PATH . '/system/Database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

function listDirectoryContents($dirPath) {
    if (!is_dir($dirPath) || !is_readable($dirPath)) {
        return '<ul><li>ディレクトリを読み込めません: ' . htmlspecialchars($dirPath) . '</li></ul>';
    }

    $html = '<ul>';
    $items = scandir($dirPath);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $path = $dirPath . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $html .= '<li><strong><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.54 3.87.5 3a2 2 0 0 1 2-2h3.672a2 2 0 0 1 1.414..."/></svg> ' . htmlspecialchars($item) . '</strong>';
            $html .= listDirectoryContents($path);
            $html .= '</li>';
        } else {
            $html .= '<li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1..."/></svg> ' . htmlspecialchars($item) . '</li>';
        }
    }
    $html .= '</ul>';
    return $html;
}

$usersBasePath = realpath(__DIR__ . '/../user') ?: __DIR__ . '/../user';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>管理者ページ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background-color: #f0f2f5;
            color: #333;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1, h2, h3 {
            color: #1d2129;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            margin-top: 40px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 2.5rem;
        }
        h2 {
            font-size: 2rem;
        }
        h3 {
            font-size: 1.5rem;
            border-bottom: none;
            color: #495057;
        }
        .section {
            background-color: #fff;
            border: 1px solid #dddfe2;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 0.9rem;
        }
        th, td {
            border: 1px solid #dee2e6;
            padding: 12px;
            text-align: left;
            vertical-align: top;
            word-break: break-all;
        }
        th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .file-explorer ul {
            list-style-type: none;
            padding-left: 20px;
        }
        .file-explorer li {
            padding: 5px 0;
            display: flex;
            align-items: center;
        }
        .file-explorer svg {
            margin-right: 8px;
            min-width: 16px;
        }
        .file-explorer strong {
            color: #0d6efd;
        }
        .error-message {
            color: #dc3545;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            padding: 15px;
        }
        .info-message {
            color: #0c5460;
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 4px;
            padding: 15px;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>管理者ダッシュボード</h1>

    <div id="database-info" class="section">
        <h2>データベース情報</h2>
        <?php
        try {
            $tablesQuery = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $tables = $tablesQuery->fetchAll(PDO::FETCH_COLUMN);

            if (empty($tables)) {
                echo "<p class='info-message'>データベースにテーブルが見つかりません。</p>";
            } else {
                foreach ($tables as $table) {
                    $safeTable = htmlspecialchars($table);
                    echo "<h3>テーブル: {$safeTable}</h3>";

                    $stmt = $pdo->query("SELECT * FROM " . $pdo->quote($table));
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($rows)) {
                        echo "<p class='info-message'>このテーブルにはデータがありません。</p>";
                        continue;
                    }

                    $columns = array_keys($rows[0]);
                    echo "<table><thead><tr>";
                    foreach ($columns as $col) {
                        echo "<th>" . htmlspecialchars($col) . "</th>";
                    }
                    echo "</tr></thead><tbody>";

                    foreach ($rows as $row) {
                        echo "<tr>";
                        foreach ($columns as $col) {
                            $value = $row[$col] ?? 'NULL';
                            echo "<td>" . htmlspecialchars($value) . "</td>";
                        }
                        echo "</tr>";
                    }
                    echo "</tbody></table>";
                }
            }
        } catch (PDOException $e) {
            echo "<p class='error-message'>データベースエラー: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>

    <div id="file-explorer" class="section">
        <h2>ユーザーフォルダの内容</h2>
        <?php
        if (!is_dir($usersBasePath) || !is_readable($usersBasePath)) {
            echo "<p class='error-message'>ユーザーディレクトリ '" . htmlspecialchars($usersBasePath) . "' が存在しないか、読み取り権限がありません。</p>";
            exit;
        }

        $userDirs = array_diff(scandir($usersBasePath), ['.', '..']);
        if (empty($userDirs)) {
            echo "<p class='info-message'>ユーザーディレクトリが見つかりません。</p>";
            exit;
        }

        foreach ($userDirs as $userDir) {
            $fullPath = $usersBasePath . DIRECTORY_SEPARATOR . $userDir;
            if (!is_dir($fullPath)) continue;

            echo "<h3>ユーザー: " . htmlspecialchars($userDir) . "</h3>";
            echo "<div class='file-explorer'>";
            echo listDirectoryContents($fullPath);
            echo "</div>";
        }
        ?>
    </div>
</div>
</body>
</html>
