<?php

declare(strict_types=1);

/**
 * Example 27: RAG Providers — LocalRagProvider
 *
 * Run: php examples/27-rag-providers/local.php
 *
 * Demonstrates:
 * 1. Creating a SqliteVectorStore for persistent storage
 * 2. Using LocalRagProvider with a GoogleClient embedder
 * 3. Ingesting, retrieving, and deleting documents
 * 4. Wrapping the provider in a RetrievalTool for chat models
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Embedding\SqliteVectorStore;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Rag\LocalRagProvider;
use WebFiori\Ai\Rag\RetrievalTool;

// ─── Setup the embedding provider ────────────────────────────────────────────

$embedder = new GoogleClient(new GoogleClientConfig(
    apiKey: 'your-google-api-key',
    model: 'text-embedding-004',
));

// ─── Option A: SQLite vector store (persistent) ──────────────────────────────

$sqliteStore = new SqliteVectorStore(__DIR__.'/knowledge.db');

// ─── Option B: In-memory vector store (for testing) ──────────────────────────

$memoryStore = new InMemoryVectorStore();

// ─── Create the LocalRagProvider ─────────────────────────────────────────────
// Using SQLite for persistence. Switch to $memoryStore for ephemeral usage.

$rag = new LocalRagProvider(
    store: $sqliteStore,
    embedder: $embedder,
    minScore: 0.7,
    embeddingModel: 'text-embedding-004', // explicit model override (optional)
);

// ─── Ingest some facts ───────────────────────────────────────────────────────

echo "═══ LocalRagProvider Example ═══\n\n";
echo "Ingesting documents...\n";

$id1 = $rag->ingest(
    'PHP 8.4 introduced property hooks, allowing getter and setter logic to be defined directly in property declarations.',
    ['source' => 'php-docs', 'version' => '8.4'],
);
echo "  Ingested doc 1: {$id1}\n";

$id2 = $rag->ingest(
    'PHP 8.1 added enums, fibers for cooperative multitasking, and readonly properties.',
    ['source' => 'php-docs', 'version' => '8.1'],
);
echo "  Ingested doc 2: {$id2}\n";

$id3 = $rag->ingest(
    'Composer is the dependency manager for PHP. It manages project dependencies declared in composer.json.',
    ['source' => 'composer-docs'],
);
echo "  Ingested doc 3: {$id3}\n";

$id4 = $rag->ingest(
    'PHPUnit is the standard testing framework for PHP. Tests extend TestCase and use assertions.',
    ['source' => 'phpunit-docs'],
);
echo "  Ingested doc 4: {$id4}\n";

// ─── Retrieve by query ───────────────────────────────────────────────────────

echo "\nRetrieving documents for: 'What are property hooks in PHP?'\n\n";

$results = $rag->retrieve('What are property hooks in PHP?', topK: 3);

foreach ($results as $i => $result) {
    echo sprintf(
        "  [%d] (score: %.4f) %s\n",
        $i + 1,
        $result->getScore(),
        substr($result->getText(), 0, 80).'...',
    );
    echo "      Source: ".($result->getSource() ?? 'unknown')."\n";
}

// ─── Delete a document ───────────────────────────────────────────────────────

echo "\nDeleting document: {$id3}\n";
$rag->delete($id3);
echo "  Deleted.\n";

// Verify it's gone
echo "\nRetrieving after deletion for: 'How does Composer work?'\n";
$results = $rag->retrieve('How does Composer work?', topK: 3);

if (count($results) === 0) {
    echo "  No results found (document was deleted).\n";
} else {
    foreach ($results as $result) {
        echo "  Found: ".substr($result->getText(), 0, 60)."...\n";
    }
}

// ─── Use with RetrievalTool ──────────────────────────────────────────────────

echo "\n─── Using with RetrievalTool ───\n\n";

$tool = new RetrievalTool(
    provider: $rag,
    name: 'search_php_docs',
    description: 'Search PHP documentation for language features, version changes, and best practices.',
    defaultTopK: 3,
);

// The tool can be passed to a chat provider for autonomous retrieval
$chatClient = new GoogleClient(new GoogleClientConfig(
    apiKey: 'your-google-api-key',
    model: 'gemini-2.5-flash',
));

$messages = [
    new Message('system', 'You are a PHP expert. Use the search_php_docs tool to find relevant documentation before answering.'),
    new Message('user', 'What new features were added in PHP 8.1?'),
];

$response = $chatClient->chat($messages, [
    ChatOption::TOOLS => [$tool],
    ChatOption::AUTO_EXECUTE_TOOLS => true,
]);

echo "Question: What new features were added in PHP 8.1?\n";
echo "Response: ".$response->getMessage()->getContent()."\n";
