<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool;

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Embedding\VectorStorageInterface;
use WebFiori\Ai\Provider\ProviderInterface;
use WebFiori\Ai\Rag\RetrievalResult;

/**
 * Core memory class for AI agents.
 *
 * AgentMemory provides long-term memory capabilities by storing facts as
 * vector embeddings and retrieving them via semantic similarity search.
 * Facts can be stored manually or automatically via a RememberStrategyInterface.
 *
 * Supports superseding (replacing) old memories when facts are updated,
 * and filtering recall results by a minimum similarity score threshold.
 *
 * Example:
 * ```php
 * $memory = new AgentMemory($vectorStore, $embeddingProvider);
 * $memory->setMinScore(0.75);
 *
 * $id = $memory->remember('The user prefers dark mode.');
 * $results = $memory->recall('What theme does the user like?');
 * $memory->forget($id);
 * ```
 *
 * @author Ibrahim
 */
class AgentMemory {
    /**
     * The AI provider used for generating embeddings.
     *
     * @var ProviderInterface
     */
    private ProviderInterface $embedder;

    /**
     * Optional embedding model override.
     *
     * @var string|null
     */
    private ?string $embeddingModel;

    /**
     * Minimum similarity score threshold for recall results.
     *
     * @var float
     */
    private float $minScore;

    /**
     * The vector storage backend.
     *
     * @var VectorStorageInterface
     */
    private VectorStorageInterface $store;

    /**
     * Maximum number of results to return from recall.
     *
     * @var int
     */
    private int $topK;

    /**
     * Creates a new AgentMemory instance.
     *
     * @param VectorStorageInterface $store The vector storage backend for
     *        persisting and querying memory embeddings.
     * @param ProviderInterface $embedder The AI provider for generating
     *        vector embeddings from text.
     * @param float $minScore Minimum similarity score threshold (0.0 to 1.0).
     *        Results below this threshold are filtered out during recall.
     * @param int $topK Maximum number of results to return from recall.
     * @param string|null $embeddingModel Optional model name for embeddings.
     *        If null, the provider's default model is used.
     */
    public function __construct(
        VectorStorageInterface $store,
        ProviderInterface $embedder,
        float $minScore = 0.7,
        int $topK = 5,
        ?string $embeddingModel = null,
    ) {
        $this->store = $store;
        $this->embedder = $embedder;
        $this->minScore = $minScore;
        $this->topK = $topK;
        $this->embeddingModel = $embeddingModel;
    }

    /**
     * Deletes a memory by its identifier.
     *
     * @param string $id The unique identifier of the memory to delete.
     *
     * @return bool True if the memory was deleted, false if it did not exist.
     */
    public function forget(string $id): bool {
        return $this->store->delete($id);
    }

    /**
     * Returns the embedding model used for generating vectors.
     *
     * @return string|null The model name, or null if using provider default.
     */
    public function getEmbeddingModel(): ?string {
        return $this->embeddingModel;
    }

    /**
     * Returns the minimum similarity score threshold.
     *
     * @return float The minimum score (0.0 to 1.0).
     */
    public function getMinScore(): float {
        return $this->minScore;
    }

    /**
     * Returns the vector storage backend.
     *
     * @return VectorStorageInterface The store.
     */
    public function getStore(): VectorStorageInterface {
        return $this->store;
    }

    /**
     * Returns the maximum number of results returned by recall.
     *
     * @return int The top-K value.
     */
    public function getTopK(): int {
        return $this->topK;
    }

    /**
     * Retrieves memories relevant to a query using semantic similarity.
     *
     * Embeds the query, searches the vector store, and filters results
     * by the minimum score threshold.
     *
     * @param string $query The search query text.
     * @param int|null $topK Maximum number of results. If null, uses the
     *        instance default.
     *
     * @return RetrievalResult[] Results sorted by score descending.
     */
    public function recall(string $query, ?int $topK = null): array {
        $topK = $topK ?? $this->topK;

        $options = [];

        if ($this->embeddingModel !== null) {
            $options[ChatOption::MODEL] = $this->embeddingModel;
        }

        $response = $this->embedder->embed($query, $options);
        $vector = $response->getVector();

        $records = $this->store->query($vector, $topK);

        $results = [];

        foreach ($records as $record) {
            if ($record->getScore() < $this->minScore) {
                continue;
            }

            $metadata = $record->getMetadata();
            $text = $metadata['text'] ?? '';

            unset($metadata['text']);

            $results[] = new RetrievalResult(
                id: $record->getId(),
                text: $text,
                score: $record->getScore(),
                metadata: $metadata,
            );
        }

        return $results;
    }

    /**
     * Stores a fact in long-term memory.
     *
     * Generates a unique ID, embeds the fact text, and stores the vector
     * with metadata in the vector store. Optionally supersedes (deletes)
     * an existing memory that this fact replaces.
     *
     * @param string $fact The fact text to remember.
     * @param array<string, mixed> $metadata Additional metadata to store
     *        alongside the fact. 'timestamp' and 'text' are added automatically.
     * @param string|null $supersedes If provided, the ID of an existing memory
     *        that this fact replaces. The old memory is deleted before storing.
     *
     * @return string The unique identifier of the stored memory.
     */
    public function remember(string $fact, array $metadata = [], ?string $supersedes = null): string {
        $id = 'mem_'.substr(md5($fact.microtime(true)), 0, 12);

        $metadata['timestamp'] = time();
        $metadata['text'] = $fact;

        if ($supersedes !== null) {
            $this->store->delete($supersedes);
        }

        $options = [];

        if ($this->embeddingModel !== null) {
            $options[ChatOption::MODEL] = $this->embeddingModel;
        }

        $response = $this->embedder->embed($fact, $options);
        $vector = $response->getVector();

        $this->store->store($id, $vector, $metadata);

        return $id;
    }

    /**
     * Sets the minimum similarity score threshold.
     *
     * Results below this threshold are filtered out during recall.
     *
     * @param float $minScore Minimum score (0.0 to 1.0).
     */
    public function setMinScore(float $minScore): void {
        $this->minScore = $minScore;
    }

    /**
     * Sets the maximum number of results returned by recall.
     *
     * @param int $topK The top-K value.
     */
    public function setTopK(int $topK): void {
        $this->topK = $topK;
    }
}
