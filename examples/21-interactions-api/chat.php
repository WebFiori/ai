<?php

/**
 * Example 21: Google Interactions API (gemini-3.5-flash+)
 *
 * Run: php examples/21-interactions-api/chat.php
 *
 * Demonstrates the new Interactions API introduced with gemini-3.5-flash:
 * 1. Basic chat — responses include thought + model_output steps
 * 2. Multi-turn stateless conversation — raw steps preserved for replay
 * 3. Tool calling with auto-execute loop
 * 4. Streaming with named SSE events
 *
 * The Interactions API is automatically selected for gemini-3.x+ models.
 * You can also force it via GoogleApiVersion::INTERACTIONS.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\Tool;

// gemini-3.5-flash auto-detects INTERACTIONS API
$client = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-3.5-flash',
    credentials: __DIR__.'/../../keys/vertex-ai-key.json',
));

// ─── 1. Basic chat ───────────────────────────────────────────────────────────
echo '═══ Basic Chat (Interactions API) ═══'.PHP_EOL.PHP_EOL;

$response = $client->chat([
    new Message('system', 'You are a helpful assistant. Keep responses concise.'),
    new Message('user', 'What is PHP in one sentence?'),
]);

echo 'Response: '.$response->getMessage()->getContent().PHP_EOL;
echo 'Interaction ID: '.$response->getRequestId().PHP_EOL;

if ($response->getUsage()) {
    echo 'Tokens: '.$response->getUsage()->getTotalTokens().PHP_EOL;
}

$steps = $response->getMessage()->getRawSteps() ?? [];
$stepTypes = array_column($steps, 'type');
echo 'Steps: '.implode(', ', $stepTypes).PHP_EOL;

// ─── 2. Multi-turn stateless conversation ────────────────────────────────────
echo PHP_EOL.'═══ Multi-turn Stateless Conversation ═══'.PHP_EOL.PHP_EOL;

echo 'Turn 1: My favourite language is PHP.'.PHP_EOL;
$messages = [new Message('user', 'My favourite language is PHP.')];
$r1 = $client->chat($messages);
echo 'AI: '.$r1->getMessage()->getContent().PHP_EOL.PHP_EOL;

// Append the assistant message (with rawSteps) so the model can replay its output
$messages[] = $r1->getMessage();
$messages[] = new Message('user', 'What language did I mention?');

echo 'Turn 2: What language did I mention?'.PHP_EOL;
$r2 = $client->chat($messages);
echo 'AI: '.$r2->getMessage()->getContent().PHP_EOL;

// ─── 3. Tool calling with auto-execute ───────────────────────────────────────
echo PHP_EOL.'═══ Tool Calling ═══'.PHP_EOL.PHP_EOL;

$weatherTool = new Tool(
    'get_weather',
    'Returns current weather for a city.',
    [
        'type' => 'object',
        'properties' => ['city' => ['type' => 'string']],
        'required' => ['city'],
    ],
    fn(array $args) => json_encode(['city' => $args['city'], 'temp' => '24°C', 'condition' => 'sunny'])
);

echo 'User: What is the weather in Amman?'.PHP_EOL.PHP_EOL;

$response = $client->chat(
    [new Message('user', 'What is the weather in Amman?')],
    ['tools' => [$weatherTool], 'auto_execute_tools' => true]
);

echo 'AI: '.$response->getMessage()->getContent().PHP_EOL;

// ─── 4. Streaming ────────────────────────────────────────────────────────────
echo PHP_EOL.'═══ Streaming ═══'.PHP_EOL.PHP_EOL;

echo 'User: Count from 1 to 5.'.PHP_EOL;
echo 'AI: ';

$client->streamChat(
    [new Message('user', 'Count from 1 to 5.')],
    function (string $token)
    {
        echo $token;
        flush();
    },
    function ($response)
    {
        echo PHP_EOL.'Interaction ID: '.$response->getRequestId().PHP_EOL;
    }
);

// ─── 5. Manual override: force INTERACTIONS on any model ─────────────────────
echo PHP_EOL.'═══ Manual API Version Override ═══'.PHP_EOL.PHP_EOL;

// Force INTERACTIONS API even on a gemini-2.x model (for testing)
$overrideClient = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    credentials: __DIR__.'/../../keys/vertex-ai-key.json',
    apiVersion: GoogleApiVersion::INTERACTIONS, // Force Interactions API
));

echo 'Using gemini-2.5-flash with INTERACTIONS API override'.PHP_EOL;

$response = $overrideClient->chat([new Message('user', 'Say hi in one word.')]);
echo 'AI: '.$response->getMessage()->getContent().PHP_EOL;
echo 'Interaction ID: '.$response->getRequestId().PHP_EOL;
