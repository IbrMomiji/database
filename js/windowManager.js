import { contextMenu } from './contextMenu.js';
import { Console } from './console.js';

class WindowManager {
    constructor(container) {
        this.container = container;
        this.windows = new Map();
        this.windowIdCounter = 0;
        this.highestZIndex = 100;
        this.zOrder = [];
        this.snapIndicator = document.querySelector('.snap-indicator');
        this.minimizedArea = document.getElementById('minimized-area');
        this.activeDrag = null;
        this.lastMousePosition = { x: 0, y: 0 };
        this.titleBarHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--titlebar-height'), 10) || 20;

        this._setupGlobalListeners();
        this._setupMessageListener();
    }

    createWindow(type, options = {}) {
        const templateId = `${type}-window-template`;
        const template = document.getElementById(templateId);
        if (!template) {
            const errorMsg = `エラー: ウィンドウテンプレートが見つかりません ('${templateId}')。index.phpを確認してください。`;
            console.error(errorMsg);
            alert(errorMsg);
            return;
        }

        const newWindowEl = template.content.cloneNode(true).querySelector('.window-container');
        const id = `window-${this.windowIdCounter++}`;
        newWindowEl.id = id;

        const iframe = newWindowEl.querySelector('iframe');
        if (iframe) {
            const iframeId = `${type}-iframe-${this.windowIdCounter}`;
            iframe.name = iframeId;
            let srcParams = new URLSearchParams();

            if (type === 'notepad') {
                if (options.filePath) srcParams.append('file', options.filePath);
                iframe.src = `system/application/notepad.php?${srcParams.toString()}`;
            } else if (type === 'explorer') {
                iframe.src = `system/application/explorer.php`;
            } else if (type === 'file_explorer_dialog') {
                srcParams.set('source', options.sourceWindowId);
                srcParams.set('mode', options.mode);
                srcParams.set('path', options.currentPath || '');
                iframe.src = `system/application/file-explorer-dialog.php?${srcParams.toString()}`;
                newWindowEl.querySelector('.window-title').textContent = options.mode === 'open' ? 'ファイルを開く' : '名前を付けて保存';
            }
        }

        const defaultLeft = 60 + (this.windows.size % 10) * 30;
        const defaultTop = 60 + (this.windows.size % 10) * 30;
        newWindowEl.style.left = `${options.left || defaultLeft}px`;
        newWindowEl.style.top = `${options.top || defaultTop}px`;
        if(options.width) newWindowEl.style.width = `${options.width}px`;
        if(options.height) newWindowEl.style.height = `${options.height}px`;


        this.container.appendChild(newWindowEl);

        const windowInstance = { id, el: newWindowEl, type, state: 'normal', prevRect: {}, controller: null };
        if (type === 'console') {
            windowInstance.controller = new Console(newWindowEl, this, options);
        }

        this.windows.set(id, windowInstance);
        this._makeInteractive(windowInstance);
        this.focus(id);
    }

    focus(id) {
        const win = this.windows.get(id);
        if (!win || this.zOrder[this.zOrder.length - 1] === id) return;

        this.bringToFront(id);

        document.querySelectorAll('.window-container.active').forEach(w => w.classList.remove('active'));
        win.el.classList.add('active');

        if (win.controller && typeof win.controller.focus === 'function') {
            win.controller.focus();
        } else {
            const iframe = win.el.querySelector('iframe');
            if (iframe) {
                try {
                    iframe.focus();
                } catch(e) {
                }
            }
        }
    }

