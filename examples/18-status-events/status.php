<?php

/**
 * Example 18: Real-time Status Events (CLI)
 *
 * Run: php examples/18-status-events/status.php
 *
 * Demonstrates:
 * 1. Default StatusMessageFormatter with humanized messages
 * 2. Custom templates for tool-specific context
 * 3. SSEStatusEmitter pattern for web usage
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Status;
use WebFiori\Ai\StatusMessageFormatter;
use WebFiori\Ai\Tool\Tool;

$client = new GoogleClient([
    'credentials' => __DIR__.'/../../vertex-ai-key.json',
    'model' => 'gemini-3.5-flash',
]);

$client->enableConnectionReuse();

// ─── Option A: Default formatter ─────────────────────────────────────────────
echo '═══ Default Humanized Messages ═══'.PHP_EOL.PHP_EOL;

$formatter = new StatusMessageFormatter();

$client->setStatusEmitter(new CallbackStatusEmitter(
    function (string $status, array $context) use ($formatter)
    {
        echo '  '.$formatter->format($status, $context).PHP_EOL;
    }
));

// ─── Option B: Custom templates ──────────────────────────────────────────────
echo PHP_EOL.'═══ Custom Templates ═══'.PHP_EOL.PHP_EOL;

$customFormatter = new StatusMessageFormatter();
$customFormatter->setTemplates([
    Status::PREPARING => 'Getting ready to answer your question...',
    Status::SENDING_REQUEST => 'Asking {model}...',
    Status::TOOL_CALLING => 'Looking up {tool} data for {arguments.city}...',
    Status::TOOL_EXECUTING => 'Fetching live data from {tool}...',
    Status::TOOL_COMPLETED => '✓ Got {tool} result in {duration_ms}ms',
    Status::COMPLETED => '✓ Answer ready in {duration_s}s using {total_tokens} tokens',
    Status::ERROR => '✗ Failed: {error}',
]);

$client->setStatusEmitter(new CallbackStatusEmitter(
    function (string $status, array $context) use ($customFormatter)
    {
        echo '  '.$customFormatter->format($status, $context).PHP_EOL;
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

// ─── SSE usage (web) ─────────────────────────────────────────────────────────
echo PHP_EOL.'To use with SSE in a web app, run:'.PHP_EOL;
echo '  php -S localhost:8080 -t examples/18-status-events'.PHP_EOL;
echo '  Then open: http://localhost:8080'.PHP_EOL;
