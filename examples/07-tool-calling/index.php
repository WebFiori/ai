<?php

/**
 * Example 07: Tool Calling (Web)
 *
 * Start: php -S localhost:8080 -t examples/07-tool-calling
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;
use WebFiori\Ai\Tool\ToolResult;

$steps = [];
$error = null;
$userMessage = '';

// Simulated tools
$tools = [
    'get_weather' => function (array $args): string {
        $location = $args['location'] ?? 'Unknown';

        return json_encode([
            'location' => $location,
            'temperature' => rand(15, 30),
            'condition' => ['sunny', 'cloudy', 'rainy', 'windy'][rand(0, 3)],
            'humidity' => rand(30, 80),
        ]);
    },
    'get_time' => function (array $args): string {
        $timezone = $args['timezone'] ?? 'UTC';

        return json_encode(['timezone' => $timezone, 'time' => date('H:i:s')]);
    },
];

$toolDefinitions = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Get the current weather for a location',
            'parameters' => [
                'type' => 'object',
                'properties' => ['location' => ['type' => 'string', 'description' => 'City name']],
                'required' => ['location'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'get_time',
            'description' => 'Get the current time in a timezone',
            'parameters' => [
                'type' => 'object',
                'properties' => ['timezone' => ['type' => 'string', 'description' => 'Timezone (e.g., UTC, EST)']],
                'required' => ['timezone'],
            ],
        ],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $userMessage = trim($_POST['message']);

    try {
        $provider = new OpenAIProvider([
            'api_key' => getenv('OPENAI_API_KEY'),
            'model' => 'gpt-4o',
        ]);

        $messages = [
            new Message('system', 'You are a helpful assistant. Use tools when appropriate.'),
            new Message('user', $userMessage),
        ];

        $steps[] = ['type' => 'user', 'content' => $userMessage];

        $response = $provider->chat($messages, ['tools' => $toolDefinitions]);

        if ($response->hasToolCalls()) {
            $messages[] = $response->getMessage();

            foreach ($response->getMessage()->getToolCalls() as $toolCall) {
                $handler = $tools[$toolCall->getName()] ?? null;
                $result = $handler ? $handler($toolCall->getArguments()) : '{"error":"Unknown tool"}';

                $steps[] = [
                    'type' => 'tool_call',
                    'name' => $toolCall->getName(),
                    'args' => $toolCall->getArguments(),
                    'result' => $result,
                ];

                $messages[] = new Message('tool', '', [], new ToolResult($toolCall->getId(), $result));
            }

            $finalResponse = $provider->chat($messages);
            $steps[] = ['type' => 'assistant', 'content' => $finalResponse->getMessage()->getContent()];
        } else {
            $steps[] = ['type' => 'assistant', 'content' => $response->getMessage()->getContent()];
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Calling — WebFiori AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; color: #333; }
        h1 { margin-bottom: 8px; }
        p.subtitle { color: #666; margin-bottom: 24px; }
        form { display: flex; gap: 8px; margin-bottom: 24px; }
        input[type="text"] { flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; }
        button { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .step { margin-bottom: 12px; padding: 12px; border-radius: 8px; }
        .step.user { background: #dbeafe; }
        .step.assistant { background: #f1f5f9; }
        .step.tool_call { background: #fef3c7; border: 1px solid #f59e0b; }
        .step .label { font-size: 12px; font-weight: bold; text-transform: uppercase; color: #64748b; margin-bottom: 4px; }
        .step .content { white-space: pre-wrap; word-wrap: break-word; }
        .step code { background: #f1f5f9; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
        .tools-info { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 24px; font-size: 14px; }
        .tools-info h3 { font-size: 14px; margin-bottom: 8px; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <h1>Tool Calling</h1>
    <p class="subtitle">Ask about weather or time and watch the AI invoke tools to get real data.</p>

    <div class="tools-info">
        <h3>Available Tools</h3>
        <strong>get_weather</strong> — Returns weather for a city (simulated)<br>
        <strong>get_time</strong> — Returns current time in a timezone
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="message" placeholder="What's the weather in Paris?" value="<?= htmlspecialchars($userMessage) ?>" autofocus>
        <button type="submit">Send</button>
    </form>

    <?php foreach ($steps as $step): ?>
        <div class="step <?= $step['type'] ?>">
            <?php if ($step['type'] === 'user'): ?>
                <div class="label">You</div>
                <div class="content"><?= htmlspecialchars($step['content']) ?></div>
            <?php elseif ($step['type'] === 'tool_call'): ?>
                <div class="label">🔧 Tool Call: <?= htmlspecialchars($step['name']) ?></div>
                <div class="content">
                    Args: <code><?= htmlspecialchars(json_encode($step['args'])) ?></code><br>
                    Result: <code><?= htmlspecialchars($step['result']) ?></code>
                </div>
            <?php elseif ($step['type'] === 'assistant'): ?>
                <div class="label">Assistant</div>
                <div class="content"><?= htmlspecialchars($step['content']) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
