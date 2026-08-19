# Provider Fallback

Automatic failover across multiple AI providers for production resilience. If your primary provider fails (network error, rate limit, outage), requests automatically fall back to secondary providers.

## Features

- **Sequential fallback** — Try providers in order until one succeeds
- **Round-robin** — Distribute load across providers
- **Weighted routing** — Assign traffic percentages (e.g., 70% cheap, 30% premium)
- **Circuit breaker** — Skip failing providers temporarily to avoid hammering them
- **Metrics callback** — Observe which provider handled each request
- **Transparent interface** — Implements `ProviderInterface`, drop-in replacement

## Basic Usage

```php
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Fallback\FallbackProvider;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Google\GoogleClient;

// Create individual providers
$openai = new OpenAIClient(['api_key' => '...', 'model' => 'gpt-4o']);
$anthropic = new AnthropicClient(['api_key' => '...', 'model' => 'claude-sonnet-4-20250514']);
$google = new GoogleClient(['api_key' => '...', 'model' => 'gemini-2.5-flash']);

// Wrap them — tries OpenAI first, then Anthropic, then Google
$provider = new FallbackProvider([$openai, $anthropic, $google]);

// Use like any other provider
$response = $provider->chat([new Message('user', 'Hello!')]);
echo $response->getMessage()->getContent();

// Check which provider was used
echo "Handled by: " . $provider->getLastUsedProvider();
```

## Same Provider, Different Models

You can use the same provider multiple times with different models:

```php
$provider = new FallbackProvider([
    new GoogleClient(['api_key' => $key, 'model' => 'gemini-2.5-flash']),  // Fast
    new GoogleClient(['api_key' => $key, 'model' => 'gemini-2.5-pro']),    // Capable
    new GoogleClient(['api_key' => $key, 'model' => 'gemini-3.0-pro']),    // Latest
]);
```

## Routing Strategies

### Sequential (Default)
```php
use WebFiori\Ai\Provider\Fallback\FallbackConfig;
use WebFiori\Ai\Provider\Fallback\FallbackStrategy;

$config = new FallbackConfig(strategy: FallbackStrategy::SEQUENTIAL);
$provider = new FallbackProvider($providers, $config);
```

### Round-Robin (Load Balancing)
```php
$config = new FallbackConfig(strategy: FallbackStrategy::ROUND_ROBIN);
```

### Weighted (Cost Optimization)
```php
$config = new FallbackConfig(
    strategy: FallbackStrategy::WEIGHTED,
    weights: [
        0 => 70,  // First provider gets 70% of traffic
        1 => 30,  // Second provider gets 30%
    ],
);
```

## Circuit Breaker

Prevent hammering a failing provider:

```php
use WebFiori\Ai\Provider\Fallback\CircuitBreakerConfig;

$config = new FallbackConfig(
    circuitBreaker: new CircuitBreakerConfig(
        failureThreshold: 5,   // Open circuit after 5 consecutive failures
        cooldownSeconds: 60,   // Wait 60s before trying again
        successThreshold: 2,   // Need 2 successes to fully close circuit
    ),
);
```

Circuit states:
- **CLOSED** — Normal operation, requests pass through
- **OPEN** — Provider marked unavailable, requests skip it
- **HALF_OPEN** — Testing recovery, limited requests allowed

## Custom Failover Conditions

By default, failover triggers on `ProviderException`, `HttpException`, and `RateLimitException`. Customize this:

```php
use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\RateLimitException;

$config = new FallbackConfig(
    failoverOn: [
        HttpException::class,      // Network errors
        RateLimitException::class, // 429 responses
        // NOT AuthenticationException — fail fast on bad credentials
    ],
);
```

## Metrics and Observability

```php
$config = new FallbackConfig();
$config->setMetricsCallback(function (
    string $providerName,
    bool $success,
    int $latencyMs,
    ?string $error
) {
    // Send to your monitoring system (DataDog, Prometheus, etc.)
    $this->metrics->increment("ai.provider.$providerName." . ($success ? 'success' : 'failure'));
    $this->metrics->histogram("ai.provider.$providerName.latency", $latencyMs);
});
```

## Health Checks

```php
$result = $provider->healthCheck();

if ($result->isAvailable()) {
    echo "At least one provider is healthy";
} else {
    echo "All providers down: " . $result->getError();
}
```

## Run the Example

```bash
export OPENAI_API_KEY="sk-..."
export ANTHROPIC_API_KEY="sk-ant-..."
export GOOGLE_API_KEY="AIza..."

php fallback_provider.php
```

## Files

- `fallback_provider.php` — Comprehensive examples of all features
