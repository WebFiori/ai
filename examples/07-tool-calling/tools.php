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
 * 4. ToolResponse — returning text + images from tools
 * 5. LazyTool for deferred instantiation
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\LazyTool;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolResponse;
use WebFiori\Ai\Tool\ToolResult;

$provider = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    credentials: __DIR__.'/../../keys/vertex-ai-key.json',
    projectId: 'webfiori',
    location: 'us-central1',
    api: \WebFiori\Ai\Provider\Google\GoogleApi::VERTEX_AI,
));

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
    function (array $args): string {
        $location = $args['location'] ?? 'Unknown';
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
    function (array $args): string {
        $timezone = $args['timezone'] ?? 'UTC';

        return json_encode(['timezone' => $timezone, 'time' => date('H:i:s')]);
    }
);

// LazyTool: only instantiated when called — useful for expensive constructors
$databaseTool = new LazyTool(
    'search_database',
    'Search the product database',
    [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Search query'],
        ],
        'required' => ['query'],
    ],
    function () {
        echo "  [LazyTool: Database connection initialized]\n";

        return function (array $args): string {
            $query = $args['query'] ?? '';

            return json_encode([
                'results' => [
                    ['id' => 1, 'name' => 'Product A', 'price' => 29.99],
                    ['id' => 2, 'name' => 'Product B', 'price' => 49.99],
                ],
                'query' => $query,
            ]);
        };
    }
);

// Tool returning ToolResponse with an image (multimodal tool output)
// The model will receive both the text AND see the image for visual analysis.
$chartTool = new Tool(
    'generate_chart',
    'Generates a simple bar chart and returns it as an image',
    [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string', 'description' => 'Chart title'],
        ],
        'required' => ['title'],
    ],
    function (array $args): ToolResponse {
        $title = $args['title'] ?? 'Chart';

        // In a real scenario this would generate an actual image.
        // Here we create a tiny 1×1 white PNG as demonstration.
        $pngData = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
        );

        return ToolResponse::withImages(
            json_encode(['title' => $title, 'status' => 'Chart generated successfully']),
            [ContentPart::imageBase64(base64_encode($pngData), 'image/png')]
        );
    }
);

$tools = [$weatherTool, $timeTool, $databaseTool];

// ─── Option A: Auto-Execute Mode ────────────────────────────────────────────
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

// ─── Option B: Manual Mode ───────────────────────────────────────────────────
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

        $result = '';

        foreach ($tools as $tool) {
            if ($tool->getName() === $toolCall->getName()) {
                $result = $tool->execute($toolCall->getArguments());

                break;
            }
        }

        echo '    Result: '.(string) $result.PHP_EOL;
        $messages[] = new Message('tool', '', [], new ToolResult($toolCall->getId(), (string) $result));
    }

    echo PHP_EOL;

    $finalResponse = $provider->chat($messages, ['tools' => $tools]);
    echo 'AI: '.$finalResponse->getMessage()->getContent().PHP_EOL;
} else {
    echo 'AI: '.$response->getMessage()->getContent().PHP_EOL;
}

// ─── Option C: ToolResponse with images ──────────────────────────────────────
echo PHP_EOL.'═══ ToolResponse with Images ═══'.PHP_EOL.PHP_EOL;
echo 'User: Generate a sales chart and describe it'.PHP_EOL.PHP_EOL;
echo '(Tool returns both text metadata AND an image for the model to analyze)'.PHP_EOL.PHP_EOL;

$response = $provider->chat(
    [new Message('user', 'Generate a sales chart titled "Q3 Revenue" and describe what you see.')],
    [
        'tools' => [$chartTool],
        'auto_execute_tools' => true,
    ]
);

echo 'AI: '.$response->getMessage()->getContent().PHP_EOL;
