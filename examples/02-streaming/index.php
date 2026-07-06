<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Streaming Chat — WebFiori AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; color: #333; }
        h1 { margin-bottom: 8px; }
        p.subtitle { color: #666; margin-bottom: 24px; }
        .input-row { display: flex; gap: 8px; margin-bottom: 24px; }
        input[type="text"] { flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; }
        button { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        button:disabled { background: #94a3b8; cursor: not-allowed; }
        #output { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; min-height: 120px; white-space: pre-wrap; word-wrap: break-word; font-size: 15px; line-height: 1.6; }
        .meta { font-size: 13px; color: #64748b; margin-top: 12px; }
        .cursor { display: inline-block; width: 2px; height: 1em; background: #2563eb; animation: blink 0.8s infinite; vertical-align: text-bottom; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }
    </style>
</head>
<body>
    <h1>Streaming Chat</h1>
    <p class="subtitle">Watch tokens appear in real-time as the AI generates its response.</p>

    <div class="input-row">
        <input type="text" id="message" placeholder="Ask something..." autofocus>
        <button id="sendBtn" onclick="sendMessage()">Send</button>
    </div>

    <div id="output"></div>
    <div id="meta" class="meta"></div>

    <script>
        const messageInput = document.getElementById('message');
        const output = document.getElementById('output');
        const meta = document.getElementById('meta');
        const sendBtn = document.getElementById('sendBtn');

        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') sendMessage();
        });

        function sendMessage() {
            const message = messageInput.value.trim();
            if (!message) return;

            output.textContent = '';
            meta.textContent = '';
            sendBtn.disabled = true;

            const cursor = document.createElement('span');
            cursor.className = 'cursor';
            output.appendChild(cursor);

            const source = new EventSource('sse.php?message=' + encodeURIComponent(message));

            source.onmessage = function(event) {
                const data = JSON.parse(event.data);

                if (data.error) {
                    output.textContent = 'Error: ' + data.error;
                    source.close();
                    sendBtn.disabled = false;
                    return;
                }

                if (data.done) {
                    cursor.remove();
                    meta.textContent = 'Model: ' + data.model + ' | Finish: ' + data.finish_reason;
                    source.close();
                    sendBtn.disabled = false;
                    return;
                }

                if (data.token) {
                    output.insertBefore(document.createTextNode(data.token), cursor);
                }
            };

            source.onerror = function() {
                cursor.remove();
                meta.textContent = 'Connection closed.';
                source.close();
                sendBtn.disabled = false;
            };
        }
    </script>
</body>
</html>
