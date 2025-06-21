import WindowManager from './windowManager.js';
import { contextMenu } from './contextMenu.js';
import { WindowSwitcher } from './windowSwitcher.js';

const initialClientState = window.initialClientState || {
    history: [],
    prompt: ''
};

class App {
    constructor() {
        this.windowManager = new WindowManager(document.body);
        this.contextMenu = contextMenu;
        this.windowSwitcher = new WindowSwitcher(this.windowManager);
        
        this.contextMenu.initialize(document.getElementById('context-menu-container'));

        this._setupEventListeners();
        this._createInitialWindow();
    }

    _createInitialWindow() {
        this.windowManager.createWindow('console', {
            history: initialClientState.history,
            prompt: initialClientState.prompt,
        });
    }

    _setupEventListeners() {
        const minimizedArea = document.getElementById('minimized-area');
        if (minimizedArea) {
            minimizedArea.addEventListener('click', (e) => {
                const tab = e.target.closest('.minimized-tab');
                if (!tab) return;
                const windowId = tab.dataset.windowId;
                if (windowId) {
                    this.windowManager.restoreWindow(windowId);
                }
            });
        }

        window.addEventListener('keydown', (e) => {
            // --- 1. ウィンドウスイッチャーやコンテキストメニューの表示中は、他の処理を中断 ---
            if (e.altKey && e.key.toLowerCase() === 'w') {
                e.preventDefault();
                if (!this.windowSwitcher.isVisible) {
                    this.windowSwitcher.isAltHeld = true;
                    this.windowSwitcher.show();
                } else if (this.windowSwitcher.isAltHeld) {
                    this.windowSwitcher.navigate(1);
                }
                return;
            }
            if (this.windowSwitcher.isVisible) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.windowSwitcher.hide();
                }
                return; // スイッチャー表示中は他のキーを無効化
            }
            if (this.contextMenu.isVisible()) {
                this.contextMenu.handleKeyPress(e);
                return;
            }

            // --- 2. 現在フォーカスされている場所がテキスト入力欄かどうかを判定 ---
            let isTextInput = false;
            const activeEl = document.activeElement;
            if (activeEl) {
                if (activeEl.tagName === 'IFRAME') {
                    // iframe内にフォーカスがある場合、安全策として入力中とみなす
                    try {
                        const iframeActiveEl = activeEl.contentWindow.document.activeElement;
                        if (iframeActiveEl) {
                            isTextInput = ['INPUT', 'TEXTAREA'].includes(iframeActiveEl.tagName) || iframeActiveEl.isContentEditable;
                        }
                    } catch (err) {
                        isTextInput = true; // アクセスできない場合は入力中と判断
                    }
                } else {
                    isTextInput = ['INPUT', 'TEXTAREA'].includes(activeEl.tagName);
                }
            }
            
            // --- 3. テキスト入力中でなければ、ウィンドウ操作のショートカットを処理 ---
            if (!isTextInput) {
                const topWindow = this.windowManager.getTopWindow();

                // a. ウィンドウ操作 (Ctrl + 矢印)
                if (e.ctrlKey && ['ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                    if (topWindow) {
                        e.preventDefault();
                        if (e.key === 'ArrowUp') this.windowManager.toggleMaximize(topWindow.id);
                        if (e.key === 'ArrowLeft') this.windowManager.snapWindow(topWindow.id, 'left');
                        if (e.key === 'ArrowRight') this.windowManager.snapWindow(topWindow.id, 'right');
                    }
                }
                // b. デスクトップへの文字入力 -> コンソールへフォーカス
                else if (!e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
                    const topConsole = this.windowManager.getTopmostConsole();
                    if (topConsole) {
                        this.windowManager.focus(topConsole.id);
                    }
                }
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new App();
});
