<?php

/**
 * Live Test 09: Anthropic Claude — direct API
 *
 * Usage:
 *   source keys/env.sh && php live/09-anthropic-chat.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\Tool;

section('Anthropic Claude — Direct API');

// ─── 1. Basic chat ────────────────────────────────────────────────────────────
run('Basic chat completion', function () {
    $response = anthropicClient()->chat([
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
run('Streaming chat', function () {
    $tokens = [];
    $completed = false;

    anthropicClient()->streamChat(
        [new Message('user', 'Count from 1 to 5.')],
        function (string $token) use (&$tokens) { $tokens[] = $token; },
        function ($response) use (&$completed) { $completed = true; }
    );

    assert(!empty($tokens), 'No tokens received');
    assert($completed, 'onComplete not called');
    echo "    → Received ".count($tokens)." token chunks\n";
    echo "    → Full: ".implode('', $tokens)."\n";
});

// ─── 3. Multi-turn conversation ───────────────────────────────────────────────
run('Multi-turn conversation', function () {
    $client = anthropicClient();
    $messages = [new Message('user', 'My favourite number is 7.')];
    $r1 = $client->chat($messages);
    $messages[] = $r1->getMessage();
    $messages[] = new Message('user', 'What number did I mention?');
    $r2 = $client->chat($messages);

    assert(str_contains($r2->getMessage()->getContent(), '7'), 'Number not recalled');
    echo "    → ".$r2->getMessage()->getContent()."\n";
});

// ─── 4. Tool calling ──────────────────────────────────────────────────────────
run('Tool calling (auto-execute)', function () {
    $tool = new Tool(
        'get_weather',
        'Returns current weather for a city.',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        fn(array $args) => "22°C and sunny in {$args['city']}"
    );

    $response = anthropicClient()->chat(
        [new Message('user', 'What is the weather in Amman?')],
        ['tools' => [$tool], 'auto_execute_tools' => true]
    );

    assert(!$response->hasToolCalls(), 'Still has tool calls');
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// ─── 5. Health check ──────────────────────────────────────────────────────────
run('Health check', function () {
    $result = anthropicClient()->healthCheck(5);
    echo "    → Available: ".($result->isAvailable() ? 'yes' : 'no')."\n";
    echo "    → Latency: {$result->getLatencyMs()}ms\n";
    if (!$result->isAvailable()) {
        echo "    → Error: {$result->getError()}\n";
    }
});

echo "\n";
