<?php

/**
 * Example 13: Metrics Collection Verification
 *
 * Verifies metrics events are emitted correctly using FakeHttpClient.
 *
 * Run: php examples/13-metrics/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Cache\CacheConfig;
use WebFiori\Ai\Cache\InMemoryCache;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

echo "=== Metrics Collection Verification ===\n\n";

$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));

$events = [];
$provider->setMetricsCallback(function (string $event, array $data) use (&$events)
{
    $events[] = compact('event', 'data');
});

$fakeHttp = new FakeHttpClient();
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello!']]],
    'model' => 'gpt-4o',
    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
])));
$provider->setHttpClient($fakeHttp);

// Test 1: Basic chat emits events
echo "1. Chat emits request.sent and request.completed\n";
$response = $provider->chat([new Message('user', 'Hi')]);

$eventNames = array_column($events, 'event');
assert(in_array('request.sent', $eventNames), 'request.sent missing');
assert(in_array('request.completed', $eventNames), 'request.completed missing');
echo "   ✅ request.sent, request.completed emitted\n";

// Test 2: Request ID is consistent and returned in response
echo "\n2. Request ID consistency\n";
$sentId = null;
$completedId = null;

foreach ($events as $e) {
    if ($e['event'] === 'request.sent') {
        $sentId = $e['data']['request_id'];
    }

    if ($e['event'] === 'request.completed') {
        $completedId = $e['data']['request_id'];
    }
}

assert($sentId === $completedId, 'Request IDs should match');
assert($response->getRequestId() === $sentId, 'Response request_id should match');
echo "   ✅ Request ID: {$sentId}\n";
echo "   ✅ Response->getRequestId() matches\n";

// Test 3: Custom request ID
echo "\n3. Custom request ID\n";
$events = [];
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]],
    'model' => 'gpt-4o',
])));

$customId = 'my-trace-id-12345';
$response = $provider->chat([new Message('user', 'Hey')], ['request_id' => $customId]);

assert($response->getRequestId() === $customId);
echo "   ✅ Custom ID used: {$customId}\n";

// Test 4: Cache hit/miss events
echo "\n4. Cache hit/miss events\n";
$cache = new InMemoryCache();
$provider->setCache($cache);
$provider->setCacheConfig(new CacheConfig(skipCacheAboveTemperature: null));

$events = [];
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Cached']]],
    'model' => 'gpt-4o',
])));

$messages = [new Message('user', 'Repeated question')];
$provider->chat($messages); // miss
$provider->chat($messages); // hit

$eventNames = array_column($events, 'event');
assert(in_array('cache.miss', $eventNames), 'cache.miss missing');
assert(in_array('cache.hit', $eventNames), 'cache.hit missing');
echo "   ✅ cache.miss emitted on first call\n";
echo "   ✅ cache.hit emitted on second call\n";

// Test 5: Error emits request.failed
echo "\n5. Error emits request.failed\n";
$events = [];
$fakeHttp->addResponse(new HttpResponse(401, [], json_encode([
    'error' => ['message' => 'Invalid API key'],
])));
$provider->setCache(null);

try {
    $provider->chat([new Message('user', 'Hi')]);
} catch (Throwable $e) {
    // Expected
}

$eventNames = array_column($events, 'event');
assert(in_array('request.failed', $eventNames), 'request.failed missing');
echo "   ✅ request.failed emitted on error\n";

// Test 6: Timestamp is in milliseconds
echo "\n6. Timestamp in milliseconds\n";
$events = [];
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]],
    'model' => 'gpt-4o',
])));
$provider->chat([new Message('user', 'Hi')]);

foreach ($events as $e) {
    assert($e['data']['timestamp'] > 1_000_000_000_000, 'Timestamp should be milliseconds');
}

echo "   ✅ All timestamps in milliseconds\n";

echo "\n=== All Verification Tests Passed ===\n";
echo "\nSample event data:\n";
$completedEvent = null;

foreach ($events as $e) {
    if ($e['event'] === 'request.completed') {
        $completedEvent = $e;

        break;
    }
}

if ($completedEvent) {
    echo json_encode($completedEvent, JSON_PRETTY_PRINT)."\n";
}
