# ModelRouter — Intelligent Multi-Provider Routing

Route requests to different providers/models based on task characteristics. The router implements `ProviderInterface` so it's a transparent drop-in replacement.

## Concepts

**Tiers** are logical names (`'fast'`, `'smart'`, `'coding'`) that map to providers. Combined with `ModelAliases`, each provider resolves the tier to its own model ID.

**Routing priority** (highest to lowest):
1. `forceRoute()` — global override
2. `force_provider` option — per-call override
3. Strategy (`setStrategy()`)
4. Rules (`addRule()`)
5. Default tier

## Quick Start

```php
use WebFiori\Ai\Routing\ModelRouter;
use WebFiori\Ai\Routing\RoutingRule;
use WebFiori\Ai\Routing\Strategy\TaskComplexityStrategy;

$router = new ModelRouter(
    providers: ['fast' => $openai, 'smart' => $google],
    default: 'fast',
);

// Strategy-based: auto-selects tier based on message complexity
$router->setStrategy(new TaskComplexityStrategy('fast', 'smart'));

$response = $router->chat($messages); // routes transparently
```

## Integration with ModelAliases

```php
$aliases = new ModelAliases([
    'fast'  => ['openai' => 'gpt-4o-mini', 'google' => 'gemini-2.5-flash'],
    'smart' => ['openai' => 'gpt-4o',      'google' => 'gemini-2.5-pro'],
]);

$openai->setModelAliases($aliases);
$google->setModelAliases($aliases);

$router = new ModelRouter(['fast' => $openai, 'smart' => $google]);
// Tier 'fast' → 'gpt-4o-mini' for OpenAI, 'gemini-2.5-flash' for Google
```

## Built-in Strategies

| Strategy | Logic |
|----------|-------|
| `AlwaysStrategy('fast')` | Always route to a fixed tier |
| `TokenLengthStrategy('fast', 'smart', 500)` | Short → fast, long → smart |
| `KeywordStrategy(['smart' => ['analyze', 'compare']], 'fast')` | Pattern matching |
| `TaskComplexityStrategy('fast', 'smart')` | Multi-signal scoring |

### TaskComplexityStrategy signals

Each signal adds 1 point. Configurable threshold (default: 2):
- Message length > 300 chars
- Complexity keywords (analyze, compare, summarize...)
- 3+ tools available
- File attachments present
- 5+ user messages in conversation

## Rule-based Routing

```php
$router->addRule(new RoutingRule(
    condition: fn($msgs, $opts) => count($opts['tools'] ?? []) > 0,
    tier: 'smart',
    priority: 10,
    description: 'Tool use → smart tier',
));
```

## Tool-based Classification (HYBRID / TOOL mode)

```php
$router->setMode(RoutingMode::HYBRID);    // rules first, classifier fallback
$router->setClassifier($classifierClient); // any provider

$router->addRoute('fast', 'Simple questions, greetings, quick lookups');
$router->addRoute('smart', 'Complex analysis, code, multi-step reasoning');
// Classifier calls route_to(tier: 'smart') when it can't match a rule
```

## Observability

```php
$router->onRoute(function (RouteResult $result): void {
    echo "Routed to {$result->getTier()} via {$result->getProvider()->getName()}";
    echo " (reason: {$result->getReason()})";
});
```

## Overrides

```php
// Per-call
$router->chat($messages, ['force_provider' => 'fast']);

// Global
$router->forceRoute('fast');   // lock to tier
$router->forceRoute(null);     // remove lock
```

## Run

```bash
php examples/23-model-router/router.php
```
