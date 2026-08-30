# Example 29: Context Usage Snapshot

Demonstrates `getContextUsage()` — a structured snapshot of how much of a model's context window a request consumes. Useful for pre-send warnings ("you've used 6k of 8k tokens") and UI gauges.

## Key Concepts

- **`ContextUsage` DTO** — immutable snapshot with used/max/reserved/available/remaining/percentage
- **Ceiling resolution** — explicit `maxTokens` → context window strategy → `null`
- **Estimated vs actual** — estimates from input by default; uses exact prompt tokens when a response is passed
- **Percentage against max** — reads as "how full is the window"

## API

```php
$usage = $client->getContextUsage($messages, $tools, maxTokens: 8000);

$usage->getUsedTokens();       // 6200  (estimated or actual)
$usage->getMaxTokens();        // 8000  (null if unknown)
$usage->getReservedTokens();   // 2000
$usage->getAvailableTokens();  // 6000  (max - reserved)
$usage->getRemainingTokens();  // 0     (max(0, available - used))
$usage->getUsedPercentage();   // 77.5  (against max)
$usage->isOverBudget();        // true  (used > available)
$usage->isEstimated();         // true
$usage->toArray();             // for logging / JSON
```

## Ceiling Resolution

The context window ceiling (`maxTokens`) is resolved in order:

1. Explicit `$maxTokens` argument
2. Context window strategy's `getMaxTokens()` (if a strategy is set)
3. `ContextWindowConfig` lookup by the model name (ships with defaults for common models)
4. `null` — `usedTokens` is still reported; derived values are `null`

```php
// (1) Explicit
$client->getContextUsage($messages, maxTokens: 8000);

// (2) From strategy
$client->setContextWindowStrategy(new SlidingWindowStrategy(maxTokens: 8000, reserveForCompletion: 2000));
$client->getContextUsage($messages);

// (3) Auto-inferred from the model (e.g. gpt-4o → 128000)
$client->getContextUsage($messages);

// (4) Unknown model — used reported, max/remaining/percentage null
```

### Model context window table (`ContextWindowConfig`)

Ships with defaults for common OpenAI, Google, Anthropic, and Bedrock models. Override or extend as needed:

```php
use WebFiori\Ai\Context\ContextWindowConfig;

$config = $client->getContextWindowConfig();      // default table, auto-created
$config->getContextWindow('gpt-4o');              // 128000
$config->setContextWindow('my-fine-tune', 32000); // add/override

// Or replace entirely
$client->setContextWindowConfig(new ContextWindowConfig(['gpt-4o' => 200000]));
```

Unknown models return `null` — no guessing. Sizes drift as providers ship models, so override when needed.

## Estimated vs Actual

```php
// Pre-send: estimated from input messages
$usage = $client->getContextUsage($messages);
$usage->isEstimated(); // true

// Post-response: exact prompt tokens from the provider
$usage = $client->getContextUsage($messages, response: $lastResponse);
$usage->isEstimated(); // false
```

Estimation uses `TokenEstimator` (~4 chars/token, ~5-10% margin). Actual uses the provider's reported `promptTokens`.

## Running

```bash
php examples/29-context-usage/verify.php
```
