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
use WebFiori\Ai\Cache\InMemoryCache;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;
use WebFiori\Ai\Rag\Retriever;

/**
 * Regression tests for Retriever's query-embedding cache.
 *
 * A prior bug called CacheInterface::set() with a raw vector (2 args) and
 * returned the CachedResponse directly, which threw a TypeError against any
 * spec-compliant cache. These tests exercise the cache path with a real
 * InMemoryCache and assert the cache actually short-circuits the embedder.
 */
class RetrieverCacheTest extends TestCase {
    private function countingEmbedder(array $vector): ProviderInterface {
        return new class($vector) implements ProviderInterface {
            private array $vector;
            public int $calls = 0;

            public function __construct(array $vector) {
                $this->vector = $vector;
            }

            public function chat(array $messages, array $options = []): ChatResponse {
                return new ChatResponse(new Message('assistant', ''), 'mock');
            }

            public function embed(string|array $input, array $options = []): EmbeddingResponse {
                $this->calls++;

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

            public function streamChat(array $messages, callable $onToken, ?callable $onComplete = null, ?callable $onError = null, array $options = []): void {
            }
        };
    }

    private function store(): InMemoryVectorStore {
        $store = new InMemoryVectorStore();
        $store->store('d1', [1.0, 0.0, 0.0], ['text' => 'alpha']);
        $store->store('d2', [0.0, 1.0, 0.0], ['text' => 'beta']);

        return $store;
    }

    public function testCacheMissThenHit_EmbedsOnlyOnce(): void {
        $embedder = $this->countingEmbedder([1.0, 0.0, 0.0]);
        $retriever = new Retriever($embedder, $this->store(), ['cache' => new InMemoryCache()]);

        // First call: cache miss -> embeds.
        $first = $retriever->retrieve('same query', topK: 2);
        $this->assertNotEmpty($first);
        $this->assertSame(1, $embedder->calls);

        // Second identical call: cache hit -> must NOT embed again.
        $second = $retriever->retrieve('same query', topK: 2);
        $this->assertNotEmpty($second);
        $this->assertSame(1, $embedder->calls, 'Embedder should not be called on cache hit');

        // Results are consistent across hit/miss.
        $this->assertSame($first[0]->getId(), $second[0]->getId());
    }

    public function testDifferentQueries_EmbedSeparately(): void {
        $embedder = $this->countingEmbedder([1.0, 0.0, 0.0]);
        $retriever = new Retriever($embedder, $this->store(), ['cache' => new InMemoryCache()]);

        $retriever->retrieve('query one', topK: 1);
        $retriever->retrieve('query two', topK: 1);

        $this->assertSame(2, $embedder->calls);
    }

    public function testSetCache_EnablesCachingFluently(): void {
        $embedder = $this->countingEmbedder([1.0, 0.0, 0.0]);
        $retriever = new Retriever($embedder, $this->store());

        $this->assertSame($retriever, $retriever->setCache(new InMemoryCache()));

        $retriever->retrieve('cached query', topK: 1);
        $retriever->retrieve('cached query', topK: 1);

        $this->assertSame(1, $embedder->calls);
    }

    public function testNoCache_EmbedsEveryTime(): void {
        $embedder = $this->countingEmbedder([1.0, 0.0, 0.0]);
        $retriever = new Retriever($embedder, $this->store());

        $retriever->retrieve('q', topK: 1);
        $retriever->retrieve('q', topK: 1);

        $this->assertSame(2, $embedder->calls);
    }
}
