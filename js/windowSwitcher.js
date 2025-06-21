/**
 * Alt+Wによるウィンドウ切り替え機能のUIとロジックを管理します。
 */
export class WindowSwitcher {
    /**
     * @param {WindowManager} windowManager WindowManagerのインスタンス
     */
    constructor(windowManager) {
        this.windowManager = windowManager;
        this.switcherElement = null;
        this.listElement = null;

        this.isVisible = false;
        this.windows = [];
        this.selectedIndex = 0;
        this.isAltHeld = false;

        this._boundHandleKeyUp = this.handleKeyUp.bind(this);
    }

    show() {
        const windowIds = Array.from(this.windowManager.zOrder || []).reverse();
        
        if (windowIds.length <= 1) {
            return;
        }
        
        this.windows = windowIds
            .map(id => {
                const winObject = this.windowManager.windows.get(id);
                return (winObject && winObject.el) ? winObject.el : null;
            })
            .filter(el => el !== null);

        if (this.windows.length <= 1) {
            return;
        }

        if (!this.switcherElement) {
            this._createSwitcherElement();
        }

        this.listElement.innerHTML = '';

        this.windows.forEach(winEl => {
            const item = document.createElement('li');
            item.className = 'window-switcher-item';
            item.dataset.windowId = winEl.id;

            const iconContainer = document.createElement('div');
            iconContainer.className = 'icon';
            const originalIcon = winEl.querySelector('.title-bar-icon');
            if (originalIcon) {
                const iconClone = originalIcon.cloneNode(true);
                iconContainer.appendChild(iconClone);
            }

            const title = document.createElement('div');
            title.className = 'title';
            title.textContent = winEl.querySelector('.window-title')?.textContent || '無題のウィンドウ';

            item.appendChild(iconContainer);
            item.appendChild(title);
            
            item.addEventListener('click', () => {
               this.windowManager.focus(winEl.id);
               this.hide();
            });

            this.listElement.appendChild(item);
        });

        this.selectedIndex = 1;
        this.updateSelection();
        this.switcherElement.style.display = 'flex';
        this.isVisible = true;

        document.addEventListener('keyup', this._boundHandleKeyUp);
    }

    hide() {
        if (!this.isVisible) return;
        if (this.switcherElement) {
            this.switcherElement.style.display = 'none';
        }
        this.isVisible = false;
        this.isAltHeld = false;
        document.removeEventListener('keyup', this._boundHandleKeyUp);
    }

    handleKeyUp(e) {
        if (e.key === 'Alt') {
            e.preventDefault();
            if (this.isVisible) {
                const selectedWindowElement = this.windows[this.selectedIndex];
                if (selectedWindowElement) {
                    const windowId = selectedWindowElement.id;
                    
                    // ウィンドウを最前面に表示
                    this.windowManager.bringToFront(windowId);

                    // --- NEW LOGIC ---
                    // iframeを持つウィンドウを選択した場合、iframeからフォーカスを外し
                    // 親ウィンドウに制御を戻すことで、後続のショートカットが効くようにする。
                    const winData = this.windowManager.windows.get(windowId);
                    if (winData && winData.type !== 'console') {
                        const iframe = winData.el.querySelector('iframe');
                        if (iframe) {
                           iframe.blur(); // iframeのフォーカスを外す
                           window.focus(); // 親ウィンドウにフォーカスを戻す
                        }
                    } else if (winData) {
                        // コンソールウィンドウの場合は、通常通りフォーカスする
                        this.windowManager.focus(windowId);
                    }
                }
                this.hide();
            }
        }
    }

    navigate(direction) {
        if (!this.isVisible) return;
        const numWindows = this.windows.length;
        if (numWindows === 0) return;
        
        this.selectedIndex = (this.selectedIndex + direction + numWindows) % numWindows;
        this.updateSelection();
    }

    updateSelection() {
        Array.from(this.listElement.children).forEach((item, index) => {
            if (index === this.selectedIndex) {
                item.classList.add('selected');
                item.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            } else {
                item.classList.remove('selected');
            }
        });
    }
    
    _createSwitcherElement() {
        const overlay = document.createElement('div');
        overlay.id = 'window-switcher';
        overlay.className = 'window-switcher-overlay';
        overlay.style.display = 'none';

        const list = document.createElement('ul');
        list.id = 'window-switcher-list';
        
        overlay.appendChild(list);
        document.body.appendChild(overlay);

        this.switcherElement = overlay;
        this.listElement = list;
    }
}
