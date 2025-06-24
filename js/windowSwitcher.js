export class WindowSwitcher {
    constructor(windowManager) {
        this.windowManager = windowManager;
        this.switcherEl = document.getElementById('window-switcher');
        this.switcherEl.classList.add('window-switcher-overlay');
        this.switcherEl.innerHTML = '';
        this.windowsListEl = document.createElement('ul');
        this.windowsListEl.id = 'window-switcher-list';
        this.switcherEl.appendChild(this.windowsListEl);
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
        this.windowsListEl.innerHTML = '';
        this.windows = [];
        const zOrderedIds = [...this.windowManager.zOrder].reverse();

        zOrderedIds.forEach(id => {
            const winData = this.windowManager.windows.get(id);
            if (winData && winData.state !== 'minimized' && winData.el) {
                const winItemEl = document.createElement('li');
                winItemEl.className = 'window-switcher-item';
                winItemEl.dataset.windowId = winData.id;

                const thumbnailContainer = document.createElement('div');
                thumbnailContainer.className = 'thumbnail';

                const windowClone = winData.el.cloneNode(true);
                windowClone.className = 'window-clone';
                windowClone.removeAttribute('id');
                windowClone.classList.remove('is-active');

                const thumbnailWidth = 184;
                const thumbnailHeight = 110;
                const windowRect = winData.el.getBoundingClientRect();

                if (windowRect.width > 0 && windowRect.height > 0) {
                    const scaleX = thumbnailWidth / windowRect.width;
                    const scaleY = thumbnailHeight / windowRect.height;
                    const scale = Math.min(scaleX, scaleY);
                    const scaledWidth = windowRect.width * scale;
                    const scaledHeight = windowRect.height * scale;
                    const offsetX = (thumbnailWidth - scaledWidth) / 2;
                    const offsetY = (thumbnailHeight - scaledHeight) / 2;

                    windowClone.style.transform = `scale(${scale})`;
                    windowClone.style.top = `${offsetY}px`;
                    windowClone.style.left = `${offsetX}px`;
                }

                const titleEl = document.createElement('div');
                titleEl.className = 'title';
                titleEl.textContent = winData.el.querySelector('.window-title')?.textContent || winData.type;

                thumbnailContainer.appendChild(windowClone);
                winItemEl.appendChild(thumbnailContainer);
                winItemEl.appendChild(titleEl);
                this.windowsListEl.appendChild(winItemEl);
                this.windows.push(winItemEl);
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
        const selectedEl = this.windows[this.selectedIndex];
        if (selectedEl) {
            selectedEl.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
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
                    const windowId = selectedWindowElement.dataset.windowId;
                    this.windowManager.focus(windowId);
                }
                this.hide();
            }
            this.isAltHeld = false;
        }
    }
}