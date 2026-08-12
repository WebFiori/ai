<?php

/**
 * Example 12: Health Check Verification
 *
 * Verifies health check behavior using FakeHttpClient.
 *
 * Note: Health checks use their own HTTP client internally to bypass
 * caching and retry logic. This verify script tests the HealthCheckResult
 * object and provider interface contract.
 *
 * Run: php examples/12-health-checks/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\ProviderInterface;

echo "=== Health Check Verification ===\n\n";

// Test 1: HealthCheckResult success
echo "1. HealthCheckResult::success()\n";
$result = HealthCheckResult::success(42, 'models_list');

assert($result->isAvailable() === true, 'Should be available');
assert($result->getLatencyMs() === 42, 'Latency should be 42');
assert($result->getCheckMethod() === 'models_list', 'Method should be models_list');
assert($result->getError() === null, 'Error should be null');

echo "   ✅ Available: true, Latency: 42ms, Method: models_list\n\n";

// Test 2: HealthCheckResult failure
echo "2. HealthCheckResult::failure()\n";
$result = HealthCheckResult::failure('Connection refused', 5000, 'minimal_completion');

assert($result->isAvailable() === false, 'Should not be available');
assert($result->getLatencyMs() === 5000, 'Latency should be 5000');
assert($result->getError() === 'Connection refused', 'Error should be set');
assert($result->getCheckMethod() === 'minimal_completion', 'Method should be minimal_completion');

echo "   ✅ Available: false, Error: Connection refused\n\n";

// Test 3: Checked at timestamp
echo "3. getCheckedAt() returns current time\n";
$before = new DateTimeImmutable();
$result = HealthCheckResult::success(10, 'test');
$after = new DateTimeImmutable();

assert($result->getCheckedAt()->getTimestamp() >= $before->getTimestamp());
assert($result->getCheckedAt()->getTimestamp() <= $after->getTimestamp());

echo "   ✅ Timestamp: ".$result->getCheckedAt()->format('Y-m-d H:i:s')."\n\n";

// Test 4: healthCheck is on ProviderInterface
echo "4. healthCheck() is part of ProviderInterface\n";
$reflection = new ReflectionClass(ProviderInterface::class);
assert($reflection->hasMethod('healthCheck'), 'healthCheck must be on interface');

$method = $reflection->getMethod('healthCheck');
$params = $method->getParameters();
assert(count($params) === 1, 'Should have 1 parameter');
assert($params[0]->getName() === 'timeout', 'Parameter should be timeout');
assert($params[0]->getDefaultValue() === 5, 'Default timeout should be 5');

echo "   ✅ Method exists with timeout parameter (default: 5s)\n\n";

// Test 5: OpenAI healthCheck never throws
echo "5. healthCheck() never throws\n";
$client = new OpenAIClient(['api_key' => 'invalid-key', 'model' => 'gpt-4o']);

try {
    $result = $client->healthCheck(2);
    assert($result instanceof HealthCheckResult, 'Must return HealthCheckResult');
    echo "   ✅ Returns HealthCheckResult even with invalid key\n";
    echo "   Available: ".($result->isAvailable() ? 'Yes' : 'No')."\n";
    echo "   Method: ".$result->getCheckMethod()."\n";

    if (!$result->isAvailable()) {
        echo "   Error: ".$result->getError()."\n";
    }
} catch (Throwable $e) {
    echo "   ❌ Should not throw: ".$e->getMessage()."\n";
    exit(1);
}

echo "\n=== All Verification Tests Passed ===\n";
