<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['id']) && strpos($_SERVER['REQUEST_URI'], 'share.php') !== false) {
    require __DIR__ . '/share.php';
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_privacy_policy') {
    header('Content-Type: text/html; charset=utf-8');
    $policy_file = __DIR__ . '/page/privacy.html';
    if (file_exists($policy_file)) {
        readfile($policy_file);
    } else {
        http_response_code(404);
        echo 'プライバシーポリシーのファイルが見つかりません。';
    }
    exit;
}

require_once __DIR__ . '/system/boot.php';
$auth = new Auth();
$initialState = $auth->getInitialState();
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="ja" xml:lang="ja">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>database</title>
    <link rel="stylesheet" href="style/main.css" type="text/css" />
    <style type="text/css">
        .icon-notepad { background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="%23000"><path d="M9 1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4l-4-3zm2 9H5v-1h6v1zm0-2H5V7h6v1zm-1-3V2.5L13.5 6H10z"/></svg>'); }
        .icon-file_explorer_dialog { background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="%23000"><path d="M13 2H3v2h10V2zm0 3H3v2h10V5zm0 3H3v2h10V8zM3 11h5v2H3v-2z"/></svg>');}
        .icon-share { background-image: url('data:image/svg+xml;utf8,<svg fill="%23000" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg>'); }
    </style>
</head>
<body>
    <div id="desktop"></div>

    <div id="window-switcher" style="display: none;">
        <div class="windows-container"></div>
    </div>
    
    <div id="privacy-policy-overlay" style="display: none;" tabindex="-1">
        <div class="bios-screen">
            <header class="bios-header">プライバシーポリシー</header>
            <div class="privacy-content" tabindex="0"></div>
            <footer class="bios-footer">
                <button id="privacy-ok-btn" disabled>OK (Enter)</button>
                <span>(下にスクロールして同意)</span>
            </footer>
        </div>
    </div>

    <div id="minimized-area"></div>
    <div class="snap-indicator" style="display: none;"></div>
    <div id="context-menu-container"></div>

    <template id="console-window-template">
        <div class="window-container console-window">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-console"></span><span class="window-title">database client</span></div><div class="window-controls"><span class="minimize-btn">_</span><span class="maximize-btn">&#10065;</span><span class="close-btn">X</span></div></div>
            <div class="console-body"><div class="console-output"></div><div class="input-line"><span class="prompt"></span><input type="text" class="console-input" spellcheck="false" autocomplete="off" /></div></div>
        </div>
    </template>

    <template id="explorer-window-template">
        <div class="window-container explorer-window">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-explorer"></span><span class="window-title">エクスプローラー</span></div><div class="window-controls"><span class="minimize-btn">_</span><span class="maximize-btn">&#10065;</span><span class="close-btn">X</span></div></div>
            <iframe src="about:blank" class="window-content-frame" name="explorer-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>

    <template id="notepad-window-template">
        <div class="window-container notepad-window">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-notepad"></span><span class="window-title">無題 - メモ帳</span></div><div class="window-controls"><span class="minimize-btn">_</span><span class="maximize-btn">&#10065;</span><span class="close-btn">X</span></div></div>
            <iframe src="about:blank" class="window-content-frame" name="notepad-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>

    <template id="share-window-template">
        <div class="window-container notepad-window" style="width: 640px; height: 480px;">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-share"></span><span class="window-title">共有</span></div><div class="window-controls"><span class="minimize-btn">_</span><span class="maximize-btn">&#10065;</span><span class="close-btn">X</span></div></div>
            <iframe src="" class="window-content-frame" name="share-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>

    <template id="share-manager-window-template">
        <div class="window-container" style="width: 840px; height: 520px;">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-share-manage"></span><span class="window-title">共有の管理</span></div><div class="window-controls"><span class="minimize-btn">_</span><span class="maximize-btn">&#10065;</span><span class="close-btn">X</span></div></div>
            <iframe src="" class="window-content-frame" name="share-manager-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>

    <template id="file_explorer_dialog-window-template">
        <div class="window-container file-explorer-dialog-window">
            <div class="resizer top"></div><div class="resizer right"></div><div class="resizer bottom"></div><div class="resizer left"></div><div class="resizer top-left"></div><div class="resizer top-right"></div><div class="resizer bottom-left"></div><div class="resizer bottom-right"></div>
            <div class="title-bar"><div class="title-bar-text"><span class="title-bar-icon icon-file_explorer_dialog"></span><span class="window-title">ファイルを開く</span></div><div class="window-controls"><span class="close-btn">X</span></div></div>
            <iframe src="" class="window-content-frame" name="file_explorer_dialog-iframe-<?php echo uniqid(); ?>"></iframe>
        </div>
    </template>
    
    <script type="text/javascript">
        const initialClientState = <?php echo json_encode($initialState); ?>;
    </script>
    <script src="js/api.js" type="module"></script>
    <script src="js/contextMenu.js" type="module"></script>
    <script src="js/privacyPolicy.js" type="module"></script>
    <script src="js/windowManager.js" type="module"></script>
    <script src="js/windowSwitcher.js" type="module"></script>
    <script src="js/console.js" type="module"></script>
    <script src="js/main.js" type="module"></script>
</body>
</html>