<?php
/**
 * admin/management.php
 * 管理者向けダッシュボード
 * このファイルは index.php によってrootユーザー認証後にのみ読み込まれます。
 */

// データベースのインスタンスを取得します
$db = Database::getInstance();
$pdo = $db->getPdo();

/**
 * 指定されたディレクトリの内容を再帰的にスキャンし、HTMLのリストとして整形する関数
 * @param string $dirPath スキャンするディレクトリのパス
 * @return string ディレクトリ構造を示すHTML文字列
 */
function listDirectoryContents($dirPath) {
    // ディレクトリが存在し、読み取り可能かチェックします
    if (!is_dir($dirPath) || !is_readable($dirPath)) {
        return '<ul><li>ディレクトリを読み込めません: ' . htmlspecialchars($dirPath) . '</li></ul>';
    }

    $html = '<ul>';
    // scandirでディレクトリ内のアイテムを取得します
    $items = scandir($dirPath);

    foreach ($items as $item) {
        // '.' と '..' は現在のディレクトリと親ディレクトリを示すため除外します
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dirPath . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            // アイテムがディレクトリの場合、再帰的にこの関数を呼び出します
            $html .= '<li><strong><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.54 3.87.5 3a2 2 0 0 1 2-2h3.672a2 2 0 0 1 1.414.586l.828.828A2 2 0 0 0 9.828 3h3.982a2 2 0 0 1 1.992 2.181l-.637 7A2 2 0 0 1 13.174 14H2.826a2 2 0 0 1-1.991-1.819l-.637-7a1.99 1.99 0 0 1 .54-1.31zM2.19 4a1 1 0 0 0-.996.81l.637 7a1 1 0 0 0 .995.89h10.348a1 1 0 0 0 .995-.89l.637-7A1 1 0 0 0 13.81 4H2.19zm4.69-1.707A1 1 0 0 0 6.172 2H2.5a1 1 0 0 0-1 .981l.006.139C1.72 3.042 1.95 3 2.19 3h5.396l-.707-.707z"/></svg> ' . htmlspecialchars($item) . '/</strong>';
            $html .= listDirectoryContents($path);
            $html .= '</li>';
        } else {
            // アイテムがファイルの場合、リストアイテムとして追加します
            $html .= '<li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708l3-3z"/></svg> ' . htmlspecialchars($item) . '</li>';
        }
    }
    $html .= '</ul>';
    return $html;
}

// 全ユーザーのディレクトリが格納されているベースパス
$usersBasePath = __DIR__ . '/../users';

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>管理者ページ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* 管理ページ用のカスタムスタイル */
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

    <!-- データベース情報セクション -->
    <div id="database-info" class="section">
        <h2>データベース情報</h2>
        <?php
        try {
            // SHOW TABLESクエリで全テーブル名を取得します
            $tablesQuery = $pdo->query("SHOW TABLES");
            $tables = $tablesQuery->fetchAll(PDO::FETCH_COLUMN);

            if (empty($tables)) {
                echo "<p class='info-message'>データベースにテーブルが見つかりません。</p>";
            } else {
                foreach ($tables as $table) {
                    echo "<h3>テーブル: " . htmlspecialchars($table) . "</h3>";

                    // 各テーブルから全てのデータを取得します
                    $stmt = $pdo->query("SELECT * FROM " . $table);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($rows)) {
                        echo "<p class='info-message'>このテーブルにはデータがありません。</p>";
                    } else {
                        echo "<table>";
                        // テーブルヘッダーを動的に生成します
                        echo "<thead><tr>";
                        foreach (array_keys($rows[0]) as $column) {
                            echo "<th>" . htmlspecialchars($column) . "</th>";
                        }
                        echo "</tr></thead>";

                        // テーブルの各行を生成します
                        echo "<tbody>";
                        foreach ($rows as $row) {
                            echo "<tr>";
                            foreach ($row as $cell) {
                                echo "<td>" . htmlspecialchars($cell ?? 'NULL') . "</td>";
                            }
                            echo "</tr>";
                        }
                        echo "</tbody>";
                        echo "</table>";
                    }
                }
            }
        } catch (PDOException $e) {
            echo "<p class='error-message'>データベースエラー: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>

    <!-- ファイルエクスプローラーセクション -->
    <div id="file-explorer" class="section">
        <h2>ユーザーフォルダの内容</h2>
        <?php
        if (is_dir($usersBasePath) && is_readable($usersBasePath)) {
            $userDirs = scandir($usersBasePath);
            $foundUserDirs = false;
            foreach ($userDirs as $userDir) {
                if ($userDir === '.' || $userDir === '..') {
                    continue;
                }

                $fullPath = $usersBasePath . DIRECTORY_SEPARATOR . $userDir;
                if (is_dir($fullPath)) {
                    $foundUserDirs = true;
                    echo "<h3>ユーザー: " . htmlspecialchars($userDir) . "</h3>";
                    echo "<div class='file-explorer'>";
                    // listDirectoryContents関数でディレクトリ構造を表示します
                    echo listDirectoryContents($fullPath);
                    echo "</div>";
                }
            }
            if (!$foundUserDirs) {
                echo "<p class='info-message'>ユーザーディレクトリが見つかりません。</p>";
            }
        } else {
            echo "<p class='error-message'>ユーザーディレクトリ '" . htmlspecialchars($usersBasePath) . "' が存在しないか、読み取り権限がありません。</p>";
        }
        ?>
    </div>

</div>

</body>
</html>
