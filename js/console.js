import { postCommand } from './api.js';

export class Console {
    constructor(el, windowManager, options) {
        this.el = el;
        this.windowManager = windowManager;
        this.outputEl = el.querySelector('.console-output');
        this.promptEl = el.querySelector('.prompt');
        this.inputEl = el.querySelector('.console-input');
        this.inputLineEl = el.querySelector('.input-line');
        this.consoleBody = el.querySelector('.console-body');

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
            const data = await postCommand(command, this.el.dataset.defaultPrompt);

            if (this.el.dataset.isInteractive) {
                let echoText = this._escapeHtml(currentPromptText);
                echoText += isPassword ? '*'.repeat(command.length) : this._escapeHtml(command);
                this.outputEl.innerHTML += `<div>${echoText}</div>`;
            } else if (command) {
                this.outputEl.innerHTML += `<div>${this._escapeHtml(currentPromptText + command)}</div>`;
            }

            this._handleResponse(data);
        } catch (error) {
            this.outputEl.innerHTML += `<div class="error">クライアントエラー: ${error.message}</div>`;
            this._resetToDefaultPrompt();
        } finally {
            this.inputEl.disabled = false;
            this._scrollToBottom();
            this.focus();
        }
    }
    
    _handleResponse(data) {
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
            this.promptEl.innerHTML = newPrompt;
        } else {
            this.promptEl.innerHTML = this.el.dataset.defaultPrompt || 'database&gt; ';
        }
    }
    
    _scrollToBottom() {
        this.consoleBody.scrollTop = this.consoleBody.scrollHeight;
    }

    _escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
}
