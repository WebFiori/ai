<?php

/**
 * Example 13: Metrics with real Google API
 *
 * Run: php examples/13-metrics/real.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
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

// Set up metrics callback
$events = [];
$provider->setMetricsCallback(function (string $event, array $data) use (&$events)
{
    $events[] = compact('event', 'data');
    echo "[METRIC] {$event} | request_id={$data['request_id']} | provider={$data['provider']}";

    if (isset($data['latency_ms'])) {
        echo " | latency={$data['latency_ms']}ms";
    }

    if (isset($data['total_tokens'])) {
        echo " | tokens={$data['total_tokens']}";
    }

    if (isset($data['error_message'])) {
        echo " | error={$data['error_message']}";
    }

    echo "\n";
});

echo "=== Metrics Collection — Real Google API ===\n\n";

// Chat request
echo "--- chat() ---\n";
$response = $provider->chat([
    new Message('user', 'What is 1+1? Reply with just the number.'),
]);

echo "Response: ".trim($response->getMessage()->getContent())."\n";
echo "Request ID: ".$response->getRequestId()."\n\n";

// Embedding request
echo "--- embed() ---\n";
try {
    $embedding = $provider->embed('Hello world', ['model' => 'text-embedding-004']);
    echo "Vector dimensions: ".$embedding->getDimensions()."\n";
    echo "Request ID: ".$embedding->getRequestId()."\n";
} catch (Throwable $e) {
    echo "Note: Embedding not available with this credentials context\n";
    echo "Error: ".$e->getMessage()."\n";
}
echo "\n";

// Health check
echo "--- healthCheck() ---\n";
$health = $provider->healthCheck();
echo "Available: ".($health->isAvailable() ? 'Yes' : 'No')."\n";
echo "Latency: ".$health->getLatencyMs()."ms\n\n";

// Summary
echo "=== Summary ===\n";
echo "Total events emitted: ".count($events)."\n";

$eventCounts = array_count_values(array_column($events, 'event'));

foreach ($eventCounts as $event => $count) {
    echo "  {$event}: {$count}\n";
}
