<?php

/**
 * SSE Chat Stream Endpoint
 *
 * Streams status events and final response via Server-Sent Events.
 *
 * Usage: php -S localhost:8080 -t examples/18-status-events
 * Then open: http://localhost:8080
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Status;
use WebFiori\Ai\StatusMessageFormatter;
use WebFiori\Ai\Tool\Tool;

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable output buffering
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

while (ob_get_level()) {
    ob_end_clean();
}

$userMessage = $_GET['message'] ?? 'What is the weather in Dubai and the time in Tokyo?';

$client = new GoogleClient([
    'credentials' => __DIR__.'/../../vertex-ai-key.json',
    'model' => 'gemini-3.5-flash',
]);

$client->enableConnectionReuse();

// Use StatusMessageFormatter to add human-readable messages to SSE events
$formatter = new StatusMessageFormatter();
$formatter->setTemplates([
    Status::TOOL_CALLING => 'Getting {arguments.city} weather using {tool}...',
    Status::TOOL_EXECUTING => 'Fetching live data from {tool}...',
    Status::TOOL_COMPLETED => 'Got data from {tool} in {duration_ms}ms',
    Status::COMPLETED => 'Done in {duration_s} seconds',
]);

// Custom SSE emitter that includes humanized message
$client->setStatusEmitter(new WebFiori\Ai\CallbackStatusEmitter(
    function (string $status, array $context) use ($formatter)
    {
        echo 'event: status'."\n";
        echo 'data: '.json_encode([
            'status' => $status,
            'message' => $formatter->format($status, $context),
            ...$context,
        ])."\n\n";
        ob_flush();
        flush();
    }
));

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
        sleep(1); // Simulate delay so SSE events are visible

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

try {
    $response = $client->chat(
        [
            new Message('system', 'You are a helpful assistant. Use tools when appropriate.'),
            new Message('user', $userMessage),
        ],
        [
            'tools' => [$weatherTool, $timeTool],
            'auto_execute_tools' => true,
        ]
    );

    // Send final response
    echo 'event: response'."\n";
    echo 'data: '.json_encode(['content' => $response->getMessage()->getContent()])."\n\n";
    flush();
} catch (Throwable $e) {
    echo 'event: error'."\n";
    echo 'data: '.json_encode(['message' => $e->getMessage()])."\n\n";
    flush();
}
