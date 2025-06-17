import WindowManager from './windowManager.js';
import { contextMenu } from './contextMenu.js';

// 追加: 初期状態が未定義なら仮のデータを用意
const initialClientState = window.initialClientState || {
    history: [],
    prompt: ''
};

class App {
    constructor() {
        this.windowManager = new WindowManager(document.body);
        this.contextMenu = contextMenu;
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

        document.addEventListener('keydown', (e) => {
            if (this.contextMenu.isVisible()) {
                this.contextMenu.handleKeyPress(e);
                return;
            }

            const topWindow = this.windowManager.getTopWindow();

            // 入力欄やtextareaであれば何もしない
            const activeEl = document.activeElement;
            const isTextInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA');

            if (!topWindow) {
                // フォーカスできるウィンドウがない場合
                if (!isTextInput) {
                    if (!e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1 && this.windowManager.windows.size > 0) {
                        const anyConsole = Array.from(this.windowManager.windows.values()).find(w => w.type === 'console');
                        if (anyConsole && anyConsole.controller && typeof anyConsole.controller.focus === 'function') {
                            anyConsole.controller.focus();
                        }
                    }
                }
                return;
            }

            // ウィンドウ操作のショートカット
            if (e.ctrlKey && ['ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                e.preventDefault();
                if (e.key === 'ArrowUp') this.windowManager.toggleMaximize(topWindow.id);
                if (e.key === 'ArrowLeft') this.windowManager.snapWindow(topWindow.id, 'left');
                if (e.key === 'ArrowRight') this.windowManager.snapWindow(topWindow.id, 'right');
            }
            // キー入力を最前面のウィンドウの入力欄にフォーカスする
            else if (!isTextInput) {
                if (!e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
                    if (topWindow.controller && typeof topWindow.controller.focus === 'function') {
                        topWindow.controller.focus();
                    }
                }
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new App();
});