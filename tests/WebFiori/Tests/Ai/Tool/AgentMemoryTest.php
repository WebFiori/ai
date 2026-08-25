<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Tool;

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
use WebFiori\Ai\Tool\AgentMemory;

/**
 * Tests for AgentMemory.
 */
class AgentMemoryTest extends TestCase {
    /**
     * Creates a mock embedder that returns a fixed vector.
     *
     * @param float[] $fixedVector The vector to return from embed().
     */
    private function createEmbedder(array $fixedVector = [1.0, 0.0, 0.0]): ProviderInterface {
        return new class($fixedVector) implements ProviderInterface {
            private array $vector;
            public array $lastOptions = [];

            public function __construct(array $vector) {
                $this->vector = $vector;
            }

            public function chat(array $messages, array $options = []): ChatResponse {
                return new ChatResponse(new Message('assistant', ''), 'mock');
            }

            public function embed(string|array $input, array $options = []): EmbeddingResponse {
                $this->lastOptions = $options;
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

    /**
     * Creates an AgentMemory with a LocalRagProvider wrapping an InMemoryVectorStore and a mock embedder.
     */
    private function createMemory(array $fixedVector = [1.0, 0.0, 0.0], float $minScore = 0.7, int $topK = 5): array {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder($fixedVector);
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, $minScore, $topK);

        return [$memory, $store, $embedder, $ragProvider];
    }

    // =========================================================================
    // remember() tests
    // =========================================================================

    public function testRemember_StoresFactAndReturnsId(): void {
        [$memory, $store] = $this->createMemory();

        $id = $memory->remember('The user prefers dark mode.');

        $this->assertNotEmpty($id);
        $this->assertSame(1, $store->count());
    }

    public function testRemember_IdStartsWithDocPrefix(): void {
        [$memory] = $this->createMemory();

        $id = $memory->remember('Some fact');

        $this->assertStringStartsWith('doc_', $id);
    }

    public function testRemember_AddsTimestampToMetadata(): void {
        [$memory, $store] = $this->createMemory();

        $beforeTime = time();
        $id = $memory->remember('A fact');
        $afterTime = time();

        $record = $store->get($id);
        $this->assertNotNull($record);
        $metadata = $record->getMetadata();
        $this->assertArrayHasKey('timestamp', $metadata);
        $this->assertGreaterThanOrEqual($beforeTime, $metadata['timestamp']);
        $this->assertLessThanOrEqual($afterTime, $metadata['timestamp']);
    }

    public function testRemember_AddsTextToMetadata(): void {
        [$memory, $store] = $this->createMemory();

        $id = $memory->remember('User likes PHP.');

        $record = $store->get($id);
        $metadata = $record->getMetadata();
        $this->assertArrayHasKey('text', $metadata);
        $this->assertSame('User likes PHP.', $metadata['text']);
    }

    public function testRemember_WithCustomMetadata(): void {
        [$memory, $store] = $this->createMemory();

        $id = $memory->remember('Fact', ['source' => 'agent:helper', 'category' => 'preference']);

        $record = $store->get($id);
        $metadata = $record->getMetadata();
        $this->assertSame('agent:helper', $metadata['source']);
        $this->assertSame('preference', $metadata['category']);
        // Should also have text and timestamp
        $this->assertSame('Fact', $metadata['text']);
        $this->assertArrayHasKey('timestamp', $metadata);
    }

    public function testRemember_WithSupersedes_DeletesOldMemory(): void {
        [$memory, $store] = $this->createMemory();

        $oldId = $memory->remember('Old fact');
        $this->assertSame(1, $store->count());

        $newId = $memory->remember('New fact replacing old', [], $oldId);

        // Old record should be gone
        $this->assertNull($store->get($oldId));
        // New record should be present
        $this->assertNotNull($store->get($newId));
        $this->assertSame(1, $store->count());
    }

    // =========================================================================
    // recall() tests
    // =========================================================================

    public function testRecall_ReturnsRelevantMemories(): void {
        [$memory, $store] = $this->createMemory([1.0, 0.0, 0.0], 0.5);

        $memory->remember('The user prefers dark mode.');

        // Query with same vector → cosine similarity = 1.0
        $results = $memory->recall('dark mode preference');

        $this->assertCount(1, $results);
        $this->assertSame('The user prefers dark mode.', $results[0]->getText());
        $this->assertEqualsWithDelta(1.0, $results[0]->getScore(), 0.001);
    }

    public function testRecall_FiltersByMinScore(): void {
        $store = new InMemoryVectorStore();
        // Store vectors manually to control similarity
        $store->store('mem_1', [1.0, 0.0, 0.0], ['text' => 'High match', 'timestamp' => time()]);
        $store->store('mem_2', [0.0, 1.0, 0.0], ['text' => 'No match', 'timestamp' => time()]);

        // Embedder returns [1,0,0] so mem_1 gets score=1.0 and mem_2 gets score=0.0
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, minScore: 0.5);

        $results = $memory->recall('query');

        $this->assertCount(1, $results);
        $this->assertSame('High match', $results[0]->getText());
    }

    public function testRecall_RespectsTopK(): void {
        $store = new InMemoryVectorStore();
        // Store 5 vectors all similar to [1,0,0]
        for ($i = 0; $i < 5; $i++) {
            $store->store("mem_$i", [1.0, 0.0, 0.0], ['text' => "Fact $i", 'timestamp' => time()]);
        }

        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, minScore: 0.5, topK: 3);

        $results = $memory->recall('query');

        $this->assertCount(3, $results);
    }

