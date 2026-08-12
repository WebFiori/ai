<?php

/**
 * Example 12: Health Checks
 *
 * Demonstrates provider health checking to verify availability.
 *
 * Run: php examples/12-health-checks/health.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Provider\Google\GoogleClient;

$credentials = __DIR__.'/../../vertex-ai-key.json';

if (!file_exists($credentials)) {
    echo "Please provide vertex-ai-key.json in the project root\n";
    exit(1);
}

$provider = new GoogleClient([
    'api' => 'gemini',
    'credentials' => $credentials,
    'model' => 'gemini-3.5-flash',
]);

echo "=== Provider Health Check ===\n\n";
echo "Provider: Google Gemini\n";
echo "Checking availability...\n\n";

$result = $provider->healthCheck(timeout: 10);

echo "Available: ".($result->isAvailable() ? '✅ Yes' : '❌ No')."\n";
echo "Latency:   ".$result->getLatencyMs()." ms\n";
echo "Method:    ".$result->getCheckMethod()."\n";
echo "Checked:   ".$result->getCheckedAt()->format('Y-m-d H:i:s')."\n";

if (!$result->isAvailable()) {
    echo "Error:     ".$result->getError()."\n";
}
