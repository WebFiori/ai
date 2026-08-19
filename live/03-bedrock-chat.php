<?php

/**
 * Live Test 03: AWS Bedrock — Chat and Streaming
 *
 * Usage:
 *   source keys/env.sh && php live/03-bedrock-chat.php
 *
 * Note: Requires an active model. Update BEDROCK_MODEL in live/helpers.php
 * if the current model is EOL.
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\Tool;

section('AWS Bedrock — Claude via API Key');

// ─── Availability check ───────────────────────────────────────────────────────
$bedrockAvailable = true;
try {
    bedrockClient()->chat([new Message('user', 'hi')]);
} catch (ProviderException $e) {
    if (str_contains($e->getMessage(), 'end of its life') || str_contains($e->getMessage(), 'invalid')) {
        echo "  \033[33m⚠  SKIP\033[0m  Model '".BEDROCK_MODEL."' is not available: {$e->getMessage()}\n";
        echo "          Update BEDROCK_MODEL in live/helpers.php.\n\n";
        $bedrockAvailable = false;
    }
} catch (RuntimeException $e) {
    echo "  \033[33m⚠  SKIP\033[0m  {$e->getMessage()}\n\n";
    $bedrockAvailable = false;
}

if (!$bedrockAvailable) {
    echo "\n";

    return;
}

// ─── 1. Basic chat ────────────────────────────────────────────────────────────
run('Basic chat completion', function ()
{
    $response = bedrockClient()->chat([
        new Message('system', 'You are a helpful assistant. Keep responses concise.'),
        new Message('user', 'What is PHP in one sentence?'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 100)."\n";

    if ($response->getUsage()) {
        echo "    → Tokens: {$response->getUsage()->getTotalTokens()}\n";
    }
});

// ─── 2. Streaming ─────────────────────────────────────────────────────────────
run('Streaming chat', function ()
{
    $tokens = [];
    $completed = false;

    bedrockClient()->streamChat(
        [new Message('user', 'Count from 1 to 5.')],
        function (string $token) use (&$tokens)
        {
            $tokens[] = $token;
        },
        function ($response) use (&$completed)
        {
            $completed = true;
        }
    );

    assert(!empty($tokens), 'No tokens received');
    assert($completed, 'onComplete not called');
    echo "    → Received ".count($tokens)." token chunks\n";
    echo "    → Full: ".implode('', $tokens)."\n";
});

// ─── 3. Tool calling ──────────────────────────────────────────────────────────
run('Tool calling (auto-execute)', function ()
{
    $weatherTool = new Tool(
        'get_weather',
        'Returns current weather for a city.',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        fn(array $args) => "20°C and cloudy in {$args['city']}"
    );

    $response = bedrockClient()->chat(
        [new Message('user', 'What is the weather in Amman?')],
        ['tools' => [$weatherTool], 'auto_execute_tools' => true]
    );

    assert(!$response->hasToolCalls(), 'Still has tool calls');
    assert($response->getMessage()->getContent() !== '', 'Empty final response');
    echo "    → Final: ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// ─── 4. Health check ──────────────────────────────────────────────────────────
run('Health check', function ()
{
    $result = bedrockClient()->healthCheck(5);
    echo "    → Available: ".($result->isAvailable() ? 'yes' : 'no')."\n";
    echo "    → Latency: {$result->getLatencyMs()}ms\n";

    if (!$result->isAvailable()) {
        echo "    → Error: {$result->getError()}\n";
    }
    // Don't fail on health check — network issues are expected
});

echo "\n";
