<?php

/**
 * Live Test 08: Vertex AI Model Garden — Anthropic Claude on Vertex AI
 *
 * Usage:
 *   php live/08-model-garden.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\Tool;

section('Vertex AI Model Garden — Anthropic Claude');

function claudeOnVertex(): GoogleClient {
    return new GoogleClient(new GoogleClientConfig(
        model: 'claude-sonnet-5',
        projectId: GCP_PROJECT,
        location: 'us-east5',
        credentials: KEY_PATH,
        api: GoogleApi::VERTEX_AI,
        publisher: 'anthropic',
    ));
}

// ─── Availability check ───────────────────────────────────────────────────────
$available = true;
try {
    claudeOnVertex()->chat([new Message('user', 'hi')]);
    sleep(15);
} catch (WebFiori\Ai\Exception\ProviderException $e) {
    if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), '404')) {
        echo "  \033[33m⚠  SKIP\033[0m  Claude on Vertex not available: ".substr($e->getMessage(), 0, 80)."\n\n";
        $available = false;
    }
} catch (WebFiori\Ai\Exception\RateLimitException $e) {
    echo "  \033[33m⚠  NOTE\033[0m  Rate limit on availability check — waiting 30s for quota reset...\n";
    sleep(30);
}

if (!$available) {
    echo "\n";

    return;
}

// ─── 1. getName ───────────────────────────────────────────────────────────────
run('Client getName() returns vertex:anthropic', function ()
{
    $client = claudeOnVertex();
    assert($client->getName() === 'vertex:anthropic', 'Wrong name: '.$client->getName());
    echo "    → Name: {$client->getName()}\n";
});

sleep(20);

// ─── 2. Basic chat ────────────────────────────────────────────────────────────
run('Basic chat — Claude on Vertex AI', function ()
{
    $response = claudeOnVertex()->chat([
        new Message('system', 'You are a helpful assistant. Keep responses concise.'),
        new Message('user', 'What is PHP in one sentence?'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    assert($response->getModel() !== '', 'No model in response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
    echo "    → Model: {$response->getModel()}\n";

    if ($response->getUsage()) {
        echo "    → Tokens: {$response->getUsage()->getTotalTokens()}\n";
    }
});

sleep(20);

// ─── 3. Multi-turn ────────────────────────────────────────────────────────────
run('Multi-turn conversation', function ()
{
    $client = claudeOnVertex();
    $messages = [new Message('user', 'My favourite language is PHP.')];
    $r1 = $client->chat($messages);
    sleep(15);
    $messages[] = $r1->getMessage();
    $messages[] = new Message('user', 'What language did I mention?');
    $r2 = $client->chat($messages);

    assert(stripos($r2->getMessage()->getContent(), 'php') !== false, 'PHP not recalled');
    echo "    → Turn 2: ".$r2->getMessage()->getContent()."\n";
});

sleep(20);

// ─── 4. Tool calling ──────────────────────────────────────────────────────────
run('Tool calling with auto-execute', function ()
{
    $tool = new Tool(
        'get_weather',
        'Returns current weather for a city.',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        fn(array $args) => "24°C and sunny in {$args['city']}"
    );

    $response = claudeOnVertex()->chat(
        [new Message('user', 'What is the weather in Amman?')],
        ['tools' => [$tool], 'auto_execute_tools' => true]
    );

    assert(!$response->hasToolCalls(), 'Still has tool calls');
    assert($response->getMessage()->getContent() !== '', 'Empty final response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

sleep(20);

// ─── 5. FallbackProvider ──────────────────────────────────────────────────────
run('FallbackProvider: Claude on Vertex + Gemini fallback', function ()
{
    $fallback = new WebFiori\Ai\Provider\Fallback\FallbackProvider([
        claudeOnVertex(),
        gemini2Client(),
    ]);

    $response = $fallback->chat([new Message('user', 'Say hi in one word.')]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → Provider: {$fallback->getLastUsedProvider()}\n";
    echo "    → Response: {$response->getMessage()->getContent()}\n";
});

echo "\n";
