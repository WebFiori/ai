<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Rag;

use WebFiori\Ai\Cache\CacheInterface;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Embedding\VectorStorageInterface;
use WebFiori\Ai\MetricsTrait;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * Default retriever implementation.
 *
 * Embeds queries using a provider, searches a vector store, and returns
 * results above a minimum score threshold. Also supports ingestion and
 * deletion via the RagProviderInterface contract.
 *
 * Example:
 * ```php
 * $retriever = new Retriever($embedProvider, $vectorStore);
 * $retriever->setMinScore(0.7);
 *
 * $results = $retriever->retrieve('What is GRI 303?', topK: 5);
 *
 * foreach ($results as $result) {
 *     echo $result->getText() . "\n";
 *     echo "Source: " . $result->getSource() . ", Score: " . $result->getScore() . "\n";
 * }
 * ```
 *
 * @author Ibrahim
 */
class Retriever implements RagProviderInterface {
    /**
     * Optional cache for query embeddings.
     *
     * @var CacheInterface|null
     */
    private ?CacheInterface $cache = null;

    /**
     * Embedding model to use (overrides provider default).
     *
     * @var string|null
     */
    private ?string $embeddingModel = null;

    /**
     * Minimum similarity score threshold.
     *
     * @var float
     */
    private float $minScore = 0.0;

    /**
     * Provider for generating embeddings.
     *
     * @var ProviderInterface
     */
    private ProviderInterface $provider;

    /**
     * Vector store for similarity search.
     *
     * @var VectorStorageInterface
     */
    private VectorStorageInterface $store;

    /**
     * Track whether the last embedding was a cache hit.
     *
     * @var bool
     */
    private bool $wasCacheHit = false;

    /**
     * Creates a new Retriever instance.
     *
     * @param ProviderInterface $provider Provider for embeddings.
     * @param VectorStorageInterface $store Vector store for search.
     * @param array<string, mixed> $options Configuration options:
     *        - 'embedding_model': Model name for embeddings
     *        - 'min_score': Minimum similarity threshold (0.0 to 1.0)
     *        - 'cache': CacheInterface for embedding cache
     */
    public function __construct(
        ProviderInterface $provider,
        VectorStorageInterface $store,
        array $options = [],
    ) {
        $this->provider = $provider;
        $this->store = $store;

        if (isset($options[ChatOption::EMBEDDING_MODEL])) {
            $this->embeddingModel = $options[ChatOption::EMBEDDING_MODEL];
        }

        if (isset($options['min_score'])) {
            $this->minScore = (float) $options['min_score'];
        }

        if (isset($options['cache']) && $options['cache'] instanceof CacheInterface) {
            $this->cache = $options['cache'];
        }
    }

    /**
     * Deletes a document by ID from the vector store.
     *
     * @param string $id The document ID to delete.
     */
    public function delete(string $id): void {
        $this->store->delete($id);
    }

    /**
     * Ingests content into the vector store.
     *
     * Generates an embedding for the content and stores it with the
     * original text preserved in metadata.
     *
     * @param string $content The text content to ingest.
     * @param array<string, mixed> $metadata Optional metadata.
     *
     * @return string The generated document ID.
     */
    public function ingest(string $content, array $metadata = []): string {
        $id = 'doc_'.substr(md5($content.microtime(true)), 0, 12);

        $embedOptions = [];

        if ($this->embeddingModel !== null) {
            $embedOptions[ChatOption::MODEL] = $this->embeddingModel;
        }

        $vector = $this->provider->embed($content, $embedOptions)->getVector();

        $metadata['text'] = $content;
        $this->store->store($id, $vector, $metadata);

        return $id;
    }

    /**
     * Retrieves relevant chunks for a query.
     *
     * @param string $query The search query.
     * @param int $topK Maximum number of results.
     * @param array<string, mixed> $options Additional options. Supports:
     *        - 'filter': array<string, mixed> metadata filter for vector store.
     *        For backward compatibility, a flat array is treated as a filter.
     *
     * @return RetrievalResult[] Results sorted by score descending.
     */
    public function retrieve(string $query, int $topK = 5, array $options = []): array {
        $startTime = microtime(true);

        // Backward compat: if options is a flat metadata filter, use as filter directly
        $filter = $options['filter'] ?? $options;

        // Get query embedding (with optional caching)
        $embeddingStart = microtime(true);
        $queryVector = $this->getQueryEmbedding($query);
        $embeddingMs = (microtime(true) - $embeddingStart) * 1000;

        // Search vector store
        $searchStart = microtime(true);
        $records = $this->store->query($queryVector, $topK, $filter);
        $searchMs = (microtime(true) - $searchStart) * 1000;

        // Filter by minimum score and convert to RetrievalResult
        $results = [];

        foreach ($records as $record) {
            if ($record->getScore() < $this->minScore) {
                continue;
            }

            $metadata = $record->getMetadata();
            $text = $metadata['text'] ?? '';

            // Remove text from metadata to avoid duplication
            unset($metadata['text']);

            $results[] = new RetrievalResult(
                id: $record->getId(),
                text: $text,
                score: $record->getScore(),
                metadata: $metadata,
            );
        }

        $totalMs = (microtime(true) - $startTime) * 1000;

        // Emit metrics
        $this->emitMetric('retrieval', [
            'query_length' => mb_strlen($query),
            'top_k' => $topK,
            'results_count' => count($results),
            'top_score' => count($results) > 0 ? $results[0]->getScore() : null,
            'min_score_threshold' => $this->minScore,
            'embedding_ms' => round($embeddingMs, 2),
            'search_ms' => round($searchMs, 2),
            'total_ms' => round($totalMs, 2),
            'cache_hit' => $this->cache !== null && $this->wasCacheHit,
        ]);

        return $results;
    }

    /**
     * Sets the embedding cache.
     *
     * @param CacheInterface $cache Cache implementation.
     *
     * @return self For method chaining.
     */
    public function setCache(CacheInterface $cache): self {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Sets the embedding model.
     *
     * @param string $model Model name.
     *
     * @return self For method chaining.
     */
    public function setEmbeddingModel(string $model): self {
        $this->embeddingModel = $model;

        return $this;
    }

    /**
     * Sets the minimum score threshold.
     *
     * Results below this threshold are filtered out.
     *
     * @param float $score Minimum score (0.0 to 1.0).
     *
     * @return self For method chaining.
     */
    public function setMinScore(float $score): self {
        $this->minScore = $score;

        return $this;
    }

    /**
     * Gets the embedding for a query, using cache if available.
     *
     * @param string $query The query text.
     *
     * @return float[] The embedding vector.
     */
    private function getQueryEmbedding(string $query): array {
        $this->wasCacheHit = false;

        // Check cache
        if ($this->cache !== null) {
            $cacheKey = 'embed:'.md5($query.':'.($this->embeddingModel ?? 'default'));
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                $this->wasCacheHit = true;

                return $cached;
            }
        }

        // Generate embedding
        $options = [];

        if ($this->embeddingModel !== null) {
            $options[ChatOption::MODEL] = $this->embeddingModel;
        }

        $response = $this->provider->embed($query, $options);
        $vector = $response->getVector();

        // Store in cache
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $vector);
        }

        return $vector;
    }
    use MetricsTrait;
}
