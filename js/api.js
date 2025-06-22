export async function postCommand(command, currentPrompt, extraData = {}) {
    try {
        const payload = {
            command: command,
            current_prompt: currentPrompt,
            ...extraData
        };

        const response = await fetch('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
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