<?php

/**
 * Example 07: Tool Calling (CLI)
 *
 * Run: php examples/07-tool-calling/tools.php
 *
 * Demonstrates the full tool calling loop:
 * 1. User asks a question
 * 2. AI requests to call a tool
 * 3. Tool is executed
 * 4. Result is sent back to AI
 * 5. AI formulates final response
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Tool\ToolResult;

$provider = new OpenAIClient([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

// Define available tools (in a real app, these would call real APIs)
$tools = [
    'get_weather' => function (array $args): string
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
    },
];

// Step 1: Send user message with tool definitions
$messages = [
    new Message('system', 'You are a helpful assistant. Use the get_weather tool when asked about weather.'),
    new Message('user', 'What is the weather like in London and Tokyo?'),
];

echo 'User: What is the weather like in London and Tokyo?'.PHP_EOL.PHP_EOL;

// Include tool definitions in the options
$response = $provider->chat($messages, [
    'tools' => [[
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Get the current weather for a location',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City name'],
                ],
                'required' => ['location'],
            ],
        ],
    ]],
]);

// Step 2: Check if AI wants to call tools
if ($response->hasToolCalls()) {
    echo 'AI requested tool calls:'.PHP_EOL;

    // Add assistant message with tool calls to history
    $messages[] = $response->getMessage();

    // Step 3: Execute each tool call
    foreach ($response->getMessage()->getToolCalls() as $toolCall) {
        echo '  → '.$toolCall->getName().'('.json_encode($toolCall->getArguments()).')'.PHP_EOL;

        $handler = $tools[$toolCall->getName()] ?? null;

        if ($handler !== null) {
            $result = $handler($toolCall->getArguments());
            echo '    Result: '.$result.PHP_EOL;

            // Add tool result to messages
            $messages[] = new Message('tool', '', [], new ToolResult($toolCall->getId(), $result));
        }
    }

    echo PHP_EOL;

    // Step 4: Send tool results back to AI for final response
    $finalResponse = $provider->chat($messages);
    echo 'AI: '.$finalResponse->getMessage()->getContent().PHP_EOL;
} else {
    echo 'AI: '.$response->getMessage()->getContent().PHP_EOL;
}
