<?php

/**
 * Example 07: Tool Calling (CLI)
 *
 * Run: php examples/07-tool-calling/tools.php
 *
 * Demonstrates:
 * 1. Defining tools with the Tool class
 * 2. Manual tool calling loop
 * 3. Auto-execute mode (library handles the loop)
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolResult;

$provider = new OpenAIClient([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

// Define tools using the Tool class
$weatherTool = new Tool(
    'get_weather',
    'Get the current weather for a location',
    [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'City name'],
        ],
        'required' => ['location'],
    ],
    function (array $args): string
    {
        $location = $args['location'] ?? 'Unknown';
        // Simulated weather data
        $data = [
            'location' => $location,
            'temperature' => rand(15, 30),
            'condition' => ['sunny', 'cloudy', 'rainy', 'windy'][rand(0, 3)],
            'humidity' => rand(30, 80),
        ];

        return json_encode($data);
    }
);

$timeTool = new Tool(
    'get_time',
    'Get the current time in a timezone',
    [
        'type' => 'object',
        'properties' => [
            'timezone' => ['type' => 'string', 'description' => 'Timezone (e.g., UTC, EST)'],
        ],
        'required' => ['timezone'],
    ],
    function (array $args): string
    {
        $timezone = $args['timezone'] ?? 'UTC';

        return json_encode(['timezone' => $timezone, 'time' => date('H:i:s')]);
    }
);

$tools = [$weatherTool, $timeTool];

// ─── Option A: Auto-Execute Mode ────────────────────────────────────────────
// The library handles the entire tool call loop automatically.

echo '═══ Auto-Execute Mode ═══'.PHP_EOL.PHP_EOL;
echo 'User: What is the weather in London and what time is it in Tokyo?'.PHP_EOL.PHP_EOL;

$response = $provider->chat(
    [
        new Message('system', 'You are a helpful assistant. Use tools when appropriate.'),
        new Message('user', 'What is the weather in London and what time is it in Tokyo?'),
    ],
    [
        'tools' => $tools,
        'auto_execute_tools' => true,
        'max_tool_iterations' => 5,
    ]
);

echo 'AI: '.$response->getMessage()->getContent().PHP_EOL;

// ─── Option B: Manual Mode ──────────────────────────────────────────────────
// You control each step of the tool calling loop.

echo PHP_EOL.'═══ Manual Mode ═══'.PHP_EOL.PHP_EOL;
echo 'User: What is the weather like in Paris?'.PHP_EOL.PHP_EOL;

$messages = [
    new Message('system', 'You are a helpful assistant. Use tools when appropriate.'),
    new Message('user', 'What is the weather like in Paris?'),
];

$response = $provider->chat($messages, ['tools' => $tools]);

if ($response->hasToolCalls()) {
    echo 'AI requested tool calls:'.PHP_EOL;

    $messages[] = $response->getMessage();

    foreach ($response->getMessage()->getToolCalls() as $toolCall) {
        echo '  → '.$toolCall->getName().'('.json_encode($toolCall->getArguments()).')'.PHP_EOL;

        // Find and execute the matching tool
        $result = '';

        foreach ($tools as $tool) {
            if ($tool->getName() === $toolCall->getName()) {
                $result = $tool->execute($toolCall->getArguments());

                break;
            }
        }

        echo '    Result: '.$result.PHP_EOL;
        $messages[] = new Message('tool', '', [], new ToolResult($toolCall->getId(), $result));
    }

    echo PHP_EOL;

    $finalResponse = $provider->chat($messages, ['tools' => $tools]);
    echo 'AI: '.$finalResponse->getMessage()->getContent().PHP_EOL;
} else {
    echo 'AI: '.$response->getMessage()->getContent().PHP_EOL;
}
