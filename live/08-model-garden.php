<?php

/**
 * Live Test 08: Vertex AI Model Garden — Anthropic Claude on Vertex AI
 *
 * Usage:
 *   php live/08-model-garden.php
 *
 * Tests non-Google models running on Vertex AI infrastructure.
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\Tool;

section('Vertex AI Model Garden — Anthropic Claude');

// Helper to create a Claude client on Vertex AI
// us-east5 is the primary location for Anthropic models
function claudeOnVertex(): GoogleClient {
    return new GoogleClient(new GoogleClientConfig(
        model: 'claude-haiku-4-5@20251001',
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
} catch (\WebFiori\Ai\Exception\ProviderException $e) {
    if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'permission') || str_contains($e->getMessage(), '404')) {
        echo "  \033[33m⚠  SKIP\033[0m  Claude on Vertex not available: ".substr($e->getMessage(), 0, 80)."\n\n";
        $available = false;
    }
}

if (!$available) {
    echo "\n";
    return;
}

// ─── 1. Basic chat ────────────────────────────────────────────────────────────
run('Basic chat — Claude on Vertex AI', function () {
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

// ─── 2. Client name ───────────────────────────────────────────────────────────
run('Client getName() returns vertex:anthropic', function () {
    $client = claudeOnVertex();
    assert($client->getName() === 'vertex:anthropic', 'Wrong name: '.$client->getName());
    echo "    → Name: {$client->getName()}\n";
});

// ─── 3. Multi-turn ────────────────────────────────────────────────────────────
run('Multi-turn conversation', function () {
    $client = claudeOnVertex();
    $messages = [new Message('user', 'My favourite language is PHP.')];
    $r1 = $client->chat($messages);
    $messages[] = $r1->getMessage();
    $messages[] = new Message('user', 'What language did I mention?');
    $r2 = $client->chat($messages);

    assert(stripos($r2->getMessage()->getContent(), 'php') !== false, 'PHP not recalled');
    echo "    → Turn 2: ".$r2->getMessage()->getContent()."\n";
});

// ─── 4. Tool calling ──────────────────────────────────────────────────────────
run('Tool calling with auto-execute', function () {
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

// ─── 5. FallbackProvider with Claude on Vertex ───────────────────────────────
run('FallbackProvider: Claude on Vertex + Gemini fallback', function () {
    $fallback = new \WebFiori\Ai\Provider\Fallback\FallbackProvider([
        claudeOnVertex(),   // Primary: Claude on Vertex
        gemini2Client(),    // Fallback: Gemini
    ]);

    $response = $fallback->chat([new Message('user', 'Say hi in one word.')]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → Provider: {$fallback->getLastUsedProvider()}\n";
    echo "    → Response: {$response->getMessage()->getContent()}\n";
});

echo "\n";
