<?php

/**
 * Example 10: Response Caching (Embeddings)
 *
 * Demonstrates caching embeddings — particularly valuable since embeddings
 * are always deterministic (same text = same vector).
 *
 * Run: php examples/10-caching/embeddings.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Cache\CacheConfig;
use WebFiori\Ai\Cache\InMemoryCache;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;


$apiKey = getenv('OPENAI_API_KEY');

if (!$apiKey) {
    echo "Please set OPENAI_API_KEY environment variable\n";
    exit(1);
}

$provider = new OpenAIClient([
    'api_key' => $apiKey,
    'model' => 'gpt-4o-mini',
    'embedding_model' => 'text-embedding-3-small',
]);

// Set up caching with longer TTL for embeddings
$cache = new InMemoryCache();
$provider->setCache($cache);
$provider->setCacheConfig(new CacheConfig(
    enabled: true,
    defaultTtl: 3600,       // 1 hour for chat
    embeddingTtl: 86400,    // 24 hours for embeddings (they're deterministic)
));

$texts = [
    'The quick brown fox jumps over the lazy dog.',
    'Machine learning is a subset of artificial intelligence.',
    'PHP is a popular server-side scripting language.',
];

echo "=== First Embedding Request (API Call) ===\n";
$start1 = microtime(true);
$response1 = $provider->embed($texts[0]);
$time1 = (microtime(true) - $start1) * 1000;

$vector = $response1->getVectors()[0];
echo "Text: \"{$texts[0]}\"\n";
echo "Vector dimensions: ".count($vector)."\n";
echo "First 5 values: ".implode(', ', array_map(fn($v) => number_format($v, 6), array_slice($vector, 0, 5)))."\n";
echo "Time: ".number_format($time1, 2)." ms\n";
echo "Cache entries: ".$cache->count()."\n";
echo "\n";

echo "=== Second Request for Same Text (Cache Hit) ===\n";
$start2 = microtime(true);
$response2 = $provider->embed($texts[0]);
$time2 = (microtime(true) - $start2) * 1000;

echo "Time: ".number_format($time2, 2)." ms\n";
echo "Vectors match: ".($response1->getVectors()[0] === $response2->getVectors()[0] ? 'Yes' : 'No')."\n";
echo "\n";

echo "=== Batch Embedding (Multiple Texts) ===\n";
$start3 = microtime(true);
$response3 = $provider->embed($texts);
$time3 = (microtime(true) - $start3) * 1000;

echo "Texts embedded: ".count($texts)."\n";
echo "Time: ".number_format($time3, 2)." ms\n";
echo "Cache entries: ".$cache->count()."\n";
echo "\n";

echo "=== Summary ===\n";
echo "First request (API): ".number_format($time1, 2)." ms\n";
echo "Second request (cached): ".number_format($time2, 2)." ms\n";
echo "Speedup: ".number_format($time1 / max($time2, 0.01), 1)."x faster\n";
echo "\nNote: Embeddings are always cached since they are deterministic.\n";
echo "The same text always produces the exact same vector.\n";
