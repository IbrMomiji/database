class ContextMenu {
    constructor() {
        this.container = null; // メニューを描画するコンテナ要素
        this.menuEl = null;    // 現在表示中のメニュー要素
        this.targetWindow = null; // メニューが表示されている対象のウィンドウ
    }

    /**
     * 初期化メソッド
     * @param {HTMLElement} container - メニューを配置するコンテナ
     */
    initialize(container) {
        this.container = container;
        
        document.addEventListener('click', (e) => {
            if (this.menuEl && !this.menuEl.contains(e.target)) {
                this.hide();
            }
        });
        
        document.addEventListener('contextmenu', (e) => {
             if (this.menuEl && !this.menuEl.contains(e.target)) {
                 this.hide();
            }
        });
    }
    
    /**
     * メニューを表示する
     * @param {number} x - 表示するx座標
     * @param {number} y - 表示するy座標
     * @param {Array<object>} items - メニュー項目の配列
     * @param {object} targetWindow - メニューの対象となるウィンドウインスタンス
     */
    show(x, y, items, targetWindow) {
        this.hide();

        this.targetWindow = targetWindow;
        this.menuEl = document.createElement('div');
        this.menuEl.className = 'custom-context-menu';

        items.forEach(item => {
            if (item.type === 'separator') {
                const separator = document.createElement('div');
                separator.className = 'separator';
                this.menuEl.appendChild(separator);
            } else {
                const menuItem = document.createElement('div');
                menuItem.className = 'menu-item';
                
                const labelSpan = document.createElement('span');
                labelSpan.textContent = item.label;
                menuItem.appendChild(labelSpan);

                if (item.accelerator) {
                    const acceleratorSpan = document.createElement('span');
                    acceleratorSpan.innerHTML = `<u>${item.accelerator}</u>`;
                    menuItem.appendChild(acceleratorSpan);
                }

                menuItem.addEventListener('click', () => {
                    item.action();
                    this.hide();
                });
                this.menuEl.appendChild(menuItem);
            }
        });

        this.container.appendChild(this.menuEl);
        
        const menuRect = this.menuEl.getBoundingClientRect();
        this.menuEl.style.left = `${Math.min(x, window.innerWidth - menuRect.width)}px`;
        this.menuEl.style.top = `${Math.min(y, window.innerHeight - menuRect.height)}px`;
    }

    hide() {
        if (this.menuEl) {
            this.menuEl.remove();
            this.menuEl = null;
            this.targetWindow = null;
        }
    }

    /**
     * メニューが表示されているか
     * @returns {boolean}
     */
    isVisible() {
        return !!this.menuEl;
    }

    /**
     * メニュー表示中のキープレスを処理する
     * @param {KeyboardEvent} e
     */
    handleKeyPress(e) {
        if (!this.isVisible()) return;

        if (e.key === 'Escape') {
            this.hide();
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        
        const key = e.key.toUpperCase();
        const itemEl = Array.from(this.menuEl.querySelectorAll('.menu-item')).find(el => {
            const u = el.querySelector('u');
            return u && u.textContent.toUpperCase() === key;
        });

        if (itemEl) {
            e.preventDefault();
            e.stopPropagation();
            itemEl.click();
        }
    }
}

export const contextMenu = new ContextMenu();
