<?php

/**
 * Example 18: Real-time Status Events (CLI)
 *
 * Run: php examples/18-status-events/status.php
 *
 * Demonstrates:
 * 1. CallbackStatusEmitter for logging/debugging
 * 2. Status events during tool calling loop
 * 3. SSEStatusEmitter pattern for web usage
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Status;
use WebFiori\Ai\Tool\Tool;

$client = new GoogleClient([
    'credentials' => __DIR__.'/../../vertex-ai-key.json',
    'model' => 'gemini-3.5-flash',
]);

$client->enableConnectionReuse();

// ─── Option A: Callback emitter for debugging ─────────────────────────────────
echo '═══ Status Events with Tool Calling ═══'.PHP_EOL.PHP_EOL;

// Map status codes to human-readable messages with colors
$statusLabels = [
    Status::PREPARING => '🔄 Preparing request',
    Status::SENDING_REQUEST => '📤 Sending to AI',
    Status::WAITING_RESPONSE => '⏳ Waiting for response',
    Status::CACHE_HIT => '⚡ Cache hit',
    Status::CACHE_MISS => '🔍 Cache miss',
    Status::TOOL_CALLING => '🔧 AI calling tool',
    Status::TOOL_EXECUTING => '⚙️  Executing tool',
    Status::TOOL_COMPLETED => '✅ Tool completed',
    Status::COMPLETED => '🎉 Done',
    Status::ERROR => '❌ Error',
];

$client->setStatusEmitter(new CallbackStatusEmitter(
    function (string $status, array $context) use ($statusLabels)
    {
        $label = $statusLabels[$status] ?? "• {$status}";
        $extra = '';

        if ($status === Status::TOOL_CALLING) {
            $extra = " → {$context['tool']}(".json_encode($context['arguments']).')';
        } elseif ($status === Status::TOOL_COMPLETED) {
            $extra = " → {$context['tool']} ({$context['duration_ms']}ms)";
        } elseif ($status === Status::SENDING_REQUEST && isset($context['iteration'])) {
            $extra = $context['iteration'] > 0 ? " (round {$context['iteration']})" : '';
        } elseif ($status === Status::COMPLETED) {
            $extra = " ({$context['duration_ms']}ms, {$context['total_tokens']} tokens)";
        } elseif ($status === Status::ERROR) {
            $extra = " → {$context['error']}";
        }

        echo "  {$label}{$extra}".PHP_EOL;
    }
));

// Define tools
$weatherTool = new Tool(
    'get_weather',
    'Get weather for a city',
    [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'City name'],
        ],
        'required' => ['city'],
    ],
    function (array $args): string
    {
        return json_encode([
            'city' => $args['city'],
            'temperature' => rand(15, 35),
            'condition' => ['sunny', 'cloudy', 'rainy'][rand(0, 2)],
        ]);
    }
);

$timeTool = new Tool(
    'get_time',
    'Get current time in a timezone',
    [
        'type' => 'object',
        'properties' => [
            'timezone' => ['type' => 'string', 'description' => 'Timezone name'],
        ],
        'required' => ['timezone'],
    ],
    function (array $args): string
    {
        return json_encode([
            'timezone' => $args['timezone'],
            'time' => date('H:i:s'),
        ]);
    }
);

echo 'User: What\'s the weather in Dubai and the time in Tokyo?'.PHP_EOL.PHP_EOL;

$response = $client->chat(
    [
        new Message('system', 'You are a helpful assistant. Use tools when appropriate.'),
        new Message('user', 'What\'s the weather in Dubai and the time in Tokyo?'),
    ],
    [
        'tools' => [$weatherTool, $timeTool],
        'auto_execute_tools' => true,
    ]
);

echo PHP_EOL.'AI: '.$response->getMessage()->getContent().PHP_EOL;

// ─── Option B: SSE pattern (web usage) ───────────────────────────────────────
echo PHP_EOL.'═══ SSE Pattern (for web use) ═══'.PHP_EOL.PHP_EOL;
echo 'In a web context, use SSEStatusEmitter:'.PHP_EOL.PHP_EOL;
echo <<<'CODE'
<?php
// api/chat.php
require_once 'vendor/autoload.php';

use WebFiori\Ai\SSEStatusEmitter;
use WebFiori\Ai\Provider\Google\GoogleClient;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$client = new GoogleClient([...]);
$client->setStatusEmitter(new SSEStatusEmitter());

$response = $client->chat($messages, [
    'tools' => $tools,
    'auto_execute_tools' => true,
]);

// Send final response
echo "event: response\n";
echo "data: " . json_encode(['content' => $response->getMessage()->getContent()]) . "\n\n";

CODE;

echo PHP_EOL.'// JavaScript frontend:'.PHP_EOL;
echo <<<'CODE'
const es = new EventSource('/api/chat');

es.addEventListener('status', (e) => {
    const { status, ...ctx } = JSON.parse(e.data);

    const messages = {
        'preparing':       '🔄 Preparing...',
        'sending_request': '🤔 Thinking...',
        'tool_calling':    `🔧 Using ${ctx.tool}...`,
        'tool_completed':  `✅ ${ctx.tool} done`,
        'completed':       '✨ Done!',
    };

    document.getElementById('status').textContent = messages[status] ?? status;
});

es.addEventListener('response', (e) => {
    const { content } = JSON.parse(e.data);
    document.getElementById('response').textContent = content;
    es.close();
});
CODE;
