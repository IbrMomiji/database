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
            if (type === 'notepad') {
                const params = new URLSearchParams();
                if (options.filePath) {
                    params.append('file', options.filePath);
                }
                iframe.src = `system/application/notepad.php?${params.toString()}`;
            } else if (type === 'explorer') { // エクスプローラーのパスを追加
                 iframe.src = `system/application/explorer.php`;
            } else if (type === 'file_explorer_dialog') {
                const params = new URLSearchParams({
                    source: options.sourceWindowId,
                    mode: options.mode,
                    path: options.currentPath || ''
                });
                iframe.src = `system/application/file-explorer-dialog.php?${params.toString()}`;
                newWindowEl.querySelector('.window-title').textContent = options.mode === 'open' ? 'ファイルを開く' : '名前を付けて保存';
                newWindowEl.style.width = '580px';
                newWindowEl.style.height = '420px';
            }
        }
        
        newWindowEl.style.left = `${60 + this.windows.size * 30}px`;
        newWindowEl.style.top = `${60 + this.windows.size * 30}px`;
        
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
        this.bringToFront(id);
        const win = this.windows.get(id);
        if (!win) return;

        if (win.controller && typeof win.controller.focus === 'function') {
            win.controller.focus();
        } else {
            const iframe = win.el.querySelector('iframe');
            if (iframe) {
                iframe.focus();
            }
        }
    }

    _setupMessageListener() {
        window.addEventListener('message', (event) => {
            try {
                if (typeof event.data !== 'object' || event.data === null) {
                    return;
                }
                
                const { type, sourceWindowId, mode, currentPath, filePath, app, windowId, title } = event.data;

                if (type === 'openWithApp') {
                    if (app) {
                        this.createWindow(app, { filePath: filePath });
                    } else {
                        throw new Error("Message 'openWithApp' requires an 'app' property.");
                    }
                } else if (type === 'requestFileDialog') {
                    this.createWindow('file_explorer_dialog', { sourceWindowId, mode, currentPath });
                } else if (type === 'fileDialogResponse') {
                    for (const [id, win] of this.windows.entries()) {
                        const iframe = win.el.querySelector('iframe');
                        if (iframe && iframe.contentWindow === event.source) {
                            this.closeWindow(id);
                            break;
                        }
                    }
                    const sourceIframe = document.getElementsByName(sourceWindowId)[0];
                    if (sourceIframe) {
                        sourceIframe.contentWindow.postMessage({ type, filePath, mode, sourceWindowId }, '*');
                    }
                }
                else if (type === 'setWindowTitle') {
                    for (const [id, win] of this.windows.entries()) {
                        const iframe = win.el.querySelector('iframe');
                        if (iframe && iframe.contentWindow === event.source) {
                             const titleEl = win.el.querySelector('.window-title');
                             if (titleEl && title) {
                                 titleEl.textContent = title;
                             }
                            break;
                        }
                    }
                }
                else if (type === 'closeChildWindow' && windowId) {
                    for (const [id, win] of this.windows.entries()) {
                         const iframe = win.el.querySelector('iframe');
                         if(iframe && iframe.name === windowId){
                             this.closeWindow(id);
                             break;
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

        if (!winData || winData.state !== 'minimized') return;

        winData.state = 'normal';
        winData.el.style.display = '';
        this.zOrder.push(id);

        if (tab) {
            tab.remove();
        }

        this.focus(id);
    }

    toggleMaximize(id) {
        const winData = this.windows.get(id);
        if (!winData) return;
        const win = winData.el;
        const maximizeBtn = win.querySelector('.maximize-btn');

        if (winData.state === 'maximized' || winData.state.startsWith('snapped')) {
            const pos = winData.prevRect || {};
            win.classList.remove('maximized', 'snapped-left', 'snapped-right');
            win.style.top = pos.top || '50px';
            win.style.left = pos.left || '50px';
            win.style.width = pos.width || '700px';
            win.style.height = pos.height || '450px';
            if(maximizeBtn) maximizeBtn.innerHTML = '&#10065;';
            winData.state = 'normal';
        } else {
            winData.prevRect = { top: win.style.top, left: win.style.left, width: win.style.width, height: win.style.height };
            win.classList.add('maximized');
            if(maximizeBtn) maximizeBtn.innerHTML = '&#10066;';
            winData.state = 'maximized';
        }
    }

    snapWindow(id, type) {
        const winData = this.windows.get(id);
        if (!winData) return;
        const win = winData.el;
        
        if (winData.state === 'normal') {
            winData.prevRect = {
                top: win.style.top, left: win.style.left,
                width: win.style.width, height: win.style.height
            };
        }

        win.classList.remove('maximized', 'snapped-left', 'snapped-right');
        
        if (type === 'top') {
            this.toggleMaximize(id);
        } else {
            win.classList.add(type === 'left' ? 'snapped-left' : 'snapped-right');
            winData.state = `snapped-${type}`;
            const maximizeBtn = win.querySelector('.maximize-btn');
            if(maximizeBtn) maximizeBtn.innerHTML = '&#10065;';
        }
    }
    
    bringToFront(id) {
        const win = this.windows.get(id);
        if (!win) return;
        
        win.el.style.zIndex = ++this.highestZIndex;
        this.zOrder = this.zOrder.filter(winId => winId !== id);
        this.zOrder.push(id);
    }
    
    _showTitleBarContextMenu(e, winInstance, coords) {
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
        }
        if (e && typeof e.stopPropagation === 'function') {
            e.stopPropagation();
        }
        
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
        
        let dragOffsetX, dragOffsetY;

        if (winInstance.state === 'maximized' || winInstance.state.startsWith('snapped')) {
            const prevWidth = parseFloat(winInstance.prevRect?.width) || 700;
            const newLeft = e.clientX - (prevWidth * (e.clientX / window.innerWidth));
            this.toggleMaximize(winInstance.id);
            
            winInstance.el.style.left = `${newLeft}px`;
            winInstance.el.style.top = `${e.clientY - 15}px`;
            dragOffsetX = e.clientX - newLeft;
            dragOffsetY = e.clientY - parseFloat(winInstance.el.style.top);
        } else {
            dragOffsetX = e.clientX - winInstance.el.offsetLeft;
            dragOffsetY = e.clientY - winInstance.el.offsetTop;
        }

        this.activeDrag = {
            type: 'drag', winInstance, offsetX: dragOffsetX, offsetY: dragOffsetY,
        };
    }

    _onResizeStart(e, winInstance) {
        e.preventDefault();
        this.focus(winInstance.id);
        
        if (winInstance.state !== 'normal') {
            this.toggleMaximize(winInstance.id);
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

            if (this.activeDrag.type === 'drag') {
                this._handleDragging(e);
            } else if (this.activeDrag.type === 'resize') {
                this._handleResizing(e);
            }
        };

        const onMouseUp = () => {
            if (!this.activeDrag) return;

            if (this.activeDrag.type === 'drag') {
                this._hideSnapIndicator();
                const snapType = this.activeDrag.winInstance.el.dataset.snapType;
                if (snapType) {
                    this.snapWindow(this.activeDrag.winInstance.id, snapType);
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
        
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        window.addEventListener('resize', () => this._hideSnapIndicator());
    }

    _handleDragging(e) {
        const { winInstance, offsetX, offsetY } = this.activeDrag;
        const { clientX, clientY } = e;
        const snapMargin = 5;
        let snapType = null;
        
        if (clientY <= snapMargin) snapType = 'top';
        else if (clientX <= snapMargin) snapType = 'left';
        else if (clientX >= window.innerWidth - snapMargin) snapType = 'right';

        if (snapType) {
            this._showSnapIndicator(snapType);
            winInstance.el.dataset.snapType = snapType;
        } else {
            this._hideSnapIndicator();
            winInstance.el.dataset.snapType = '';
            winInstance.el.style.left = `${clientX - offsetX}px`;
            winInstance.el.style.top = `${clientY - offsetY}px`;
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

        if(width > minW) {
            winInstance.el.style.width = width + 'px';
            winInstance.el.style.left = left + 'px';
        }
        if(height > minH) {
            winInstance.el.style.height = height + 'px';
            winInstance.el.style.top = top + 'px';
        }
    }
    
    _showSnapIndicator(type) {
        const { innerWidth: w, innerHeight: h } = window;
        this.snapIndicator.style.display = 'block';
        if (type === "left") Object.assign(this.snapIndicator.style, { left: '0px', top: '0px', width: w / 2 + 'px', height: h + 'px' });
        else if (type === "right") Object.assign(this.snapIndicator.style, { left: w / 2 + 'px', top: '0px', width: w / 2 + 'px', height: h + 'px' });
        else if (type === "top") Object.assign(this.snapIndicator.style, { left: '0px', top: '0px', width: w + 'px', height: h + 'px' });
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
            if (win && win.type === 'console') {
                return win;
            }
        }
        return null;
    }
}

export default WindowManager;
