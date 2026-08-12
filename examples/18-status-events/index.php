<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Events Demo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0f0f0f;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .container {
            width: 100%;
            max-width: 640px;
        }

        h1 {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: #fff;
        }

        p.subtitle {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 24px;
        }

        .input-row {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }

        input[type="text"] {
            flex: 1;
            padding: 10px 14px;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 0.9rem;
            outline: none;
        }

        input[type="text"]:focus { border-color: #555; }

        button {
            padding: 10px 20px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            cursor: pointer;
            white-space: nowrap;
        }

        button:disabled { background: #333; color: #666; cursor: not-allowed; }
        button:hover:not(:disabled) { background: #1d4ed8; }

        .status-box {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            min-height: 120px;
        }

        .status-box h2 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #555;
            margin-bottom: 12px;
        }

        .status-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .status-item {
            font-size: 0.85rem;
            color: #aaa;
            display: flex;
            align-items: baseline;
            gap: 8px;
            animation: fadeIn 0.2s ease;
        }

        .status-item.active { color: #e0e0e0; }
        .status-item.done { color: #4ade80; }
        .status-item.error { color: #f87171; }

        .status-icon { font-size: 1rem; flex-shrink: 0; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #555;
            border-top-color: #aaa;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            flex-shrink: 0;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .response-box {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 10px;
            padding: 16px;
            display: none;
        }

        .response-box h2 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #555;
            margin-bottom: 10px;
        }

        .response-text {
            font-size: 0.9rem;
            line-height: 1.6;
            color: #e0e0e0;
        }

        .empty-hint {
            color: #444;
            font-size: 0.85rem;
            font-style: italic;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Real-time Status Events</h1>
    <p class="subtitle">Watch AI tool calls stream in real-time via Server-Sent Events</p>

    <div class="input-row">
        <input type="text" id="message"
               value="What's the weather in Dubai and the time in Tokyo?"
               placeholder="Ask something that requires tools...">
        <button id="sendBtn" onclick="sendMessage()">Send</button>
    </div>

    <div class="status-box">
        <h2>Progress</h2>
        <ul class="status-list" id="statusList">
            <li class="status-item"><span class="empty-hint">Send a message to see live events...</span></li>
        </ul>
    </div>

    <div class="response-box" id="responseBox">
        <h2>Response</h2>
        <p class="response-text" id="responseText"></p>
    </div>
</div>

<script>
    const statusLabels = {
        'preparing':           { icon: '🔄', text: 'Preparing request' },
        'truncating_context':  { icon: '✂️',  text: 'Truncating context' },
        'sending_request':     { icon: '📤', text: 'Sending to AI' },
        'waiting_response':    { icon: '⏳', text: 'Waiting for response' },
        'cache_hit':           { icon: '⚡', text: 'Cache hit' },
        'cache_miss':          { icon: '🔍', text: 'Cache miss' },
        'tool_calling':        { icon: '🔧', text: (ctx) => `AI calling tool: ${ctx.tool}` },
        'tool_executing':      { icon: '⚙️',  text: (ctx) => `Executing: ${ctx.tool}` },
        'tool_completed':      { icon: '✅', text: (ctx) => `${ctx.tool} done (${ctx.duration_ms}ms)` },
        'completed':           { icon: '🎉', text: (ctx) => `Done in ${ctx.duration_ms}ms` },
        'error':               { icon: '❌', text: (ctx) => `Error: ${ctx.error}` },
    };

    let eventSource = null;

    function sendMessage() {
        const message = document.getElementById('message').value.trim();
        if (!message) return;

        // Reset UI
        document.getElementById('statusList').innerHTML = '';
        document.getElementById('responseBox').style.display = 'none';
        document.getElementById('responseText').textContent = '';
        document.getElementById('sendBtn').disabled = true;

        // Close existing connection
        if (eventSource) eventSource.close();

        const url = 'stream.php?message=' + encodeURIComponent(message);
        eventSource = new EventSource(url);

        eventSource.addEventListener('status', (e) => {
            const data = JSON.parse(e.data);
            const { status, ...ctx } = data;

            const def = statusLabels[status] ?? { icon: '•', text: status };
            const text = typeof def.text === 'function' ? def.text(ctx) : def.text;

            const li = document.createElement('li');
            li.className = 'status-item active';

            const isDone = status === 'completed';
            const isError = status === 'error';
            const isRunning = ['sending_request', 'tool_executing'].includes(status);

            if (isError) li.className = 'status-item error';
            else if (isDone) li.className = 'status-item done';

            if (isRunning) {
                li.innerHTML = `<span class="spinner"></span><span>${text}</span>`;
            } else {
                li.innerHTML = `<span class="status-icon">${def.icon}</span><span>${text}</span>`;
            }

            document.getElementById('statusList').appendChild(li);
        });

        eventSource.addEventListener('response', (e) => {
            const { content } = JSON.parse(e.data);
            document.getElementById('responseBox').style.display = 'block';
            document.getElementById('responseText').textContent = content;
            document.getElementById('sendBtn').disabled = false;
            eventSource.close();
        });

        eventSource.addEventListener('error', (e) => {
            if (e.data) {
                const { message: msg } = JSON.parse(e.data);
                const li = document.createElement('li');
                li.className = 'status-item error';
                li.innerHTML = `<span class="status-icon">❌</span><span>${msg}</span>`;
                document.getElementById('statusList').appendChild(li);
            }
            document.getElementById('sendBtn').disabled = false;
            eventSource.close();
        });

        // Handle SSE connection error
        eventSource.onerror = () => {
            document.getElementById('sendBtn').disabled = false;
        };
    }

    // Send on Enter key
    document.getElementById('message').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') sendMessage();
    });
</script>
</body>
</html>
