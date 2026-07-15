<?php

/**
 * Example 05: Vector Storage — Semantic Search (CLI)
 *
 * Run: php examples/05-embeddings/search.php
 *
 * Demonstrates:
 * 1. Generating embeddings for a set of documents
 * 2. Storing them in InMemoryVectorStore with metadata
 * 3. Querying by semantic similarity
 * 4. Filtering results by metadata
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

$provider = new OpenAIClient([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

// Sample documents to index
$documents = [
    ['id' => 'doc-1', 'text' => 'PHP is a server-side scripting language for web development.', 'topic' => 'php'],
    ['id' => 'doc-2', 'text' => 'Python is popular for data science and machine learning.', 'topic' => 'python'],
    ['id' => 'doc-3', 'text' => 'Laravel is a PHP framework that follows the MVC pattern.', 'topic' => 'php'],
    ['id' => 'doc-4', 'text' => 'Django is a Python web framework with batteries included.', 'topic' => 'python'],
    ['id' => 'doc-5', 'text' => 'Composer is the dependency manager for PHP projects.', 'topic' => 'php'],
    ['id' => 'doc-6', 'text' => 'JavaScript runs in the browser and on the server with Node.js.', 'topic' => 'javascript'],
    ['id' => 'doc-7', 'text' => 'React is a JavaScript library for building user interfaces.', 'topic' => 'javascript'],
    ['id' => 'doc-8', 'text' => 'Vector databases store embeddings for fast similarity search.', 'topic' => 'ai'],
];

// Step 1: Generate embeddings for all documents
echo 'Generating embeddings for '.count($documents).' documents...'.PHP_EOL;

$texts = array_column($documents, 'text');
$response = $provider->embed($texts, ['model' => 'text-embedding-3-small']);
$vectors = $response->getVectors();

echo 'Done. Dimensions: '.$response->getDimensions().PHP_EOL.PHP_EOL;

// Step 2: Store in vector store with metadata
$store = new InMemoryVectorStore();

foreach ($documents as $i => $doc) {
    $store->store($doc['id'], $vectors[$i], [
        'text' => $doc['text'],
        'topic' => $doc['topic'],
    ]);
}

echo 'Stored '.$store->count().' vectors.'.PHP_EOL.PHP_EOL;

// Step 3: Query — find documents similar to a question
$query = 'How do I manage packages in PHP?';
echo '═══ Query: "'.$query.'" ═══'.PHP_EOL.PHP_EOL;

$queryVector = $provider->embed($query, ['model' => 'text-embedding-3-small'])->getVector();
$results = $store->query($queryVector, 3);

echo 'Top 3 results:'.PHP_EOL;

foreach ($results as $i => $record) {
    printf(
        "  %d. [%.3f] %s\n",
        $i + 1,
        $record->getScore(),
        $record->getMetadata()['text']
    );
}

// Step 4: Query with metadata filter — only PHP documents
echo PHP_EOL.'═══ Query with filter (topic=php): "'.$query.'" ═══'.PHP_EOL.PHP_EOL;

$filtered = $store->query($queryVector, 3, ['topic' => 'php']);

echo 'Top 3 PHP results:'.PHP_EOL;

foreach ($filtered as $i => $record) {
    printf(
        "  %d. [%.3f] %s\n",
        $i + 1,
        $record->getScore(),
        $record->getMetadata()['text']
    );
}

// Step 5: Another query
$query2 = 'What frontend frameworks can I use?';
echo PHP_EOL.'═══ Query: "'.$query2.'" ═══'.PHP_EOL.PHP_EOL;

$queryVector2 = $provider->embed($query2, ['model' => 'text-embedding-3-small'])->getVector();
$results2 = $store->query($queryVector2, 3);

echo 'Top 3 results:'.PHP_EOL;

foreach ($results2 as $i => $record) {
    printf(
        "  %d. [%.3f] %s\n",
        $i + 1,
        $record->getScore(),
        $record->getMetadata()['text']
    );
}