    _setupMessageListener() {
        window.addEventListener('message', (event) => {
            if (typeof event.data !== 'object' || event.data === null) return;
            const { type, windowId, sourceWindowId, mode, currentPath, filePath, app, title, itemPath } = event.data;

            try {
                if (type === 'iframeClick' && windowId) {
                    for (const [id, win] of this.windows.entries()) {
                         if(win.el.querySelector('iframe')?.name === windowId){
                             this.focus(id);
                             break;
                         }
                    }
                    return;
                }

                if (type === 'openWithApp') {
                    this.createWindow(app, { filePath: filePath });
                } else if (type === 'openShareWindow') {
                    this.createWindow('share', { itemPath: itemPath });
                } else if (type === 'openShareManager') {
                    this.createWindow('share-manager');
                } else if (type === 'requestFileDialog') {
                    this.createWindow('file_explorer_dialog', { sourceWindowId, mode, currentPath });
                } else if (type === 'fileDialogResponse') {
                     const sourceIframe = document.getElementsByName(sourceWindowId)[0];
                    if (sourceIframe) {
                        sourceIframe.contentWindow.postMessage({ type, filePath, mode, sourceWindowId }, '*');
                    }
                    for (const [id, win] of this.windows.entries()) {
                        if (win.el.querySelector('iframe')?.contentWindow === event.source) {
                            this.closeWindow(id);
                            break;
                        }
                    }
                }
                else if (type === 'setWindowTitle') {
                    for (const [id, win] of this.windows.entries()) {
                         if (win.el.querySelector('iframe')?.contentWindow === event.source) {
                             win.el.querySelector('.window-title').textContent = title || '無題';
                             break;
                         }
                    }
                }
                else if (type === 'closeChildWindow' && windowId) {
                    for (const [id, win] of this.windows.entries()) {
                         if(win.el.querySelector('iframe')?.name === windowId){
                             this.closeWindow(id);
                             break;
                         }
                    }
                }
                 else if(type === 'submitShareForm') {
                     const explorer = Array.from(this.windows.values()).find(w => w.type === 'explorer');
                     if(explorer?.el) {
                         const iframe = explorer.el.querySelector('iframe');
                         if(iframe?.contentWindow) {
                             iframe.contentWindow.postMessage(event.data, '*');
                         }
                     }
                 }
            } catch (error) {
                alert(`ウィンドウ操作中にエラーが発生しました:\n${error.message}`);
                console.error("Window message listener error:", error);
            }
        });
    }

    closeWindow(id) {
        const win = this.windows.get(id);
        if (win) {
            win.el.remove();
            this.windows.delete(id);
            this.zOrder = this.zOrder.filter(winId => winId !== id);
        }

        const nextWindow = this.getTopWindow();
        if (nextWindow) {
            this.focus(nextWindow.id);
        } else if (this.windows.size === 0 && document.querySelectorAll('.window-container').length === 0) {
            this.createWindow('console');
        }
    }

    _makeInteractive(winInstance) {
        const { id, el } = winInstance;
        const titleBar = el.querySelector('.title-bar');

        const onIconClick = (e) => {
             const rect = titleBar.getBoundingClientRect();
             this._showTitleBarContextMenu(e, winInstance, {x: rect.left, y: rect.bottom});
        };

        const onTitleBarContextMenu = (e) => {
            this._showTitleBarContextMenu(e, winInstance, {x: e.clientX, y: e.clientY});
        };

        const onMouseDown = () => this.focus(id);
        const onTitleBarMouseDown = e => this._onDragStart(e, winInstance);
        const onTitleBarDblClick = e => {
            if (!e.target.closest('.window-controls') && !e.target.closest('.title-bar-icon')) {
                this.toggleMaximize(id);
            }
        };
        const onResizerMouseDown = e => this._onResizeStart(e, winInstance);

        const icon = el.querySelector('.title-bar-icon');
        if(icon) icon.addEventListener('click', onIconClick);
        titleBar.addEventListener('contextmenu', onTitleBarContextMenu);
        el.addEventListener('mousedown', onMouseDown, { capture: true });
        titleBar.addEventListener('mousedown', onTitleBarMouseDown);
        titleBar.addEventListener('dblclick', onTitleBarDblClick);

        el.querySelector('.close-btn').addEventListener('click', () => this.closeWindow(id));
        const minimizeBtn = el.querySelector('.minimize-btn');
        if (minimizeBtn) minimizeBtn.addEventListener('click', () => this.minimizeWindow(id));
        const maximizeBtn = el.querySelector('.maximize-btn');
        if (maximizeBtn) maximizeBtn.addEventListener('click', () => this.toggleMaximize(id));

        el.querySelectorAll('.resizer').forEach(resizer => {
            resizer.addEventListener('mousedown', onResizerMouseDown);
        });
    }

