<?php

/**
 * Example 23: ModelRouter — Intelligent Multi-Provider Routing
 *
 * Run: php examples/23-model-router/router.php
 *
 * Demonstrates:
 * 1. Rule-based routing — instant, no extra API call
 * 2. Strategy-based routing (TaskComplexityStrategy)
 * 3. Hybrid routing — rules first, classifier fallback
 * 4. Observability with onRoute() callback
 * 5. Integration with ModelAliases
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\ModelAliases;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Routing\ModelRouter;
use WebFiori\Ai\Routing\RoutingRule;
use WebFiori\Ai\Routing\Strategy\KeywordStrategy;
use WebFiori\Ai\Routing\Strategy\TaskComplexityStrategy;
use WebFiori\Ai\Routing\Strategy\TokenLengthStrategy;

// ─── Providers ────────────────────────────────────────────────────────────────

$credentials = getenv('GCP_CREDENTIALS') ?: '/path/to/service-account.json';

// ModelAliases — each tier resolves to its own model ID
$aliases = new ModelAliases([
    'fast' => ['google' => 'gemini-2.5-flash'],
    'smart' => ['google' => 'gemini-2.5-pro'],
]);

$fast = new GoogleClient(new GoogleClientConfig(
    credentials: $credentials,
    projectId: 'webfiori',
    location: 'us-central1',
    api: GoogleApi::VERTEX_AI,
));
$fast->setModelAliases($aliases);

$smart = new GoogleClient(new GoogleClientConfig(
    credentials: $credentials,
    projectId: 'webfiori',
    location: 'us-central1',
    api: GoogleApi::VERTEX_AI,
));
$smart->setModelAliases($aliases);

// ─── Example 1: Rule-based routing ────────────────────────────────────────────
echo "═══ Example 1: Rule-based Routing ═══\n\n";

$router = new ModelRouter(
    providers: ['fast' => $fast, 'smart' => $smart],
    default: 'fast'
);

// Long messages → smart tier
$router->addRule(new RoutingRule(
    condition: fn($msgs, $opts) => strlen($msgs[count($msgs) - 1]->getContent()) > 200,
    tier: 'smart',
    priority: 10,
    description: 'Long messages → smart tier'
));

$router->onRoute(function ($result)
{
    echo "  Routed to '{$result->getTier()}' (reason: {$result->getReason()})\n";
});

echo "Short message:\n";
$response = $router->chat([new Message('user', 'What is PHP?')]);
echo "  Response: ".substr($response->getMessage()->getContent(), 0, 80)."...\n\n";

echo "Long message:\n";
$response = $router->chat([new Message('user', str_repeat('Please analyze this very detailed and complex topic about AI systems. ', 4))]);
echo "  Response: ".substr($response->getMessage()->getContent(), 0, 80)."...\n\n";

// ─── Example 2: Strategy-based routing ────────────────────────────────────────
echo "═══ Example 2: TaskComplexityStrategy ═══\n\n";

$router2 = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
$router2->setStrategy(new TaskComplexityStrategy('fast', 'smart', scoreThreshold: 2));
$router2->onRoute(fn($r) => print("  → {$r->getTier()} ({$r->getReason()})\n"));

echo "Simple question: ";
$router2->chat([new Message('user', 'Hi there!')]);

echo "Complex analysis: ";
$router2->chat([new Message('user', 'Please analyze and compare the architectural patterns in modern distributed systems, summarizing the key trade-offs.')]);

echo "\n";

// ─── Example 3: Keyword routing ───────────────────────────────────────────────
echo "═══ Example 3: KeywordStrategy ═══\n\n";

$router3 = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
$router3->setStrategy(new KeywordStrategy([
    'smart' => ['analyze', 'compare', 'summarize', 'explain in detail', 'step by step'],
], default: 'fast'));
$router3->onRoute(fn($r) => print("  → {$r->getTier()}\n"));

echo "Question with 'compare': ";
$router3->chat([new Message('user', 'Compare REST vs GraphQL APIs')]);

echo "Simple greeting: ";
$router3->chat([new Message('user', 'Hello!')]);

echo "\n";

// ─── Example 4: TokenLength routing ───────────────────────────────────────────
echo "═══ Example 4: TokenLengthStrategy ═══\n\n";

$router4 = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
$router4->setStrategy(new TokenLengthStrategy('fast', 'smart', threshold: 100));
$router4->onRoute(fn($r) => print("  → {$r->getTier()}\n"));

echo "Short (<100 chars): ";
$router4->chat([new Message('user', 'Quick question.')]);

echo "Long (>100 chars): ";
$router4->chat([new Message('user', str_repeat('This is a longer message. ', 5))]);

echo "\n";

// ─── Example 5: Force provider override ───────────────────────────────────────
echo "═══ Example 5: Force Provider Override ═══\n\n";

$router5 = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
$router5->setStrategy(new TaskComplexityStrategy('fast', 'smart'));
$router5->onRoute(fn($r) => print("  → {$r->getTier()} ({$r->getReason()})\n"));

echo "Strategy would pick smart, but force_provider overrides:\n";
$router5->chat(
    [new Message('user', 'Please analyze and compare distributed systems comprehensively')],
    ['force_provider' => 'fast']  // per-call override
);

echo "\nGlobal forceRoute:\n";
$router5->forceRoute('fast');
$router5->chat([new Message('user', 'Analyze everything in great detail and summarize comprehensively')]);
$router5->forceRoute(null); // remove

echo "\n";

// ─── Example 6: FallbackProvider inside ModelRouter ───────────────────────────
echo "═══ Example 6: ModelRouter + FallbackProvider ═══\n\n";

use WebFiori\Ai\Provider\Fallback\FallbackProvider;

// Each tier can itself be a FallbackProvider for extra resilience
$resilientFast = new FallbackProvider([$fast, $smart]); // fast tries, falls back to smart
$router6 = new ModelRouter(['fast' => $resilientFast], 'fast');
$router6->onRoute(fn($r) => print("  → {$r->getTier()} (via FallbackProvider)\n"));

$response = $router6->chat([new Message('user', 'What is PHP?')]);
echo "  Response: ".substr($response->getMessage()->getContent(), 0, 80)."...\n\n";
