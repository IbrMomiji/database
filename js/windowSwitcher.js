export class WindowSwitcher {
    constructor(windowManager) {
        this.windowManager = windowManager;
        this.switcherEl = document.getElementById('window-switcher');
        this.windowsContainer = this.switcherEl.querySelector('.windows-container');
        this.windows = [];
        this.selectedIndex = 0;
        this.isVisible = false;
        this.isAltHeld = false;

        this.boundHandleKeyDown = this.handleKeyDown.bind(this);
        this.boundHandleKeyUp = this.handleKeyUp.bind(this);
    }

    show() {
        this.updateWindowList();
        if (this.windows.length === 0) return;

        this.selectedIndex = this.windows.length > 1 ? 1 : 0;
        this.highlightSelection();
        this.switcherEl.style.display = 'flex';
        this.isVisible = true;

        window.addEventListener('keydown', this.boundHandleKeyDown, true);
        window.addEventListener('keyup', this.boundHandleKeyUp, true);
    }

    hide() {
        this.switcherEl.style.display = 'none';
        this.isVisible = false;
        this.isAltHeld = false;
        window.removeEventListener('keydown', this.boundHandleKeyDown, true);
        window.removeEventListener('keyup', this.boundHandleKeyUp, true);
    }

    updateWindowList() {
        this.windowsContainer.innerHTML = '';
        this.windows = [];
        
        const zOrderedIds = [...this.windowManager.zOrder].reverse();

        zOrderedIds.forEach(id => {
            const winData = this.windowManager.windows.get(id);
            if (winData && winData.state !== 'minimized') {
                const winEl = document.createElement('div');
                winEl.className = 'window-preview';
                winEl.id = winData.id;
                
                const iconEl = winData.el.querySelector('.title-bar-icon')?.cloneNode(true) || document.createElement('div');
                iconEl.className = 'icon';
                
                const titleEl = document.createElement('span');
                titleEl.className = 'title';
                titleEl.textContent = winData.el.querySelector('.window-title')?.textContent || winData.type;

                winEl.appendChild(iconEl);
                winEl.appendChild(titleEl);
                this.windowsContainer.appendChild(winEl);
                this.windows.push(winEl);
            }
        });
    }

    navigate(direction) {
        if (!this.isVisible || this.windows.length === 0) return;
        this.selectedIndex = (this.selectedIndex + direction + this.windows.length) % this.windows.length;
        this.highlightSelection();
    }

    highlightSelection() {
        this.windows.forEach((el, index) => {
            el.classList.toggle('selected', index === this.selectedIndex);
        });
    }

    handleKeyDown(e) {
        if (!this.isVisible) return;
        
        if (e.key === 'Tab' || (this.isAltHeld && e.key.toLowerCase() === 'w')) {
            e.preventDefault();
            e.stopPropagation();
            this.navigate(e.shiftKey ? -1 : 1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            this.navigate(1);
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            this.navigate(-1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            this.boundHandleKeyUp({ key: 'Alt', preventDefault: () => {} });
        } else if (e.key === 'Escape') {
             e.preventDefault();
             this.hide();
        }
    }

    handleKeyUp(e) {
        if (e.key === 'Alt') {
            e.preventDefault();
            if (this.isVisible) {
                const selectedWindowElement = this.windows[this.selectedIndex];
                if (selectedWindowElement) {
                    const windowId = selectedWindowElement.id;
                    this.windowManager.focus(windowId);
                }
                this.hide();
            }
            this.isAltHeld = false;
        }
    }
}