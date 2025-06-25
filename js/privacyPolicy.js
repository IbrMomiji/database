import { postCommand } from './api.js';

export class PrivacyPolicyManager {
    constructor(consoleInstance) {
        this.console = consoleInstance;
        this.overlay = document.getElementById('privacy-policy-overlay');
        this.contentEl = this.overlay.querySelector('.privacy-content');
        this.okBtn = this.overlay.querySelector('#privacy-ok-btn');
        
        this.resolvePromise = null;
        this._setupListeners();
    }

    async show() {
        try {
            const response = await fetch('index.php?action=get_privacy_policy');
            if (!response.ok) throw new Error('プライバシーポリシーを読み込めません。');
            const htmlString = await response.text();

            const parser = new DOMParser();
            const doc = parser.parseFromString(htmlString, 'text/html');
            
            this.contentEl.innerHTML = '';
            
            while (doc.body.firstChild) {
                this.contentEl.appendChild(doc.body.firstChild);
            }

            this.overlay.style.display = 'flex';
            this.contentEl.focus();
            this.okBtn.disabled = true;
            this.contentEl.scrollTop = 0;

            return new Promise(resolve => {
                this.resolvePromise = resolve;
            });
        } catch (error) {
            console.error(error);
            return Promise.resolve(false); 
        }
    }

    hide() {
        this.overlay.style.display = 'none';
    }

    _setupListeners() {
        this.contentEl.addEventListener('scroll', () => {
            const el = this.contentEl;
            if (el.scrollHeight - el.scrollTop <= el.clientHeight + 10) {
                this.okBtn.disabled = false;
                this.okBtn.focus();
            }
        });

        this.overlay.addEventListener('keydown', (e) => {
            e.stopPropagation();

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.contentEl.scrollTop += 40;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.contentEl.scrollTop -= 40;
            }
            
            if (e.key === 'Enter' && !this.okBtn.disabled) {
                e.preventDefault();
                this._handleConsent(true);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this._handleConsent(false);
            }
        });

        this.okBtn.addEventListener('click', () => {
            if (!this.okBtn.disabled) {
                this._handleConsent(true);
            }
        });
    }
    
    _handleConsent(didConsent) {
        this.hide();
        if (this.resolvePromise) {
            this.resolvePromise(didConsent);
            this.resolvePromise = null;
        }
    }
}