    public function testRecall_EmptyStoreReturnsEmpty(): void {
        [$memory] = $this->createMemory();

        $results = $memory->recall('anything');

        $this->assertSame([], $results);
    }

    public function testRecall_CustomTopKOverridesDefault(): void {
        $store = new InMemoryVectorStore();
        for ($i = 0; $i < 10; $i++) {
            $store->store("mem_$i", [1.0, 0.0, 0.0], ['text' => "Fact $i", 'timestamp' => time()]);
        }

        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, minScore: 0.5, topK: 5);

        // Override topK at call time
        $results = $memory->recall('query', 2);

        $this->assertCount(2, $results);
    }

    // =========================================================================
    // forget() tests
    // =========================================================================

    public function testForget_DeletesMemory(): void {
        [$memory, $store] = $this->createMemory();

        $id = $memory->remember('To be forgotten');
        $this->assertSame(1, $store->count());

        $result = $memory->forget($id);

        $this->assertTrue($result);
        $this->assertSame(0, $store->count());
        $this->assertNull($store->get($id));
    }

    public function testForget_ReturnsTrueForNonexistentWithLocalProvider(): void {
        // LocalRagProvider::delete() doesn't throw for non-existent IDs,
        // so forget() returns true. A provider that throws would return false.
        [$memory] = $this->createMemory();

        $result = $memory->forget('mem_nonexistent');

        // With LocalRagProvider backed by InMemoryVectorStore, delete doesn't throw
        $this->assertTrue($result);
    }

    public function testForget_ReturnsFalseWhenProviderThrows(): void {
        // Create a RagProvider that throws on delete for non-existent IDs
        $ragProvider = new class implements \WebFiori\Ai\Rag\RagProviderInterface {
            public function retrieve(string $query, int $topK = 5, array $options = []): array {
                return [];
            }

            public function ingest(string $content, array $metadata = []): string {
                return 'doc_test';
            }

            public function delete(string $id): void {
                throw new \RuntimeException('Not found: ' . $id);
            }
        };

        $memory = new AgentMemory($ragProvider);
        $result = $memory->forget('non_existent_id');

        $this->assertFalse($result);
    }

    // =========================================================================
    // Getters and setters
    // =========================================================================

    public function testGetters(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([1.0, 0.0, 0.0]);
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, 0.8, 10);

        $this->assertSame($ragProvider, $memory->getRagProvider());
        $this->assertEqualsWithDelta(0.8, $memory->getMinScore(), 0.001);
        $this->assertSame(10, $memory->getTopK());
    }

    public function testSetters(): void {
        [$memory] = $this->createMemory();

        $memory->setMinScore(0.9);
        $this->assertEqualsWithDelta(0.9, $memory->getMinScore(), 0.001);

        $memory->setTopK(20);
        $this->assertSame(20, $memory->getTopK());
    }

    public function testConstructor_WithRagProvider(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createEmbedder([0.5, 0.5, 0.0]);
        $ragProvider = new LocalRagProvider($store, $embedder, embeddingModel: 'custom-model');
        $memory = new AgentMemory($ragProvider);

        $this->assertSame($ragProvider, $memory->getRagProvider());
        $this->assertSame('custom-model', $ragProvider->getEmbeddingModel());

        // When remember is called, the model should be passed in options
        $memory->remember('Test fact');

        // The embedder captured the options - verify model was passed
        $this->assertSame('custom-model', $embedder->lastOptions['model'] ?? null);
    }
}
