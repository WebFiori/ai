<?php

/**
 * Verify caching example works correctly using FakeHttpClient.
 *
 * Run: php examples/10-caching/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Cache\CacheConfig;
use WebFiori\Ai\Cache\InMemoryCache;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

echo "=== Caching Verification Test ===\n\n";

$provider = new OpenAIClient([
    'api_key' => 'test-key',
    'model' => 'gpt-4o-mini',
]);

// Set up fake HTTP client - only queue ONE response
$fakeHttp = new FakeHttpClient();
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'The capital of France is Paris.']]],
    'model' => 'gpt-4o-mini',
    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
])));
$provider->setHttpClient($fakeHttp);

// Set up caching
$cache = new InMemoryCache();
$provider->setCache($cache);
$provider->setCacheConfig(new CacheConfig(
    enabled: true,
    defaultTtl: 3600,
    skipCacheAboveTemperature: null,
));

$messages = [
    new Message('system', 'You are a helpful assistant.'),
    new Message('user', 'What is the capital of France?'),
];

// First request - should hit fake API
echo "1. First request (should use fake API)...\n";
$response1 = $provider->chat($messages);
echo "   Response: ".$response1->getMessage()->getContent()."\n";
echo "   Cache entries: ".$cache->count()."\n";

// Second request - should hit cache (no more HTTP responses queued!)
echo "\n2. Second request (should hit cache)...\n";
try {
    $response2 = $provider->chat($messages);
    echo "   Response: ".$response2->getMessage()->getContent()."\n";
    echo "   Cache entries: ".$cache->count()."\n";

    if ($response1->getMessage()->getContent() === $response2->getMessage()->getContent()) {
        echo "\n✅ SUCCESS: Second request returned cached response!\n";
        echo "   (If cache wasn't working, this would have thrown an exception\n";
        echo "    because no more HTTP responses were queued)\n";
    }
} catch (Exception $e) {
    echo "\n❌ FAILED: ".$e->getMessage()."\n";
    echo "   Cache did not work - tried to make HTTP request but no response queued.\n";
    exit(1);
}

// Verify different messages cause cache miss
echo "\n3. Different question (should be cache miss)...\n";
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'The capital of Germany is Berlin.']]],
    'model' => 'gpt-4o-mini',
])));

$messages2 = [
    new Message('system', 'You are a helpful assistant.'),
    new Message('user', 'What is the capital of Germany?'),
];

$response3 = $provider->chat($messages2);
echo "   Response: ".$response3->getMessage()->getContent()."\n";
echo "   Cache entries: ".$cache->count()."\n";

if ($cache->count() === 2) {
    echo "\n✅ SUCCESS: Different question created new cache entry!\n";
} else {
    echo "\n❌ FAILED: Expected 2 cache entries, got ".$cache->count()."\n";
    exit(1);
}

echo "\n=== All Verification Tests Passed ===\n";
