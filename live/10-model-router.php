<?php

/**
 * Live Test 10: ModelRouter — intelligent routing
 *
 * Usage:
 *   source keys/env.sh && php live/10-model-router.php
 *
 * Uses Gemini (fast) and Anthropic Claude (smart) as the two tiers.
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\ModelAliases;
use WebFiori\Ai\Routing\ModelRouter;
use WebFiori\Ai\Routing\RoutingMode;
use WebFiori\Ai\Routing\RoutingRule;
use WebFiori\Ai\Routing\Strategy\KeywordStrategy;
use WebFiori\Ai\Routing\Strategy\TaskComplexityStrategy;
use WebFiori\Ai\Routing\Strategy\TokenLengthStrategy;

section('ModelRouter — Intelligent Routing');

// Two providers: Gemini (fast) and Claude (smart)
// Set ModelAliases so tier names resolve to actual model IDs
$aliases = new ModelAliases([
    'fast'  => ['google' => 'gemini-2.5-flash', 'anthropic' => 'claude-haiku-4-5-20251001'],
    'smart' => ['google' => 'gemini-2.5-flash',  'anthropic' => 'claude-haiku-4-5-20251001'],
]);

$fast = gemini2Client();
$fast->setModelAliases($aliases);

$smart = anthropicClient();
$smart->setModelAliases($aliases);

// ─── Helper ───────────────────────────────────────────────────────────────────
function makeRouter($fast, $smart): ModelRouter {
    $router = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
    $router->onRoute(function ($result) {
        echo "    → Tier: {$result->getTier()} | Reason: {$result->getReason()}\n";
    });
    return $router;
}

// ─── 1. Rule-based routing ────────────────────────────────────────────────────
run('Rule-based: long message → smart', function () use ($fast, $smart) {
    $router = makeRouter($fast, $smart);
    $router->addRule(new RoutingRule(
        fn($msgs, $opts) => strlen($msgs[0]->getContent()) > 100,
        'smart',
        priority: 10,
        description: 'long message'
    ));

    $response = $router->chat([new Message('user', 'Hi!')]);
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → Short: ".substr($response->getMessage()->getContent(), 0, 60)."\n";

    $response = $router->chat([new Message('user', str_repeat('Analyze this very long complex message. ', 4))]);
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → Long: ".substr($response->getMessage()->getContent(), 0, 60)."\n";
});

// ─── 2. TaskComplexityStrategy ────────────────────────────────────────────────
run('TaskComplexityStrategy routing', function () use ($fast, $smart) {
    $router = makeRouter($fast, $smart);
    $router->setStrategy(new TaskComplexityStrategy('fast', 'smart', scoreThreshold: 1));

    $response = $router->chat([new Message('user', 'Hi!')]);
    assert($response->getMessage()->getContent() !== '', 'Empty response');

    $response = $router->chat([new Message('user', 'Please analyze and summarize the key differences between REST and GraphQL.')]);
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 3. KeywordStrategy ───────────────────────────────────────────────────────
run('KeywordStrategy routing', function () use ($fast, $smart) {
    $router = makeRouter($fast, $smart);
    $router->setStrategy(new KeywordStrategy([
        'smart' => ['analyze', 'compare', 'summarize', 'explain in detail'],
    ], default: 'fast'));

    $response = $router->chat([new Message('user', 'What is PHP?')]);
    assert($response->getMessage()->getContent() !== '', 'Empty fast response');

    $response = $router->chat([new Message('user', 'Please analyze the pros and cons of microservices.')]);
    assert($response->getMessage()->getContent() !== '', 'Empty smart response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 4. TokenLengthStrategy ───────────────────────────────────────────────────
run('TokenLengthStrategy routing', function () use ($fast, $smart) {
    $router = makeRouter($fast, $smart);
    $router->setStrategy(new TokenLengthStrategy('fast', 'smart', threshold: 50));

    $router->chat([new Message('user', 'Hi!')]);
    $router->chat([new Message('user', str_repeat('This is a longer message. ', 3))]);

    echo "    → Both routed correctly\n";
});

// ─── 5. Force provider override ───────────────────────────────────────────────
run('Force provider override', function () use ($fast, $smart) {
    $router = makeRouter($fast, $smart);
    $router->setStrategy(new TaskComplexityStrategy('fast', 'smart', scoreThreshold: 1));

    // Complex message would go to smart, but we force fast
    $response = $router->chat(
        [new Message('user', 'Please analyze and summarize in detail.')],
        ['force_provider' => 'fast']
    );
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 60)."\n";
});

// ─── 6. Hybrid mode with classifier ───────────────────────────────────────────
run('Hybrid mode: rule first, classifier fallback', function () use ($fast, $smart) {
    $router = makeRouter($fast, $smart);
    $router->setMode(RoutingMode::HYBRID);

    // Use Gemini as the classifier
    $classifier = gemini2Client();
    $router->setClassifier($classifier);
    $router->addRoute('fast', 'Simple questions, greetings, quick facts');
    $router->addRoute('smart', 'Complex analysis, code review, detailed explanations');

    // Rule handles obvious case
    $router->addRule(new RoutingRule(
        fn($msgs, $opts) => strtolower($msgs[0]->getContent()) === 'hi',
        'fast',
        priority: 10
    ));

    // Rule matches → no classifier call
    $response = $router->chat([new Message('user', 'hi')]);
    assert($response->getMessage()->getContent() !== '', 'Empty response');

    // No rule matches → classifier decides
    $response = $router->chat([new Message('user', 'What is the best approach for scaling a distributed database?')]);
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 7. ModelRouter + FallbackProvider ────────────────────────────────────────
run('ModelRouter inside FallbackProvider', function () use ($fast, $smart) {
    $router = makeRouter($fast, $smart);
    $router->setStrategy(new TaskComplexityStrategy('fast', 'smart'));

    $fallback = new \WebFiori\Ai\Provider\Fallback\FallbackProvider([$router]);
    $response = $fallback->chat([new Message('user', 'What is PHP?')]);
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → Provider: {$fallback->getLastUsedProvider()}\n";
    echo "    → ".substr($response->getMessage()->getContent(), 0, 60)."\n";
});

// ─── 8. onRoute observability ─────────────────────────────────────────────────
run('onRoute callback captures all routing decisions', function () use ($fast, $smart) {
    $routingLog = [];
    $router = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
    $router->setStrategy(new KeywordStrategy(['smart' => ['analyze']], 'fast'));
    $router->onRoute(function ($result) use (&$routingLog) {
        $routingLog[] = ['tier' => $result->getTier(), 'reason' => $result->getReason()];
    });

    $router->chat([new Message('user', 'Hi!')]);
    $router->chat([new Message('user', 'Please analyze this.')]);

    assert(count($routingLog) === 2, 'Expected 2 log entries');
    assert($routingLog[0]['tier'] === 'fast', 'First should be fast');
    assert($routingLog[1]['tier'] === 'smart', 'Second should be smart');
    echo "    → Log: ".json_encode(array_column($routingLog, 'tier'))."\n";
});

echo "\n";
