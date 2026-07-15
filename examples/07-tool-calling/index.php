<?php

/**
 * Example 07: Tool Calling (Web)
 *
 * Start: php -S localhost:8080 -t examples/07-tool-calling
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Tool\Tool;

$error = null;
$userMessage = '';
$aiResponse = '';
$toolsExecuted = [];

// Define tools using the Tool class
$tools = [
    new Tool(
        'get_weather',
        'Get the current weather for a location',
        [
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string', 'description' => 'City name']],
            'required' => ['location'],
        ],
        function (array $args) use (&$toolsExecuted): string
        {
            $location = $args['location'] ?? 'Unknown';
            $data = [
                'location' => $location,
                'temperature' => rand(15, 30),
                'condition' => ['sunny', 'cloudy', 'rainy', 'windy'][rand(0, 3)],
                'humidity' => rand(30, 80),
            ];
            $toolsExecuted[] = ['name' => 'get_weather', 'args' => $args, 'result' => $data];

            return json_encode($data);
        }
    ),
    new Tool(
        'get_time',
        'Get the current time in a timezone',
        [
            'type' => 'object',
            'properties' => ['timezone' => ['type' => 'string', 'description' => 'Timezone (e.g., UTC, EST)']],
            'required' => ['timezone'],
        ],
        function (array $args) use (&$toolsExecuted): string
        {
            $timezone = $args['timezone'] ?? 'UTC';
            $data = ['timezone' => $timezone, 'time' => date('H:i:s')];
            $toolsExecuted[] = ['name' => 'get_time', 'args' => $args, 'result' => $data];

            return json_encode($data);
        }
    ),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $userMessage = trim($_POST['message']);

    try {
        $provider = new GoogleClient([
            'api' => 'gemini',
            'credentials' => __DIR__.'/../../vertex-ai-key.json',
            'model' => 'gemini-2.5-flash',
        ]);

        // Auto-execute mode: the library handles the tool call loop
        $response = $provider->chat(
            [
                new Message('system', 'You are a helpful assistant. Use tools when appropriate.'),
                new Message('user', $userMessage),
            ],
            [
                'tools' => $tools,
                'auto_execute_tools' => true,
                'max_tool_iterations' => 5,
            ]
        );

        $aiResponse = $response->getMessage()->getContent();
    } catch (Throwable $e) {
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
        .note { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 10px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; color: #166534; }
    </style>
</head>
<body>
    <h1>Tool Calling</h1>
    <p class="subtitle">Ask about weather or time and watch the AI invoke tools automatically.</p>

    <div class="note">
        Using <code>auto_execute_tools</code> — the library handles the tool call loop for you.
    </div>

    <div class="tools-info">
        <h3>Available Tools</h3>
        <strong>get_weather</strong> — Returns weather for a city (simulated)<br>
        <strong>get_time</strong> — Returns current time in a timezone
    </div>

    <?php if ($error) { ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php } ?>

    <form method="POST">
        <input type="text" name="message" placeholder="What's the weather in Paris and the time in Tokyo?" value="<?= htmlspecialchars($userMessage) ?>" autofocus aria-label="Message">
        <button type="submit">Send</button>
    </form>

    <?php if ($userMessage) { ?>
        <div class="step user">
            <div class="label">You</div>
            <div class="content"><?= htmlspecialchars($userMessage) ?></div>
        </div>

        <?php foreach ($toolsExecuted as $tool) { ?>
            <div class="step tool_call">
                <div class="label">🔧 Tool: <?= htmlspecialchars($tool['name']) ?></div>
                <div class="content">
                    Args: <code><?= htmlspecialchars(json_encode($tool['args'])) ?></code><br>
                    Result: <code><?= htmlspecialchars(json_encode($tool['result'])) ?></code>
                </div>
            </div>
        <?php } ?>

        <?php if ($aiResponse) { ?>
            <div class="step assistant">
                <div class="label">Assistant</div>
                <div class="content"><?= htmlspecialchars($aiResponse) ?></div>
            </div>
        <?php } ?>
    <?php } ?>
</body>
</html>
