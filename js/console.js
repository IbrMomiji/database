import { postCommand } from './api.js';
import { PrivacyPolicyManager } from './privacyPolicy.js';

export class Console {
    constructor(el, windowManager, options) {
        this.el = el;
        this.windowManager = windowManager;
        this.outputEl = el.querySelector('.console-output');
        this.promptEl = el.querySelector('.prompt');
        this.inputEl = el.querySelector('.console-input');
        this.inputLineEl = el.querySelector('.input-line');
        this.consoleBody = el.querySelector('.console-body');
        this.privacyManager = new PrivacyPolicyManager(this);

        this.el.dataset.defaultPrompt = options.prompt || 'database&gt; ';
        this.outputEl.innerHTML = options.history ? options.history.join('') : '';
        this.promptEl.innerHTML = this.el.dataset.defaultPrompt;
        
        this._setupListeners();
        this.focus();
        this._scrollToBottom();
    }

    focus() {
        this.inputEl.focus();
    }

    _setupListeners() {
        this.inputEl.addEventListener('keydown', (e) => this._onCommandSubmit(e));
        this.consoleBody.addEventListener('click', () => {
            if (window.getSelection().toString() === '') this.focus();
        });
        this.el.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if(this.el.dataset.isInteractive) {
                    this.inputEl.value = 'cancel';
                    this._onCommandSubmit(new KeyboardEvent('keydown', { key: 'Enter' }));
                }
            }
        });
    }

    handleExternalKey(e) {
        if (e.key === 'Enter') {
            this._onCommandSubmit(e);
            return;
        }

        const input = this.inputEl;

        if (e.key === 'Backspace') {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            if (start === end && start > 0) {
                input.value = input.value.substring(0, start - 1) + input.value.substring(end);
                input.selectionStart = input.selectionEnd = start - 1;
            } else if (start !== end) {
                input.value = input.value.substring(0, start) + input.value.substring(end);
                input.selectionStart = input.selectionEnd = start;
            }
        } else if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            const text = input.value;
            input.value = text.slice(0, start) + e.key + text.slice(end);
            input.selectionStart = input.selectionEnd = start + 1;
        }
        
        this.focus();
        this._scrollToBottom();
    }

    async _onCommandSubmit(e) {
        if (e.key !== 'Enter' || e.isComposing) return;
        e.preventDefault();

        const command = this.inputEl.value.trim();
        const currentPromptText = this.promptEl.textContent;
        const isPassword = this.inputEl.type === 'password';

        this.inputEl.value = '';
        this.inputEl.disabled = true;
        
        try {
            const data = await postCommand(command, currentPromptText);

            if (this.el.dataset.isInteractive) {
                let echoText = this._escapeHtml(currentPromptText);
                echoText += isPassword ? '********' : this._escapeHtml(command);
                this.outputEl.innerHTML += `<div>${echoText}</div>`;
            } else if (command) {
                this.outputEl.innerHTML += `<div>${this._escapeHtml(currentPromptText + command)}</div>`;
            }

            await this._handleResponse(data);
        } catch (error) {
            this.outputEl.innerHTML += `<div class="error">クライアントエラー: ${error.message}</div>`;
            this._resetToDefaultPrompt();
        } finally {
            this.inputEl.disabled = false;
            this._scrollToBottom();
            this.focus();
        }
    }
    
    async _handleResponse(data) {
        if (data.action && data.action.type === 'show_privacy_policy') {
            const didConsent = await this.privacyManager.show();
            
            this.inputEl.disabled = true;
            try {
                const commandName = this.el.dataset.isInteractive ? 'register' : (data.action.command || 'register');
                const consentData = await postCommand(commandName, this.el.dataset.defaultPrompt, { consent: didConsent });
                await this._handleResponse(consentData);
            } catch (error) {
                this.outputEl.innerHTML += `<div class="error">クライアントエラー: ${error.message}</div>`;
                this._resetToDefaultPrompt();
            } finally {
                this.inputEl.disabled = false;
                this._scrollToBottom();
                this.focus();
            }
            return;
        }

        if (data.interactive_final) {
            this.el.dataset.isInteractive = 'true';
            if (data.output) this.outputEl.innerHTML += `<div>${data.output}</div>`;
            this.promptEl.innerHTML = data.prompt_text || '';
            this.inputEl.type = data.input_type || 'text';
        } else {
            this._resetToDefaultPrompt(data.prompt);
            if (data.clear) this.outputEl.innerHTML = '';
            if (data.output) this.outputEl.innerHTML += `<div>${data.output}</div>`;

            if (data.action && typeof data.action === 'object' && data.action.type) {
                try {
                    switch(data.action.type) {
                        case 'open_app':
                            if (data.action.app) {
                                this.windowManager.createWindow(data.action.app, data.action.options || {});
                            } else {
                                throw new Error("起動するアプリケーションが指定されていません。");
                            }
                            break;
                        default:
                            console.warn('不明なアクションタイプです:', data.action.type);
                    }
                } catch (error) {
                    alert(`アプリケーションの起動に失敗しました:\n${error.message}`);
                }
            }
        }
    }
    
    _resetToDefaultPrompt(newPrompt = null) {
        delete this.el.dataset.isInteractive;
        this.inputEl.type = 'text';
        if (newPrompt !== null) {
            this.el.dataset.defaultPrompt = newPrompt;
        }
        this.promptEl.innerHTML = this.el.dataset.defaultPrompt || 'database&gt; ';
    }
    
    _scrollToBottom() {
        this.consoleBody.scrollTop = this.consoleBody.scrollHeight;
    }

    _escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
}