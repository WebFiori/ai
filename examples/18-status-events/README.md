# 18 — Real-time Status Events

Show real-time progress updates during AI operations. Ideal for frontend UX indicators showing "Thinking...", "Using tool...", "Done!".

## What It Demonstrates

- `CallbackStatusEmitter` for debugging and logging
- `SSEStatusEmitter` for streaming to browser via Server-Sent Events
- Status events during tool calling loops

## Available Emitters

| Class | Use Case |
|-------|----------|
| `NullStatusEmitter` | Default, discards all events |
| `CallbackStatusEmitter` | Custom callable, useful for logging |
| `SSEStatusEmitter` | Browser streaming via SSE |

## Status Events

| Event | When |
|-------|------|
| `preparing` | Before building request |
| `truncating_context` | Context window strategy applied |
| `sending_request` | Before each HTTP request |
| `cache_hit` / `cache_miss` | Cache lookup result |
| `tool_calling` | Model requests a tool |
| `tool_executing` | Tool handler is running |
| `tool_completed` | Tool returned result |
| `completed` | Final response ready |
| `error` | Exception thrown |

## Usage

### Callback (CLI / Logging)

```php
use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\Status;

$client->setStatusEmitter(new CallbackStatusEmitter(
    function(string $status, array $context) {
        if ($status === Status::TOOL_CALLING) {
            echo "Using tool: {$context['tool']}\n";
        }
        if ($status === Status::COMPLETED) {
            echo "Done in {$context['duration_ms']}ms\n";
        }
    }
));
```

### SSE (Web)

```php
// PHP backend
use WebFiori\Ai\SSEStatusEmitter;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$client->setStatusEmitter(new SSEStatusEmitter());
$response = $client->chat($messages, [
    'tools' => $tools,
    'auto_execute_tools' => true,
]);

echo "event: response\n";
echo "data: " . json_encode(['content' => $response->getMessage()->getContent()]) . "\n\n";
```

```javascript
// JavaScript frontend
const es = new EventSource('/api/chat');

es.addEventListener('status', (e) => {
    const { status, ...ctx } = JSON.parse(e.data);
    showStatus(status, ctx);
});

es.addEventListener('response', (e) => {
    showResponse(JSON.parse(e.data).content);
    es.close();
});
```

## Running

```bash
php examples/18-status-events/status.php
```
