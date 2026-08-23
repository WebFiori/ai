<?php

/**
 * Example 24: Response Recording & Replay — Record step using Anthropic
 *
 * Run: source keys/env.sh && php examples/24-recording-replay/record.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Http\Recording\RecordingHttpClient;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Tool\Tool;

$fixturesPath = __DIR__.'/fixtures';

if (!is_dir($fixturesPath)) {
    mkdir($fixturesPath, 0777, true);
}

$apiKey = getenv('ANTHROPIC_API_KEY') ?: getenv('OPENAI_API_KEY') ?: null;

if (!$apiKey) {
    echo "❌ Set ANTHROPIC_API_KEY or OPENAI_API_KEY to record.\n";
    exit(1);
}

// Use Anthropic if available, otherwise show OpenAI example
$client = new AnthropicClient(new AnthropicClientConfig(
    apiKey: $apiKey,
    model: getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001',
));

// ─── Configure RecordingHttpClient ────────────────────────────────────────────
$recorder = new RecordingHttpClient(
    inner: new CurlHttpClient(),
    path: $fixturesPath,
);
$client->setHttpClient($recorder);

// ─── Record: Basic chat ───────────────────────────────────────────────────────
echo "Recording: basic chat...\n";
$response = $client->chat([
    new Message('system', 'You are a helpful assistant. Keep responses concise.'),
    new Message('user', 'What is PHP in one sentence?'),
]);
echo "  Response: ".substr($response->getMessage()->getContent(), 0, 80)."\n";
echo "  Fixtures saved: ".count(glob($fixturesPath.'/*.json'))."\n\n";

// ─── Record: Tool calling ─────────────────────────────────────────────────────
echo "Recording: tool calling...\n";
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
echo "  Response: ".substr($response->getMessage()->getContent(), 0, 80)."\n";
echo "  Fixtures saved: ".count(glob($fixturesPath.'/*.json'))."\n\n";

// ─── Record: Streaming ────────────────────────────────────────────────────────
echo "Recording: streaming...\n";
$tokens = [];
$client->streamChat(
    [new Message('user', 'Count from 1 to 3.')],
    function (string $token) use (&$tokens) { $tokens[] = $token; }
);
echo "  Received ".count($tokens)." chunk(s): ".implode('', $tokens)."\n";
echo "  Fixtures saved: ".count(glob($fixturesPath.'/*.json'))."\n\n";

echo "✅ Recording complete. Run replay.php next.\n";
