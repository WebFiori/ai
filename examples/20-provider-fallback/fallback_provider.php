<?php

/**
 * Example: Using FallbackProvider for resilient AI operations
 *
 * This example demonstrates how to configure multiple providers with
 * automatic failover for production-ready AI applications.
 */

require_once __DIR__.'/../vendor/autoload.php';

use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Fallback\CircuitBreakerConfig;
use WebFiori\Ai\Provider\Fallback\FallbackConfig;
use WebFiori\Ai\Provider\Fallback\FallbackProvider;
use WebFiori\Ai\Provider\Fallback\FallbackStrategy;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

// =============================================================================
// Example 1: Basic Sequential Fallback
// =============================================================================
echo "=== Example 1: Basic Sequential Fallback ===\n\n";

// Create individual providers (replace with your API keys)
$openai = new OpenAIClient([
    'api_key' => getenv('OPENAI_API_KEY') ?: 'sk-...',
    'model' => 'gpt-4o',
]);

$anthropic = new AnthropicClient([
    'api_key' => getenv('ANTHROPIC_API_KEY') ?: 'sk-ant-...',
    'model' => 'claude-sonnet-4-20250514',
]);

$google = new GoogleClient([
    'api_key' => getenv('GOOGLE_API_KEY') ?: 'AIza...',
    'model' => 'gemini-2.5-flash',
]);

// Wrap them in a FallbackProvider - tries OpenAI first, then Anthropic, then Google
$provider = new FallbackProvider(
    providers: [$openai, $anthropic, $google],
    config: new FallbackConfig(
        strategy: FallbackStrategy::SEQUENTIAL,
        maxAttempts: 3,
    )
);

// Use it like any other provider
try {
    $response = $provider->chat([
        new Message('user', 'What is the capital of France?'),
    ]);

    echo "Response: ".$response->getMessage()->getContent()."\n";
    echo "Provider used: ".$provider->getLastUsedProvider()."\n\n";
} catch (ProviderException $e) {
    echo "All providers failed: ".$e->getMessage()."\n\n";
}

// =============================================================================
// Example 2: Round-Robin Load Balancing
// =============================================================================
echo "=== Example 2: Round-Robin Load Balancing ===\n\n";

$loadBalancedProvider = new FallbackProvider(
    providers: [$openai, $anthropic, $google],
    config: new FallbackConfig(
        strategy: FallbackStrategy::ROUND_ROBIN,
    )
);

// Each call rotates to the next provider
for ($i = 1; $i <= 4; $i++) {
    try {
        $loadBalancedProvider->chat([new Message('user', 'Hi')]);
        echo "Request $i handled by: ".$loadBalancedProvider->getLastUsedProvider()."\n";
    } catch (ProviderException $e) {
        echo "Request $i failed\n";
    }
}
echo "\n";

// =============================================================================
// Example 3: Weighted Distribution (Cost Optimization)
// =============================================================================
echo "=== Example 3: Weighted Distribution ===\n\n";

// Give cheaper/faster model 70% of traffic, expensive model 30%
$weightedProvider = new FallbackProvider(
    providers: [$openai, $anthropic],
    config: new FallbackConfig(
        strategy: FallbackStrategy::WEIGHTED,
        weights: [
            0 => 70, // OpenAI gets 70% of requests
            1 => 30, // Anthropic gets 30% of requests
        ],
    )
);

$counts = ['openai' => 0, 'anthropic' => 0];

for ($i = 0; $i < 100; $i++) {
    try {
        $weightedProvider->chat([new Message('user', 'Hi')]);
        $counts[$weightedProvider->getLastUsedProvider()]++;
    } catch (ProviderException $e) {
        // Ignore failures for this example
    }
}

echo "Distribution over 100 requests:\n";

foreach ($counts as $name => $count) {
    echo "  $name: $count%\n";
}
echo "\n";

// =============================================================================
// Example 4: Circuit Breaker Pattern
// =============================================================================
echo "=== Example 4: Circuit Breaker Pattern ===\n\n";

$resilientProvider = new FallbackProvider(
    providers: [$openai, $anthropic, $google],
    config: new FallbackConfig(
        strategy: FallbackStrategy::SEQUENTIAL,
        circuitBreaker: new CircuitBreakerConfig(
            failureThreshold: 3,   // Open circuit after 3 consecutive failures
            cooldownSeconds: 60,   // Try again after 60 seconds
            successThreshold: 2,   // Need 2 successes to fully close circuit
        ),
    )
);

// If OpenAI fails 3 times, it will be skipped for 60 seconds
// This prevents hammering a failing provider

echo "Circuit breaker configured:\n";
echo "  - Opens after 3 failures\n";
echo "  - Cooldown: 60 seconds\n";
echo "  - Closes after 2 successes\n\n";

// Check circuit states
foreach ($resilientProvider->getCircuitBreakers() as $index => $cb) {
    $providerName = $resilientProvider->getProvider($index)->getName();
    echo "  $providerName circuit: ".$cb->getState()->value."\n";
}
echo "\n";

// =============================================================================
// Example 5: Metrics and Observability
// =============================================================================
echo "=== Example 5: Metrics and Observability ===\n\n";

$config = new FallbackConfig(strategy: FallbackStrategy::SEQUENTIAL);

// Add metrics callback for monitoring
$config->setMetricsCallback(function (
    string $providerName,
    bool $success,
    int $latencyMs,
    ?string $error
) {
    $status = $success ? 'SUCCESS' : 'FAILED';
    echo "[METRIC] Provider: $providerName | Status: $status | Latency: {$latencyMs}ms";

    if ($error) {
        echo " | Error: $error";
    }
    echo "\n";
});

$observableProvider = new FallbackProvider([$openai, $anthropic], $config);

try {
    $observableProvider->chat([new Message('user', 'Hello')]);
} catch (ProviderException $e) {
    echo "Request failed: ".$e->getMessage()."\n";
}
echo "\n";

// =============================================================================
// Example 6: Custom Failover Conditions
// =============================================================================
echo "=== Example 6: Custom Failover Conditions ===\n\n";

// Only failover on specific exceptions
$selectiveProvider = new FallbackProvider(
    providers: [$openai, $anthropic],
    config: new FallbackConfig(
        strategy: FallbackStrategy::SEQUENTIAL,
        // Only failover on network errors and rate limits, not auth errors
        failoverOn: [
            HttpException::class,
            RateLimitException::class,
        ],
    )
);

echo "Failover configured for:\n";
echo "  - HttpException (network errors)\n";
echo "  - RateLimitException (429 responses)\n";
echo "  - NOT AuthenticationException (would throw immediately)\n\n";

// =============================================================================
// Example 7: Health Checks
// =============================================================================
echo "=== Example 7: Health Checks ===\n\n";

$healthResult = $provider->healthCheck(timeout: 5);

echo "Fallback provider health:\n";
echo "  Available: ".($healthResult->isAvailable() ? 'Yes' : 'No')."\n";
echo "  Latency: ".$healthResult->getLatencyMs()."ms\n";

if ($healthResult->getError()) {
    echo "  Error: ".$healthResult->getError()."\n";
}
echo "\n";

echo "=== Examples Complete ===\n";
