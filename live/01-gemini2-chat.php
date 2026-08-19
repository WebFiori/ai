<?php

/**
 * Live Test 01: Gemini 2.x — Chat, Streaming, Tools (generateContent API)
 *
 * Usage:
 *   php live/01-gemini2-chat.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolResult;

section('Gemini 2.x — generateContent API');

// ─── 1. Basic chat ────────────────────────────────────────────────────────────
run('Basic chat completion', function () {
    $response = gemini2Client()->chat([
        new Message('system', 'You are a helpful assistant. Keep responses concise.'),
        new Message('user', 'What is PHP in one sentence?'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    assert($response->getUsage() !== null, 'No usage');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."...\n";
    echo "    → Tokens: {$response->getUsage()->getTotalTokens()}\n";
});

// ─── 2. Streaming ─────────────────────────────────────────────────────────────
run('Streaming chat', function () {
    $tokens = [];
    $completed = false;

    gemini2Client()->streamChat(
        [new Message('user', 'Count from 1 to 5.')],
        function (string $token) use (&$tokens) {
            $tokens[] = $token;
        },
        function ($response) use (&$completed) {
            $completed = true;
        }
    );

    assert(!empty($tokens), 'No tokens received');
    assert($completed, 'onComplete not called');
    echo "    → Received ".count($tokens)." token chunks\n";
    echo "    → Full: ".implode('', $tokens)."\n";
});

// ─── 3. Multi-turn conversation ───────────────────────────────────────────────
run('Multi-turn conversation', function () {
    $client = gemini2Client();
    $messages = [
        new Message('user', 'My name is Ibrahim.'),
    ];

    $r1 = $client->chat($messages);
    $messages[] = $r1->getMessage();
    $messages[] = new Message('user', 'What is my name?');

    $r2 = $client->chat($messages);

    assert(stripos($r2->getMessage()->getContent(), 'ibrahim') !== false, 'Name not recalled');
    echo "    → Response: ".$r2->getMessage()->getContent()."\n";
});

// ─── 4. Tool calling (manual) ─────────────────────────────────────────────────
run('Tool calling (manual execution)', function () {
    $client = gemini2Client();
    $weatherTool = new Tool(
        'get_weather',
        'Returns current weather for a given city.',
        [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string', 'description' => 'The city name']],
            'required' => ['city'],
        ],
        fn(array $args) => "22°C, sunny in {$args['city']}"
    );

    $response = $client->chat(
        [new Message('user', 'What is the weather in Amman?')],
        ['tools' => [$weatherTool]]
    );

    assert($response->hasToolCalls(), 'Expected tool call');
    $call = $response->getMessage()->getToolCalls()[0];
    assert($call->getName() === 'get_weather', 'Wrong tool called');
    echo "    → Tool called: {$call->getName()}({$call->getArguments()['city']})\n";
});

// ─── 5. Tool auto-execute ─────────────────────────────────────────────────────
run('Tool calling (auto-execute)', function () {
    $weatherTool = new Tool(
        'get_weather',
        'Returns current weather for a given city.',
        [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ],
        fn(array $args) => "18°C and cloudy in {$args['city']}"
    );

    $response = gemini2Client()->chat(
        [new Message('user', 'What is the weather in Dubai?')],
        ['tools' => [$weatherTool], 'auto_execute_tools' => true]
    );

    assert(!$response->hasToolCalls(), 'Still has unexecuted tool calls');
    assert($response->getMessage()->getContent() !== '', 'Empty final response');
    echo "    → Final: ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// ─── 6. JSON mode ─────────────────────────────────────────────────────────────
run('JSON mode (structured output)', function () {
    $response = gemini2Client()->chat(
        [new Message('user', 'Return a JSON object with keys "name" and "language" for PHP.')],
        ['json_mode' => true]
    );

    $json = json_decode($response->getMessage()->getContent(), true);
    assert($json !== null, 'Response is not valid JSON');
    echo "    → JSON: ".json_encode($json)."\n";
});

echo "\n";
