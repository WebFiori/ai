<?php

/**
 * Example 24: Response Recording & Replay — Replay step
 *
 * Run AFTER record.php:
 *   php examples/24-recording-replay/replay.php
 *
 * No API key needed — responses loaded from fixtures/.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Http\Recording\FixtureNotFoundException;
use WebFiori\Ai\Http\Recording\ReplayHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Tool\Tool;

$fixturesPath = __DIR__.'/fixtures';

if (!is_dir($fixturesPath) || empty(glob($fixturesPath.'/*.json'))) {
    echo "❌ No fixtures found. Run record.php first.\n";
    exit(1);
}

// No real API key needed — 'FAKE' is fine for replay
$client = new AnthropicClient(new AnthropicClientConfig(
    apiKey: 'FAKE_KEY_NOT_NEEDED',
    model: 'claude-haiku-4-5-20251001',
));
$client->setHttpClient(new ReplayHttpClient($fixturesPath));

echo "Loaded ".count(glob($fixturesPath.'/*.json'))." fixture(s)\n\n";

// ─── Replay: Basic chat ───────────────────────────────────────────────────────
echo "Replaying: basic chat...\n";
$response = $client->chat([
    new Message('system', 'You are a helpful assistant. Keep responses concise.'),
    new Message('user', 'What is PHP in one sentence?'),
]);
echo "  ✅ Response: ".substr($response->getMessage()->getContent(), 0, 80)."\n\n";

// ─── Replay: Tool calling ─────────────────────────────────────────────────────
echo "Replaying: tool calling...\n";
$weatherTool = new Tool(
    'get_weather',
    'Returns current weather for a city.',
    ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
    fn(array $args) => "22°C and sunny in {$args['city']}"
);
$response = $client->chat(
    [new Message('user', 'What is the weather in Amman?')],
    ['tools' => [$weatherTool], 'auto_execute_tools' => true]
);
echo "  ✅ Response: ".substr($response->getMessage()->getContent(), 0, 80)."\n\n";

// ─── Replay: Streaming ────────────────────────────────────────────────────────
echo "Replaying: streaming...\n";
$tokens = [];
$client->streamChat(
    [new Message('user', 'Count from 1 to 3.')],
    function (string $token) use (&$tokens)
    {
        $tokens[] = $token;
    }
);
echo "  ✅ Received ".count($tokens)." chunk(s): ".implode('', $tokens)."\n\n";

// ─── Demonstrate: fixture miss ────────────────────────────────────────────────
echo "Demonstrating: fixture miss throws clearly...\n";
try {
    $client->chat([new Message('user', 'This was never recorded.')]);
} catch (FixtureNotFoundException $e) {
    echo "  ✅ FixtureNotFoundException: ".substr($e->getMessage(), 0, 100)."...\n\n";
}

echo "✅ All replayed from fixtures — zero API calls made.\n";
