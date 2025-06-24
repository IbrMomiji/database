class ContextMenu {
    constructor() {
        this.container = null;
        this.menuEl = null;
        this.targetWindow = null;
    }

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
    
    show(x, y, items, targetWindow) {
        this.hide();

        this.targetWindow = targetWindow;
        this.menuEl = document.createElement('div');
        this.menuEl.className = 'custom-context-menu';

        if (targetWindow && targetWindow.el) {
            const titleBar = targetWindow.el.querySelector('.title-bar');
            if (titleBar) {
                const bgColor = window.getComputedStyle(titleBar).backgroundColor;
                
                const getBrightness = (rgbColor) => {
                    const rgb = rgbColor.match(/\d+/g).map(Number);
                    return (rgb[0] * 299 + rgb[1] * 587 + rgb[2] * 114) / 1000;
                };

                const brightness = getBrightness(bgColor);
                
                if (brightness > 128) {
                    this.menuEl.classList.add('light');
                }
            }
        }

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

    isVisible() {
        return !!this.menuEl;
    }

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