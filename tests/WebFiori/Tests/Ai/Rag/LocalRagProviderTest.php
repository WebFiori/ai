<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Rag;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;
use WebFiori\Ai\Rag\LocalRagProvider;

/**
 * Tests for LocalRagProvider.
 */
class LocalRagProviderTest extends TestCase {
    /**
     * Creates a mock embedder that returns a fixed vector.
     */
    private function createEmbedder(array $fixedVector = [1.0, 0.0, 0.0]): ProviderInterface {
        return new class($fixedVector) implements ProviderInterface {
            private array $vector;

            public function __construct(array $vector) {
                $this->vector = $vector;
            }

            public function chat(array $messages, array $options = []): ChatResponse {
                return new ChatResponse(new Message('assistant', ''), 'mock');
            }

            public function embed(string|array $input, array $options = []): EmbeddingResponse {
                return new EmbeddingResponse([$this->vector], 'mock-embed');
            }

            public function generateImage(ImageRequest $request): ImageResponse {
                return new ImageResponse([], 'mock');
            }

            public function getName(): string {
                return 'mock-embedder';
            }

            public function healthCheck(int $timeout = 5): HealthCheckResult {
                return HealthCheckResult::success(1, 'mock');
            }

            public function setHttpClient(HttpClientInterface $client): void {
            }

            public function setLogCallback(?callable $callback): void {
            }

            public function streamChat(
                array $messages,
                callable $onToken,
                ?callable $onComplete = null,
                ?callable $onError = null,
                array $options = []
            ): void {
            }
        };
    }

    // =========================================================================
    // retrieve() tests
    // =========================================================================

    public function testRetrieve_ReturnsRelevantResults(): void {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [1.0, 0.0, 0.0], ['text' => 'About water quality.']);
        $store->store('doc-2', [0.9, 0.1, 0.0], ['text' => 'Water treatment process.']);
        $store->store('doc-3', [0.0, 1.0, 0.0], ['text' => 'About energy.']);

        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $results = $provider->retrieve('water', topK: 3);

        $this->assertCount(3, $results);
        $this->assertEquals('doc-1', $results[0]->getId());
        $this->assertEqualsWithDelta(1.0, $results[0]->getScore(), 0.001);
        $this->assertEquals('About water quality.', $results[0]->getText());
    }

    public function testRetrieve_FiltersByMinScore(): void {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [1.0, 0.0, 0.0], ['text' => 'High match.']);
        $store->store('doc-2', [0.0, 1.0, 0.0], ['text' => 'No match.']);

        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder, minScore: 0.8);

        $results = $provider->retrieve('query', topK: 10);

        $this->assertCount(1, $results);
        $this->assertEquals('doc-1', $results[0]->getId());
    }

    public function testRetrieve_RespectsTopK(): void {
        $store = new InMemoryVectorStore();
        for ($i = 0; $i < 10; $i++) {
            $store->store("doc-$i", [1.0, 0.0, 0.0], ['text' => "Document $i"]);
        }

        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $results = $provider->retrieve('query', topK: 3);

        $this->assertCount(3, $results);
    }

    public function testRetrieve_EmptyStoreReturnsEmpty(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $results = $provider->retrieve('anything');

        $this->assertSame([], $results);
    }

    // =========================================================================
    // ingest() tests
    // =========================================================================

    public function testIngest_StoresContentAndReturnsId(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([0.5, 0.5, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $id = $provider->ingest('PHP is a server-side language.');

        $this->assertNotEmpty($id);
        $this->assertSame(1, $store->count());
        $this->assertNotNull($store->get($id));
    }

    public function testIngest_IdStartsWithDocPrefix(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $id = $provider->ingest('Some content.');

        $this->assertStringStartsWith('doc_', $id);
    }

    public function testIngest_StoresTextInMetadata(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $id = $provider->ingest('The sky is blue.');

        $record = $store->get($id);
        $metadata = $record->getMetadata();
        $this->assertArrayHasKey('text', $metadata);
        $this->assertSame('The sky is blue.', $metadata['text']);
    }

    public function testIngest_PreservesCustomMetadata(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $id = $provider->ingest('Important fact.', ['source' => 'docs.pdf', 'page' => 42]);

        $record = $store->get($id);
        $metadata = $record->getMetadata();
        $this->assertSame('docs.pdf', $metadata['source']);
        $this->assertSame(42, $metadata['page']);
        $this->assertSame('Important fact.', $metadata['text']);
    }

    // =========================================================================
    // delete() tests
    // =========================================================================

    public function testDelete_RemovesFromStore(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $id = $provider->ingest('To be deleted.');
        $this->assertSame(1, $store->count());

        $provider->delete($id);

        $this->assertSame(0, $store->count());
        $this->assertNull($store->get($id));
    }

    // =========================================================================
    // Getters and setters
    // =========================================================================

    public function testGetters(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder, minScore: 0.75, embeddingModel: 'text-embedding-3-small');

        $this->assertSame($store, $provider->getStore());
        $this->assertEqualsWithDelta(0.75, $provider->getMinScore(), 0.001);
        $this->assertSame('text-embedding-3-small', $provider->getEmbeddingModel());
    }

    public function testSetMinScore(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $provider = new LocalRagProvider($store, $embedder);

        $this->assertEqualsWithDelta(0.0, $provider->getMinScore(), 0.001);

        $result = $provider->setMinScore(0.85);

        $this->assertSame($provider, $result); // Fluent interface
        $this->assertEqualsWithDelta(0.85, $provider->getMinScore(), 0.001);
    }
}
