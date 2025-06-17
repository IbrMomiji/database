/**
 * サーバーにコマンドをPOSTリクエストで送信する
 * @param {string} command - 送信するコマンド文字列
 * @param {string} currentPrompt - 現在のプロンプト文字列
 * @returns {Promise<object>} サーバーからのJSONレスポンス
 */
export async function postCommand(command, currentPrompt) {
    try {
        const response = await fetch('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                command: command,
                current_prompt: currentPrompt,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.output || `サーバーエラー: ${response.status}`);
        }
        
        return data;

    } catch (error) {
        console.error('API call failed:', error);
        throw error;
    }
}
