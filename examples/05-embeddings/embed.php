<?php

/**
 * Example 05: Text Embeddings (CLI)
 *
 * Run: php examples/05-embeddings/embed.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;

$provider = new OpenAIProvider([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

$texts = [
    'How do I reset my password?',
    'I forgot my login credentials',
    'What is the weather today?',
    'Account recovery steps',
];

echo 'Generating embeddings for '.count($texts).' texts...'.PHP_EOL.PHP_EOL;

$response = $provider->embed($texts, ['model' => 'text-embedding-3-small']);

echo 'Model: '.$response->getModel().PHP_EOL;
echo 'Dimensions: '.$response->getDimensions().PHP_EOL;
echo PHP_EOL.'Similarity matrix:'.PHP_EOL;
echo str_repeat('-', 60).PHP_EOL;

$vectors = $response->getVectors();

for ($i = 0; $i < count($texts); $i++) {
    for ($j = $i + 1; $j < count($texts); $j++) {
        $similarity = cosineSimilarity($vectors[$i], $vectors[$j]);
        printf("  %.3f  \"%s\" vs \"%s\"\n", $similarity, $texts[$i], $texts[$j]);
    }
}

/**
 * Compute cosine similarity between two vectors.
 */
function cosineSimilarity(array $a, array $b): float {
    $dotProduct = 0;
    $normA = 0;
    $normB = 0;

    for ($i = 0; $i < count($a); $i++) {
        $dotProduct += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }

    $denominator = sqrt($normA) * sqrt($normB);

    return $denominator > 0 ? $dotProduct / $denominator : 0.0;
}
