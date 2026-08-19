<?php

/**
 * RAG Verification Script
 *
 * Tests all RAG functionality using FakeHttpClient - no API keys required.
 * Run with: php verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Embedding\FileVectorStore;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Embedding\SqliteVectorStore;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Rag\ChunkResult;
use WebFiori\Ai\Rag\RetrievalResult;
use WebFiori\Ai\Rag\RetrievalTool;
use WebFiori\Ai\Rag\Retriever;
use WebFiori\Ai\Rag\RetrieverInterface;
use WebFiori\Ai\Rag\TextChunker;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void {
    global $passed, $failed;

    try {
        $fn();
        echo "✅ {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "❌ {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assert_true(bool $condition, string $message = ''): void {
    if (!$condition) {
        throw new Exception($message ?: 'Assertion failed');
    }
}

function assert_equals($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new Exception($message ?: "Expected ".var_export($expected, true).", got ".var_export($actual, true));
    }
}

echo "=== RAG Verification ===\n\n";

// --- TextChunker Tests ---
echo "TextChunker:\n";

test('chunks text into pieces', function ()
{
    $chunker = new TextChunker(chunkSize: 50, overlap: 10);
    $text = 'First sentence here. Second sentence follows. Third one now. Fourth comes along.';

    $chunks = $chunker->chunk($text, ['source' => 'test.txt']);

    assert_true(count($chunks) > 1, 'Should create multiple chunks');
    assert_true($chunks[0] instanceof ChunkResult, 'Should return ChunkResult instances');
    assert_equals('test.txt', $chunks[0]->getMetadata()['source']);
});

test('preserves text content across chunks', function ()
{
    $chunker = new TextChunker(chunkSize: 100, overlap: 20);
    $text = 'The quick brown fox jumps over the lazy dog. This is a test sentence for chunking.';

    $chunks = $chunker->chunk($text);

    // All words should appear in at least one chunk
    foreach (['quick', 'brown', 'fox', 'lazy', 'dog', 'chunking'] as $word) {
        $found = false;

        foreach ($chunks as $chunk) {
            if (str_contains($chunk->getText(), $word)) {
                $found = true;

                break;
            }
        }

        assert_true($found, "Word '$word' should be in at least one chunk");
    }
});

test('handles empty text', function ()
{
    $chunker = new TextChunker();

    $chunks = $chunker->chunk('');

    assert_equals([], $chunks);
});

test('handles Unicode text', function ()
{
    $chunker = new TextChunker(chunkSize: 50, overlap: 10);
    $text = 'مرحبا بالعالم. هذا نص عربي.';

    $chunks = $chunker->chunk($text);

    assert_true(count($chunks) >= 1);
    assert_true(str_contains($chunks[0]->getText(), 'مرحبا'));
});

// --- FileVectorStore Tests ---
echo "\nFileVectorStore:\n";

$tempDir = sys_get_temp_dir().'/rag-verify-'.uniqid();

test('stores and retrieves vectors', function () use ($tempDir)
{
    $store = new FileVectorStore($tempDir.'/file-store');

    $store->store('chunk-1', [1.0, 0.0, 0.0], ['text' => 'About water quality.', 'source' => 'doc.pdf']);
    $store->store('chunk-2', [0.0, 1.0, 0.0], ['text' => 'About energy usage.', 'source' => 'doc.pdf']);

    assert_equals(2, $store->count());

    $record = $store->get('chunk-1');
    assert_equals('About water quality.', $record->getMetadata()['text']);
});

test('queries by similarity', function () use ($tempDir)
{
    $store = new FileVectorStore($tempDir.'/file-store');

    // Query for water (similar to chunk-1)
    $results = $store->query([0.9, 0.1, 0.0], topK: 1);

    assert_equals(1, count($results));
    assert_equals('chunk-1', $results[0]->getId());
});

test('filters by metadata', function () use ($tempDir)
{
    $store = new FileVectorStore($tempDir.'/file-store-filter');

    $store->store('a', [1.0, 0.0], ['text' => 'A', 'category' => 'water']);
    $store->store('b', [0.9, 0.1], ['text' => 'B', 'category' => 'energy']);

    $results = $store->query([1.0, 0.0], filter: ['category' => 'water']);

    assert_equals(1, count($results));
    assert_equals('a', $results[0]->getId());
});

// --- SqliteVectorStore Tests ---
echo "\nSqliteVectorStore:\n";

test('stores and retrieves vectors', function () use ($tempDir)
{
    $store = new SqliteVectorStore($tempDir.'/sqlite-store.db');

    $store->store('chunk-1', [1.0, 0.0, 0.0], ['text' => 'SQLite water info.']);
    $store->store('chunk-2', [0.0, 1.0, 0.0], ['text' => 'SQLite energy info.']);

    assert_equals(2, $store->count());

    $record = $store->get('chunk-1');
    assert_equals('SQLite water info.', $record->getMetadata()['text']);
});

test('queries with multi-field filter', function () use ($tempDir)
{
    $store = new SqliteVectorStore($tempDir.'/sqlite-filter.db');

    $store->store('a', [1.0, 0.0], ['text' => 'A', 'category' => 'water', 'year' => 2024]);
    $store->store('b', [0.9, 0.1], ['text' => 'B', 'category' => 'water', 'year' => 2023]);

    $results = $store->query([1.0, 0.0], filter: ['category' => 'water', 'year' => 2024]);

    assert_equals(1, count($results));
    assert_equals('a', $results[0]->getId());
});

// --- Retriever Tests ---
echo "\nRetriever:\n";

test('retrieves relevant chunks', function ()
{
    // Setup store with known vectors
    $store = new InMemoryVectorStore();
    $store->store('water-1', [1.0, 0.0, 0.0], ['text' => 'Water quality standards.']);
    $store->store('water-2', [0.95, 0.05, 0.0], ['text' => 'Water testing procedures.']);
    $store->store('energy-1', [0.0, 1.0, 0.0], ['text' => 'Energy consumption data.']);

    // Mock provider that returns water-like vector
    $fakeHttp = new FakeHttpClient();
    $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
        'object' => 'list',
        'data' => [['embedding' => [1.0, 0.0, 0.0]]],
        'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
    ])));

    $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'text-embedding-3-small'));
    $provider->setHttpClient($fakeHttp);

    $retriever = new Retriever($provider, $store);
    $results = $retriever->retrieve('water quality', topK: 2);

    assert_equals(2, count($results));
    assert_true(str_contains($results[0]->getText(), 'Water'));
});

test('respects minimum score threshold', function ()
{
    $store = new InMemoryVectorStore();
    $store->store('high', [1.0, 0.0], ['text' => 'High relevance.']);
    $store->store('low', [0.0, 1.0], ['text' => 'Low relevance.']);

    $fakeHttp = new FakeHttpClient();
    $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
        'object' => 'list',
        'data' => [['embedding' => [1.0, 0.0]]],
        'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
    ])));

    $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
    $provider->setHttpClient($fakeHttp);

    $retriever = new Retriever($provider, $store);
    $retriever->setMinScore(0.9);

    $results = $retriever->retrieve('query');

    assert_equals(1, count($results));
    assert_equals('high', $results[0]->getId());
});

// --- RetrievalTool Tests ---
echo "\nRetrievalTool:\n";

test('exposes correct tool interface', function ()
{
    $mockRetriever = new class implements RetrieverInterface
    {
        public function retrieve(string $query, int $topK = 5, array $filter = []): array {
            return [];
        }
    };

    $tool = new RetrievalTool($mockRetriever, name: 'search_docs');

    assert_equals('search_docs', $tool->getName());
    assert_true(str_contains($tool->getDescription(), 'knowledge'));

    $params = $tool->getParameters();
    assert_equals('object', $params['type']);
    assert_true(in_array('query', $params['required']));
});

test('executes search and returns JSON', function ()
{
    $mockRetriever = new class implements RetrieverInterface
    {
        public function retrieve(string $query, int $topK = 5, array $filter = []): array {
            return [
                new RetrievalResult('id1', 'Result about '.$query, 0.9, ['source' => 'doc.pdf']),
            ];
        }
    };

    $tool = new RetrievalTool($mockRetriever);

    $output = $tool->execute(['query' => 'water']);
    $data = json_decode($output, true);

    assert_equals('water', $data['query']);
    assert_equals(1, $data['total_found']);
    assert_true(str_contains($data['results'][0]['text'], 'water'));
});

test('handles empty results gracefully', function ()
{
    $mockRetriever = new class implements RetrieverInterface
    {
        public function retrieve(string $query, int $topK = 5, array $filter = []): array {
            return [];
        }
    };

    $tool = new RetrievalTool($mockRetriever);

    $output = $tool->execute(['query' => 'unknown']);
    $data = json_decode($output, true);

    assert_equals(0, count($data['results']));
    assert_true(isset($data['message']));
});

// --- End-to-End Flow ---
echo "\nEnd-to-End Flow:\n";

test('complete RAG pipeline', function () use ($tempDir)
{
    // 1. Chunk document
    $chunker = new TextChunker(chunkSize: 100, overlap: 20);
    $document = "GRI 303 Water Standard. Organizations must disclose water withdrawal. Water discharge must be reported. Energy consumption is covered in GRI 302.";

    $chunks = $chunker->chunk($document, ['source' => 'gri-303.pdf']);

    assert_true(count($chunks) >= 1);

    // 2. Store chunks (using mock embeddings for simplicity)
    $store = new SqliteVectorStore($tempDir.'/e2e.db');

    foreach ($chunks as $i => $chunk) {
        // Simple mock: first chunk gets [1,0], others get [0,1]
        $vector = $i === 0 ? [1.0, 0.0] : [0.0, 1.0];
        $store->store($chunk->getId(), $vector, $chunk->getAllMetadata());
    }

    // 3. Setup retriever with mock provider
    $fakeHttp = new FakeHttpClient();
    $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
        'object' => 'list',
        'data' => [['embedding' => [1.0, 0.0]]], // Query vector similar to first chunk
        'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
    ])));

    $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test'));
    $provider->setHttpClient($fakeHttp);

    $retriever = new Retriever($provider, $store);

    // 4. Retrieve
    $results = $retriever->retrieve('water withdrawal', topK: 1);

    assert_equals(1, count($results));
    assert_true(str_contains($results[0]->getText(), 'GRI 303') || str_contains($results[0]->getText(), 'water'));
    assert_equals('gri-303.pdf', $results[0]->getSource());
});

// Cleanup
function removeDir(string $path): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path.DIRECTORY_SEPARATOR.$item;
        is_dir($itemPath) ? removeDir($itemPath) : unlink($itemPath);
    }

    rmdir($path);
}

removeDir($tempDir);

// Summary
echo "\n=== Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