    minimizeWindow(id) {
        const winData = this.windows.get(id);
        if (!winData || winData.state === 'minimized') return;

        winData.state = 'minimized';
        winData.el.style.display = 'none';
        this.zOrder = this.zOrder.filter(winId => winId !== id);

        const minimizedTab = document.createElement('div');
        minimizedTab.className = 'minimized-tab';
        minimizedTab.textContent = winData.el.querySelector('.window-title').textContent;
        minimizedTab.dataset.windowId = id;
        this.minimizedArea.appendChild(minimizedTab);

        const nextWindow = this.getTopWindow();
        if (nextWindow) {
            this.focus(nextWindow.id);
        }
    }

    restoreWindow(id) {
        const winData = this.windows.get(id);
        const tab = this.minimizedArea.querySelector(`.minimized-tab[data-window-id="${id}"]`);

        if (!winData) return;

        winData.state = 'normal';
        winData.el.style.display = 'flex';
        this.zOrder.push(id);

        if (tab) {
            tab.remove();
        }

        this.focus(id);
    }
    
    _saveOriginalState(winData) {
        if (winData.state !== 'normal') return;
        const el = winData.el;
        winData.prevRect = {
            top: `${el.offsetTop}px`,
            left: `${el.offsetLeft}px`,
            width: `${el.offsetWidth}px`,
            height: `${el.offsetHeight}px`
        };
    }

    toggleMaximize(id) {
        const winData = this.windows.get(id);
        if (!winData) return;

        if (winData.state === 'maximized') {
            this._restoreToPrevious(winData);
        } else {
            this._saveOriginalState(winData);
            this.maximize(id);
        }
        this.focus(id);
    }

    maximize(id) {
        const winData = this.windows.get(id);
        if (winData.state !== 'maximized') {
            this._saveOriginalState(winData);
        }
        winData.el.classList.remove('snapped-left', 'snapped-right');
        winData.el.classList.add('maximized');
        winData.state = 'maximized';
    }

    snapWindow(id, type) {
        const winData = this.windows.get(id);
        if (!winData) return;
        const newSnapState = `snapped-${type}`;

        if (winData.state === newSnapState) {
            this._restoreToPrevious(winData);
        } else {
            this._saveOriginalState(winData);
            winData.el.classList.remove('maximized', 'snapped-left', 'snapped-right');
            winData.el.classList.add(newSnapState);
            winData.state = newSnapState;
        }
        this.focus(id);
    }
    
    _restoreToPrevious(winData) {
        if (!winData.prevRect || Object.keys(winData.prevRect).length === 0) return;
        const pos = winData.prevRect;
        winData.el.classList.remove('maximized', 'snapped-left', 'snapped-right');
        winData.el.style.top = pos.top;
        winData.el.style.left = pos.left;
        winData.el.style.width = pos.width;
        winData.el.style.height = pos.height;
        winData.state = 'normal';
        winData.prevRect = {};
    }

    bringToFront(id) {
        const win = this.windows.get(id);
        if (!win) return;

        win.el.style.zIndex = ++this.highestZIndex;
        this.zOrder = this.zOrder.filter(winId => winId !== id);
        this.zOrder.push(id);
    }

    _showTitleBarContextMenu(e, winInstance, coords) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        if (e && typeof e.stopPropagation === 'function') e.stopPropagation();

        const { id, el } = winInstance;
        const winData = this.windows.get(id);
        if (!winData) return;

        const { state } = winData;
        const isMaximizedOrSnapped = state === 'maximized' || state.startsWith('snapped');

