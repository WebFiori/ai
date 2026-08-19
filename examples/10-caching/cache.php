<?php

/**
 * Example 10: Response Caching (Chat)
 *
 * Demonstrates caching chat responses to reduce API costs and latency.
 *
 * Run: php examples/10-caching/cache.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Cache\CacheConfig;
use WebFiori\Ai\Cache\InMemoryCache;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;


$apiKey = getenv('OPENAI_API_KEY');

if (!$apiKey) {
    echo "Please set OPENAI_API_KEY environment variable\n";
    exit(1);
}

$provider = new OpenAIClient([
    'api_key' => $apiKey,
    'model' => 'gpt-4o-mini',
]);

// Set up caching
$cache = new InMemoryCache();
$provider->setCache($cache);
$provider->setCacheConfig(new CacheConfig(
    enabled: true,
    defaultTtl: 3600,                    // Cache for 1 hour
    skipCacheAboveTemperature: null,     // Cache all temperatures for demo
));

$messages = [
    new Message('system', 'You are a helpful assistant. Keep responses brief.'),
    new Message('user', 'What is the capital of France?'),
];

echo "=== First Request (API Call) ===\n";
$start1 = microtime(true);
$response1 = $provider->chat($messages, ['temperature' => 0]);
$time1 = (microtime(true) - $start1) * 1000;

echo "Response: ".$response1->getMessage()->getContent()."\n";
echo "Model: ".$response1->getModel()."\n";
echo "Time: ".number_format($time1, 2)." ms\n";
echo "Cache entries: ".$cache->count()."\n";
echo "\n";

echo "=== Second Request (Cache Hit) ===\n";
$start2 = microtime(true);
$response2 = $provider->chat($messages, ['temperature' => 0]);
$time2 = (microtime(true) - $start2) * 1000;

echo "Response: ".$response2->getMessage()->getContent()."\n";
echo "Time: ".number_format($time2, 2)." ms\n";
echo "Cache entries: ".$cache->count()."\n";
echo "\n";

echo "=== Different Question (Cache Miss) ===\n";
$messages2 = [
    new Message('system', 'You are a helpful assistant. Keep responses brief.'),
    new Message('user', 'What is the capital of Germany?'),
];

$start3 = microtime(true);
$response3 = $provider->chat($messages2, ['temperature' => 0]);
$time3 = (microtime(true) - $start3) * 1000;

echo "Response: ".$response3->getMessage()->getContent()."\n";
echo "Time: ".number_format($time3, 2)." ms\n";
echo "Cache entries: ".$cache->count()."\n";
echo "\n";

echo "=== Summary ===\n";
echo "First request (API): ".number_format($time1, 2)." ms\n";
echo "Second request (cached): ".number_format($time2, 2)." ms\n";
echo "Speedup: ".number_format($time1 / max($time2, 0.01), 1)."x faster\n";
