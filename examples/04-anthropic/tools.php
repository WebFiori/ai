<?php

/**
 * Example: Tool calling with Anthropic Claude
 *
 * Run: php examples/04-anthropic/tools.php
 *
 * Requires: ANTHROPIC_API_KEY environment variable
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Tool\Tool;

$client = new AnthropicClient([
    'api_key' => getenv('ANTHROPIC_API_KEY'),
    'model' => 'claude-sonnet-4-20250514',
]);

// Define a weather tool
$weatherTool = new Tool(
    name: 'get_weather',
    description: 'Get the current weather for a location',
    parameters: [
        'type' => 'object',
        'properties' => [
            'location' => [
                'type' => 'string',
                'description' => 'The city and country, e.g., "Paris, France"',
            ],
            'unit' => [
                'type' => 'string',
                'enum' => ['celsius', 'fahrenheit'],
                'description' => 'Temperature unit',
            ],
        ],
        'required' => ['location'],
    ],
    callback: function (array $args): string {
        // Simulated weather data
        $location = $args['location'];
        $unit = $args['unit'] ?? 'celsius';
        $temp = $unit === 'celsius' ? 22 : 72;

        return json_encode([
            'location' => $location,
            'temperature' => $temp,
            'unit' => $unit,
            'conditions' => 'Partly cloudy',
        ]);
    }
);

$response = $client->chat(
    messages: [
        new Message('user', 'What is the weather like in Tokyo?'),
    ],
    options: [
        'tools' => [$weatherTool],
        'auto_execute_tools' => true,
    ]
);

echo 'Response: '.$response->getMessage()->getContent().PHP_EOL;