        let menuItems = [
            { label: '移動', accelerator: 'M', action: () => {
                const titleBar = el.querySelector('.title-bar');
                const {x, y} = this.lastMousePosition;
                titleBar.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, clientX: x, clientY: y }));
            }},
            { label: 'サイズ変更', accelerator: 'S', action: () => {
                const resizer = el.querySelector('.resizer.bottom-right');
                const {x, y} = this.lastMousePosition;
                resizer.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, clientX: x, clientY: y }));
            }},
            { type: 'separator' },
            { label: isMaximizedOrSnapped ? '元に戻す' : '最大化', accelerator: 'X', action: () => this.toggleMaximize(id) },
            { type: 'separator' },
            { label: '閉じる', accelerator: 'C', action: () => this.closeWindow(id) },
        ];

        if (el.querySelector('.minimize-btn')) {
             menuItems.splice(3, 0, { label: '最小化', accelerator: 'N', action: () => this.minimizeWindow(id) });
        }

        contextMenu.show(coords.x, coords.y, menuItems, winInstance);
    }

    _onDragStart(e, winInstance) {
        if (e.target.closest('.window-controls') || e.target.closest('.title-bar-icon') || e.button !== 0) return;
        e.preventDefault();
        this.focus(winInstance.id);

        this.activeDrag = {
            type: 'move',
            winInstance,
            offsetX: e.clientX - winInstance.el.offsetLeft,
            offsetY: e.clientY - winInstance.el.offsetTop,
            initialState: winInstance.state
        };
    }

    _onResizeStart(e, winInstance) {
        e.preventDefault();
        this.focus(winInstance.id);

        if (winInstance.state !== 'normal') {
            this._restoreToPrevious(winInstance);
        }

        this.activeDrag = {
            type: 'resize',
            winInstance,
            resizer: e.target,
            startX: e.clientX,
            startY: e.clientY,
            startRect: winInstance.el.getBoundingClientRect(),
        };
    }

    _setupGlobalListeners() {
        const onMouseMove = (e) => {
            this.lastMousePosition = { x: e.clientX, y: e.clientY };
            if (!this.activeDrag) return;

            if (this.activeDrag.type === 'move') {
                this._handleDragging(e);
            } else if (this.activeDrag.type === 'resize') {
                this._handleResizing(e);
            }
        };

        const onMouseUp = () => {
            if (!this.activeDrag) return;

            if (this.activeDrag.type === 'move') {
                this._hideSnapIndicator();
                const snapType = this.activeDrag.winInstance.el.dataset.snapType;
                if (snapType) {
                    const winId = this.activeDrag.winInstance.id;
                    if (snapType === 'top') {
                        this.maximize(winId);
                    } else {
                        this.snapWindow(winId, snapType);
                    }
                }
            } else if (this.activeDrag.type === 'resize') {
                 const { el } = this.activeDrag.winInstance;
                 this.windows.get(this.activeDrag.winInstance.id).prevRect = {
                     top: el.style.top, left: el.style.left,
                     width: el.style.width, height: el.style.height
                 };
            }

            this.focus(this.activeDrag.winInstance.id);
            this.activeDrag = null;
        };

        const onKeyDown = (e) => {
            const activeTag = document.activeElement.tagName;
            const exceptions = ['INPUT', 'TEXTAREA', 'SELECT'];
            if (exceptions.includes(activeTag)) {
                return;
            }

            if (activeTag === 'IFRAME') {
                try {
                    const iframeActiveTag = document.activeElement.contentDocument.activeElement.tagName;
                    if (exceptions.includes(iframeActiveTag)) {
                        return;
                    }
                } catch (err) {
                    return;
                }
            }

            const topConsole = this.getTopmostConsole();
            if (topConsole?.controller) {
                e.preventDefault();
                topConsole.controller.handleExternalKey(e);
            }
        };

        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        window.addEventListener('resize', () => this._hideSnapIndicator());
    }

    _handleDragging(e) {
        const { winInstance, initialState } = this.activeDrag;
        const { clientX, clientY } = e;

        if (initialState !== 'normal' && winInstance.state !== 'normal') {
             const prevWidth = parseFloat((winInstance.prevRect?.width || '700px').replace('px', ''));
             this._restoreToPrevious(winInstance);
             this.activeDrag.offsetX = prevWidth * (clientX / window.innerWidth);
             this.activeDrag.offsetY = this.titleBarHeight / 2;
        }

        const newTop = Math.max(0, clientY - this.activeDrag.offsetY);
        winInstance.el.style.left = `${clientX - this.activeDrag.offsetX}px`;
        winInstance.el.style.top = `${newTop}px`;

        const snapMargin = 1;
        let snapType = null;
        if (e.buttons !== 1) { 
            this._hideSnapIndicator();
            return;
        }

        if (clientY <= snapMargin) snapType = 'top';
        else if (clientX <= snapMargin) snapType = 'left';
        else if (clientX >= window.innerWidth - snapMargin) snapType = 'right';

        if (snapType) {
            this._showSnapIndicator(snapType);
            winInstance.el.dataset.snapType = snapType;
        } else {
            this._hideSnapIndicator();
            delete winInstance.el.dataset.snapType;
        }
    }

    _handleResizing(e) {
        const { winInstance, resizer, startX, startY, startRect } = this.activeDrag;
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;

        let { top, left, width, height } = startRect;

        if (resizer.classList.contains('right')) width += dx;
        if (resizer.classList.contains('bottom')) height += dy;
        if (resizer.classList.contains('left')) { width -= dx; left += dx; }
        if (resizer.classList.contains('top')) { height -= dy; top += dy; }
        if (resizer.classList.contains('top-left')) { width -= dx; left += dx; height -= dy; top += dy; }
        if (resizer.classList.contains('top-right')) { width += dx; height -= dy; top += dy; }
        if (resizer.classList.contains('bottom-left')) { width -= dx; left += dx; height += dy; }
        if (resizer.classList.contains('bottom-right')) { width += dx; height += dy; }

        const minW = parseInt(getComputedStyle(winInstance.el).minWidth) || 200;
        const minH = parseInt(getComputedStyle(winInstance.el).minHeight) || 150;

        if(width >= minW) {
            winInstance.el.style.width = width + 'px';
            winInstance.el.style.left = left + 'px';
        }
        if(height >= minH) {
            winInstance.el.style.height = height + 'px';
            winInstance.el.style.top = top + 'px';
        }
    }

    _showSnapIndicator(type) {
        const { innerWidth: w, innerHeight: h } = window;
        const taskbarHeight = 30;
        this.snapIndicator.style.display = 'block';
        if (type === "left") Object.assign(this.snapIndicator.style, { left: '0px', top: '0px', width: w / 2 + 'px', height: h - taskbarHeight + 'px' });
        else if (type === "right") Object.assign(this.snapIndicator.style, { left: w / 2 + 'px', top: '0px', width: w / 2 + 'px', height: h - taskbarHeight + 'px' });
        else if (type === "top") Object.assign(this.snapIndicator.style, { left: '0px', top: '0px', width: w + 'px', height: h - taskbarHeight + 'px' });
    }

    _hideSnapIndicator() {
        this.snapIndicator.style.display = 'none';
    }

    getTopWindow() {
        if (this.zOrder.length === 0) return null;
        const topWindowId = this.zOrder[this.zOrder.length - 1];
        return this.windows.get(topWindowId) || null;
    }

    getTopmostConsole() {
        for (let i = this.zOrder.length - 1; i >= 0; i--) {
            const winId = this.zOrder[i];
            const win = this.windows.get(winId);
            if (win && win.type === 'console' && win.state !== 'minimized') {
                return win;
            }
        }
        return null;
    }
}

export default WindowManager;