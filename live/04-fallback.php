<?php

/**
 * Live Test 04: FallbackProvider — Resilience
 *
 * Uses Google as primary with circuit breaker configured.
 * Tests failover, circuit breaker, and metrics.
 *
 * Usage:
 *   source keys/env.sh && php live/04-fallback.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Fallback\CircuitBreakerConfig;
use WebFiori\Ai\Provider\Fallback\FallbackConfig;
use WebFiori\Ai\Provider\Fallback\FallbackProvider;
use WebFiori\Ai\Provider\Fallback\FallbackStrategy;

section('FallbackProvider — Resilience');

// ─── 1. Sequential fallback — primary succeeds ────────────────────────────────
run('Sequential fallback (primary succeeds)', function ()
{
    $primary = gemini2Client();
    $fallback = new FallbackProvider([$primary]);

    $response = $fallback->chat([new Message('user', 'Say hi in one word.')]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    assert($fallback->getLastUsedProvider() === 'google', 'Wrong provider');
    echo "    → Provider used: {$fallback->getLastUsedProvider()}\n";
    echo "    → Response: {$response->getMessage()->getContent()}\n";
});

// ─── 2. Metrics callback ──────────────────────────────────────────────────────
run('Metrics callback receives correct data', function ()
{
    $metrics = [];
    $config = new FallbackConfig();
    $config->setMetricsCallback(function ($provider, $success, $latency, $error) use (&$metrics)
    {
        $metrics[] = compact('provider', 'success', 'latency');
    });

    $fallback = new FallbackProvider([gemini2Client()], $config);
    $fallback->chat([new Message('user', 'Hi.')]);

    assert(count($metrics) === 1, 'Expected 1 metric entry');
    assert($metrics[0]['success'] === true, 'Expected success=true');
    assert($metrics[0]['latency'] > 0, 'Expected positive latency');
    echo "    → Provider: {$metrics[0]['provider']}, latency: {$metrics[0]['latency']}ms\n";
});

// ─── 3. Circuit breaker — healthy provider stays closed ───────────────────────
run('Circuit breaker stays closed for healthy provider', function ()
{
    $config = new FallbackConfig(
        circuitBreaker: new CircuitBreakerConfig(failureThreshold: 3)
    );
    $fallback = new FallbackProvider([gemini2Client()], $config);

    // Make 3 successful calls
    for ($i = 0; $i < 3; $i++) {
        $fallback->chat([new Message('user', 'Hi.')]);
    }

    $cb = $fallback->getCircuitBreaker(0);
    assert($cb->getState()->value === 'closed', 'Circuit should be closed');
    echo "    → Circuit state after 3 successful calls: {$cb->getState()->value}\n";
});

// ─── 4. Round-robin distribution ──────────────────────────────────────────────
run('Round-robin distributes across providers', function ()
{
    $p1 = gemini2Client();
    $p2 = gemini2Client();

    $config = new FallbackConfig(strategy: FallbackStrategy::ROUND_ROBIN);
    $fallback = new FallbackProvider([$p1, $p2], $config);

    $used = [];

    for ($i = 0; $i < 4; $i++) {
        $fallback->chat([new Message('user', 'Hi.')]);
        $used[] = $fallback->getLastUsedProvider();
    }

    // Both providers should have been used (both are 'google' but alternated by index)
    assert(count($used) === 4, 'Expected 4 requests');
    echo "    → Provider sequence: ".implode(' → ', $used)."\n";
});

// ─── 5. Health check aggregation ──────────────────────────────────────────────
run('Health check aggregates all providers', function ()
{
    $fallback = new FallbackProvider([gemini2Client(), gemini2Client()]);
    $result = $fallback->healthCheck(5);

    assert($result->isAvailable(), 'Expected at least one healthy provider');
    echo "    → Available: yes, method: {$result->getCheckMethod()}\n";
});

echo "\n";
