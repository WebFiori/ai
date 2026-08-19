<?php

/**
 * Live Test 02: Gemini 3.x — Chat, Streaming, Tools (Interactions API)
 *
 * Usage:
 *   php live/02-gemini3-interactions.php
 *
 * Note: The Interactions API requires a gemini-3.x model. Set the
 * GEMINI_3_MODEL constant in helpers.php to an available gemini-3.x model.
 * Tests will be skipped if the model is not available on the configured endpoint.
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\Tool;

section('Gemini 3.x — Interactions API');

// ─── Availability check ───────────────────────────────────────────────────────
$modelAvailable = true;
try {
    $checkResponse = gemini3Client()->chat([new Message('user', 'hi')]);
} catch (ProviderException $e) {
    if (str_contains($e->getMessage(), 'Unsupported model') || str_contains($e->getMessage(), 'not found')) {
        echo "  \033[33m⚠  SKIP\033[0m  Model '".GEMINI_3_MODEL."' not available on this endpoint.\n";
        echo "          Update GEMINI_3_MODEL in live/helpers.php to a gemini-3.x model.\n\n";
        $modelAvailable = false;
    }
}

if (!$modelAvailable) {
    echo "\n";

    return;
}

section('Gemini 3.x — Interactions API');

// ─── 1. Basic chat via Interactions API ───────────────────────────────────────
run('Basic chat (Interactions API)', function ()
{
    $response = gemini3Client()->chat([
        new Message('system', 'You are a helpful assistant. Keep responses concise.'),
        new Message('user', 'What is PHP in one sentence?'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    assert($response->getRequestId() !== null, 'No interaction ID returned');
    echo "    → Interaction ID: {$response->getRequestId()}\n";
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";

    if ($response->getUsage()) {
        echo "    → Tokens: {$response->getUsage()->getTotalTokens()}\n";
    }
});

// ─── 2. Raw steps stored on message ───────────────────────────────────────────
run('Raw steps preserved for stateless replay', function ()
{
    $response = gemini3Client()->chat([
        new Message('user', 'Say hello in exactly 3 words.'),
    ]);

    $rawSteps = $response->getMessage()->getRawSteps();
    assert($rawSteps !== null, 'No raw steps on message');
    assert(count($rawSteps) > 0, 'Empty raw steps');
    echo "    → ".count($rawSteps)." step(s): ".implode(', ', array_column($rawSteps, 'type'))."\n";
});

// ─── 3. Multi-turn stateless conversation ─────────────────────────────────────
run('Multi-turn stateless conversation', function ()
{
    $client = gemini3Client();

    // Turn 1
    $messages = [new Message('user', 'My favourite language is PHP.')];
    $r1 = $client->chat($messages);

    // Append assistant message with raw steps for replay
    $messages[] = $r1->getMessage();
    $messages[] = new Message('user', 'What language did I mention?');

    // Turn 2
    $r2 = $client->chat($messages);

    assert(stripos($r2->getMessage()->getContent(), 'php') !== false, 'PHP not recalled');
    echo "    → Turn 2 response: ".$r2->getMessage()->getContent()."\n";
});

// ─── 4. Tool calling ──────────────────────────────────────────────────────────
run('Tool calling (manual execution)', function ()
{
    $weatherTool = new Tool(
        'get_weather',
        'Returns current weather for a given city.',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        fn(array $args) => "25°C, clear in {$args['city']}"
    );

    $response = gemini3Client()->chat(
        [new Message('user', 'What is the weather in Amman?')],
        ['tools' => [$weatherTool]]
    );

    assert($response->hasToolCalls(), 'Expected tool call');
    $call = $response->getMessage()->getToolCalls()[0];
    assert($call->getName() === 'get_weather', 'Wrong tool');
    echo "    → Tool: {$call->getName()}({$call->getArguments()['city']})\n";
    echo "    → call_id: {$call->getId()}\n";
});

// ─── 5. Tool auto-execute loop ────────────────────────────────────────────────
run('Tool auto-execute loop', function ()
{
    $weatherTool = new Tool(
        'get_weather',
        'Returns current weather for a city.',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        fn(array $args) => "30°C and sunny in {$args['city']}"
    );

    $response = gemini3Client()->chat(
        [new Message('user', 'What is the weather in Dubai?')],
        ['tools' => [$weatherTool], 'auto_execute_tools' => true]
    );

    assert(!$response->hasToolCalls(), 'Still has tool calls');
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → Final: ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// ─── 6. Streaming ─────────────────────────────────────────────────────────────
run('Streaming (Interactions API)', function ()
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
    echo "    → Received ".count($tokens)." token chunks\n";
    echo "    → Full: ".implode('', $tokens)."\n";
    echo "    → Interaction ID: {$interactionId}\n";
});

echo "\n";
