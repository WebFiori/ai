<?php

/**
 * Live Test 05: Interactions API (gemini-3.5-flash)
 *
 * Usage:
 *   php live/05-interactions-api.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\Tool;

section('Interactions API — gemini-3.5-flash');

// ─── 1. Basic chat ────────────────────────────────────────────────────────────
run('Basic chat via Interactions API', function ()
{
    $response = gemini3Client()->chat([
        new Message('system', 'You are a helpful assistant. Keep responses concise.'),
        new Message('user', 'What is PHP in one sentence?'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    assert($response->getRequestId() !== null, 'No interaction ID');
    echo "    → ".$response->getMessage()->getContent()."\n";
    echo "    → Interaction ID: {$response->getRequestId()}\n";

    if ($response->getUsage()) {
        echo "    → Tokens: {$response->getUsage()->getTotalTokens()}\n";
    }
});

// ─── 2. Steps and raw steps ───────────────────────────────────────────────────
run('Response contains steps (thought + model_output)', function ()
{
    $response = gemini3Client()->chat([new Message('user', 'What is 2+2?')]);

    $steps = $response->getMessage()->getRawSteps();
    assert($steps !== null && count($steps) > 0, 'No raw steps');
    $types = array_column($steps, 'type');
    echo "    → Steps: ".implode(', ', $types)."\n";
    assert(in_array('model_output', $types), 'No model_output step');
});

// ─── 3. Multi-turn stateless ──────────────────────────────────────────────────
run('Multi-turn stateless conversation', function ()
{
    $client = gemini3Client();

    $messages = [new Message('user', 'My favourite number is 42.')];
    $r1 = $client->chat($messages);
    echo "    → Turn 1: ".$r1->getMessage()->getContent()."\n";

    $messages[] = $r1->getMessage(); // carries rawSteps
    $messages[] = new Message('user', 'What number did I mention?');
    $r2 = $client->chat($messages);
    echo "    → Turn 2: ".$r2->getMessage()->getContent()."\n";

    assert(stripos($r2->getMessage()->getContent(), '42') !== false, '42 not recalled');
});

// ─── 4. Tool calling ──────────────────────────────────────────────────────────
run('Tool calling (manual execution)', function ()
{
    $tool = new Tool(
        'get_weather',
        'Returns weather for a city.',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        fn(array $args) => '25°C, sunny'
    );

    $response = gemini3Client()->chat(
        [new Message('user', 'What is the weather in Amman?')],
        ['tools' => [$tool]]
    );

    assert($response->hasToolCalls(), 'Expected tool call');
    $call = $response->getMessage()->getToolCalls()[0];
    echo "    → Tool: {$call->getName()}(".json_encode($call->getArguments()).")\n";
    echo "    → call_id: {$call->getId()}\n";
});

// ─── 5. Auto-execute tool loop ────────────────────────────────────────────────
run('Tool auto-execute loop', function ()
{
    $tool = new Tool(
        'get_weather',
        'Returns weather for a city.',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        fn(array $args) => "22°C and cloudy in {$args['city']}"
    );

    $response = gemini3Client()->chat(
        [new Message('user', 'What is the weather in Dubai?')],
        ['tools' => [$tool], 'auto_execute_tools' => true]
    );

    assert(!$response->hasToolCalls(), 'Still has tool calls');
    assert($response->getMessage()->getContent() !== '', 'Empty final response');
    echo "    → Final: ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// ─── 6. Streaming ─────────────────────────────────────────────────────────────
run('Streaming with named SSE events', function ()
{
    $tokens = [];
    $interactionId = null;

    gemini3Client()->streamChat(
        [new Message('user', 'Count from 1 to 3.')],
        function (string $token) use (&$tokens)
        {
            $tokens[] = $token;
        },
        function ($response) use (&$interactionId)
        {
            $interactionId = $response->getRequestId();
        }
    );

    assert(!empty($tokens), 'No tokens received');
    echo "    → Tokens received: ".count($tokens)."\n";
    echo "    → Full: ".implode('', $tokens)."\n";
    echo "    → Interaction ID: {$interactionId}\n";
});

// ─── 7. API version override ──────────────────────────────────────────────────
run('Force INTERACTIONS on gemini-2.x model', function ()
{
    $client = new GoogleClient(new GoogleClientConfig(
        model: 'gemini-2.5-flash',
        credentials: KEY_PATH,
        api: GoogleApi::GEMINI,
        apiVersion: GoogleApiVersion::INTERACTIONS,
    ));

    $response = $client->chat([new Message('user', 'Say hi in one word.')]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    assert($response->getRequestId() !== null, 'No interaction ID — not using Interactions API');
    echo "    → Response: {$response->getMessage()->getContent()}\n";
    echo "    → Interaction ID: {$response->getRequestId()}\n";
});

echo "\n";
