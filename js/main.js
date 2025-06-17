import WindowManager from './windowManager.js';
import { contextMenu } from './contextMenu.js';

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
        minimizedArea.addEventListener('click', (e) => {
            const tab = e.target.closest('.minimized-tab');
            if (!tab) return;
            const windowId = tab.dataset.windowId;
            if (windowId) {
                this.windowManager.restoreWindow(windowId);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (this.contextMenu.isVisible()) {
                this.contextMenu.handleKeyPress(e);
                return;
            }

            const topWindow = this.windowManager.getTopWindow();
            if (!topWindow) {
                // フォーカスできるウィンドウがない場合でも、特定のキー入力を受け付ける
                const activeEl = document.activeElement;
                 if (!activeEl || (activeEl.tagName !== 'INPUT' && activeEl.tagName !== 'TEXTAREA')) {
                     if (!e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1 && this.windowManager.windows.size > 0) {
                         const anyConsole = Array.from(this.windowManager.windows.values()).find(w => w.type === 'console');
                         if(anyConsole) anyConsole.controller?.focus();
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
            else {
                const activeEl = document.activeElement;
                if (!activeEl || (activeEl.tagName !== 'INPUT' && activeEl.tagName !== 'TEXTAREA')) {
                     if (!e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
                         topWindow.controller?.focus();
                    }
                }
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new App();
});
