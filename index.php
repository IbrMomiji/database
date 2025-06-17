<?php
require_once __DIR__ . '/system/boot.php';
$auth = new Auth();
$initialState = $auth->getInitialState();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>database</title>
    <link rel="stylesheet" href="style/main.css">
    <style>
        .icon-notepad { background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="%23000"><path d="M9 1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4l-4-3zm2 9H5v-1h6v1zm0-2H5V7h6v1zm-1-3V2.5L13.5 6H10z"/></svg>'); }
        .icon-file_explorer_dialog { background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="%23000"><path d="M13 2H3v2h10V2zm0 3H3v2h10V5zm0 3H3v2h10V8zM3 11h5v2H3v-2z"/></svg>');}
    </style>
</head>
<body>
    <!-- Console Window Template -->
    <template id="console-window-template">
        <div class="window-container console-window">
             <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-console"></span><span class="window-title">database client</span></div><div class="window-controls"><span class="minimize-btn">_</span><span class="maximize-btn">&#10065;</span><span class="close-btn">X</span></div></div>
            <div class="console-body"><div class="console-output"></div><div class="input-line"><span class="prompt"></span><input type="text" class="console-input" spellcheck="false" autocomplete="off"></div></div>
        </div>
    </template>
    
    <!-- Explorer Window Template -->
    <template id="explorer-window-template">
        <div class="window-container explorer-window">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-explorer"></span><span class="window-title">エクスプローラー</span></div><div class="window-controls"><span class="minimize-btn">_</span><span class="maximize-btn">&#10065;</span><span class="close-btn">X</span></div></div>
            <iframe src="system/explorer.php" class="window-content-frame" name="explorer-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>

    <!-- Notepad Window Template -->
    <template id="notepad-window-template">
        <div class="window-container notepad-window">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-notepad"></span><span class="window-title">無題 - メモ帳</span></div><div class="window-controls"><span class="minimize-btn">_</span><span class="maximize-btn">&#10065;</span><span class="close-btn">X</span></div></div>
            <iframe src="system/notepad.php" class="window-content-frame" name="notepad-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>

    <!-- File Explorer Dialog Window Template -->
    <template id="file_explorer_dialog-window-template">
        <div class="window-container file-explorer-dialog-window">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-file_explorer_dialog"></span><span class="window-title">ファイルを開く</span></div><div class="window-controls"><span class="close-btn">X</span></div></div>
            <iframe src="" class="window-content-frame" name="file_explorer_dialog-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>

    <div id="minimized-area"></div>
    <div class="snap-indicator" style="display: none;"></div>
    <div id="context-menu-container"></div>

    <script> const initialClientState = <?php echo json_encode($initialState); ?>; </script>
    <script src="js/api.js" type="module"></script>
    <script src="js/contextMenu.js" type="module"></script>
    <script src="js/windowManager.js" type="module"></script>
    <script src="js/console.js" type="module"></script>
    <script src="js/main.js" type="module"></script>
</body>
</html>
