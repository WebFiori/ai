<?php

namespace WebFiori\Tests\Ai\Rag;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Rag\Retriever;

class RetrieverTest extends TestCase {
    public function testRetrieveReturnsResults(): void {
        // Setup: store some vectors
        $store = new InMemoryVectorStore();
        $store->store('chunk-1', [1.0, 0.0, 0.0], ['text' => 'First chunk about water.', 'source' => 'doc.pdf']);
        $store->store('chunk-2', [0.9, 0.1, 0.0], ['text' => 'Second chunk about water quality.', 'source' => 'doc.pdf']);
        $store->store('chunk-3', [0.0, 1.0, 0.0], ['text' => 'Third chunk about energy.', 'source' => 'doc.pdf']);

        // Setup: mock provider that returns a query vector similar to water chunks
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [1.0, 0.0, 0.0]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'text-embedding-3-small'));
        $provider->setHttpClient($fakeHttp);

        // Test retrieval
        $retriever = new Retriever($provider, $store);
        $results = $retriever->retrieve('water requirements', topK: 2);

        $this->assertCount(2, $results);
        $this->assertEquals('chunk-1', $results[0]->getId());
        $this->assertEquals('First chunk about water.', $results[0]->getText());
        $this->assertEqualsWithDelta(1.0, $results[0]->getScore(), 0.001);
    }

    public function testRetrieveWithMinScoreFilter(): void {
        $store = new InMemoryVectorStore();
        $store->store('chunk-1', [1.0, 0.0], ['text' => 'High relevance.']);
        $store->store('chunk-2', [0.5, 0.5], ['text' => 'Medium relevance.']);
        $store->store('chunk-3', [0.0, 1.0], ['text' => 'Low relevance.']);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [1.0, 0.0]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store);
        $retriever->setMinScore(0.8);

        $results = $retriever->retrieve('query', topK: 10);

        // Only chunk-1 should pass the 0.8 threshold
        $this->assertCount(1, $results);
        $this->assertEquals('chunk-1', $results[0]->getId());
    }

    public function testRetrieveWithMetadataFilter(): void {
        $store = new InMemoryVectorStore();
        $store->store('chunk-1', [1.0, 0.0], ['text' => 'Water info.', 'category' => 'water']);
        $store->store('chunk-2', [0.9, 0.1], ['text' => 'Energy info.', 'category' => 'energy']);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [1.0, 0.0]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store);

        $results = $retriever->retrieve('query', options: ['category' => 'water']);

        $this->assertCount(1, $results);
        $this->assertEquals('chunk-1', $results[0]->getId());
    }

    public function testRetrieveReturnsEmptyWhenNoMatches(): void {
        $store = new InMemoryVectorStore();
        // Store nothing

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [1.0, 0.0]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store);

        $results = $retriever->retrieve('query');

        $this->assertCount(0, $results);
    }

    public function testRetrieveEmitsMetrics(): void {
        $store = new InMemoryVectorStore();
        $store->store('chunk-1', [1.0, 0.0], ['text' => 'Test content.']);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [1.0, 0.0]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store);

        $capturedMetrics = null;
        $retriever->setMetricsCallback(function ($name, $data) use (&$capturedMetrics) {
            $capturedMetrics = ['name' => $name, 'data' => $data];
        });

        $retriever->retrieve('test query');

        $this->assertNotNull($capturedMetrics);
        $this->assertEquals('retrieval', $capturedMetrics['name']);
        $this->assertArrayHasKey('query_length', $capturedMetrics['data']);
        $this->assertArrayHasKey('results_count', $capturedMetrics['data']);
        $this->assertArrayHasKey('embedding_ms', $capturedMetrics['data']);
        $this->assertArrayHasKey('search_ms', $capturedMetrics['data']);
        $this->assertArrayHasKey('total_ms', $capturedMetrics['data']);
    }

    public function testSetEmbeddingModel(): void {
        $store = new InMemoryVectorStore();
        $store->store('chunk-1', [1.0, 0.0], ['text' => 'Test.']);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [1.0, 0.0]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store);
        $result = $retriever->setEmbeddingModel('text-embedding-3-small');

        $this->assertSame($retriever, $result); // Fluent interface

        $retriever->retrieve('test');

        // Verify the request used the specified model
        $request = $fakeHttp->getLastRequest();
        $body = json_decode($request->getBody(), true);
        $this->assertEquals('text-embedding-3-small', $body['model']);
    }

    public function testConstructorOptions(): void {
        $store = new InMemoryVectorStore();
        $store->store('chunk-1', [1.0, 0.0], ['text' => 'Test.']);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [1.0, 0.0]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store, [
            'embedding_model' => 'custom-model',
            'min_score' => 0.5,
        ]);

        $retriever->retrieve('test');

        $request = $fakeHttp->getLastRequest();
        $body = json_decode($request->getBody(), true);
        $this->assertEquals('custom-model', $body['model']);
    }
}
