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

use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Rag\RagProviderInterface;
use WebFiori\Ai\Rag\RetrievalResult;

/**
 * Core memory class for AI agents.
 *
 * AgentMemory provides long-term memory capabilities by storing facts
 * via a RAG provider and retrieving them via semantic similarity search.
 * Facts can be stored manually or automatically via a RememberStrategyInterface.
 *
 * Supports superseding (replacing) old memories when facts are updated,
 * and filtering recall results by a minimum similarity score threshold.
 *
 * Example:
 * ```php
 * $ragProvider = new LocalRagProvider($vectorStore, $embeddingProvider);
 * $memory = new AgentMemory($ragProvider);
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
     * Minimum similarity score threshold for recall results.
     *
     * @var float
     */
    private float $minScore;

    /**
     * The RAG provider for storage and retrieval.
     *
     * @var RagProviderInterface
     */
    private RagProviderInterface $ragProvider;

    /**
     * Maximum number of results to return from recall.
     *
     * @var int
     */
    private int $topK;

    /**
     * Creates a new AgentMemory instance.
     *
     * @param RagProviderInterface $ragProvider The RAG provider for storing
     *        and retrieving memory embeddings.
     * @param float $minScore Minimum similarity score threshold (0.0 to 1.0).
     *        Results below this threshold are filtered out during recall.
     * @param int $topK Maximum number of results to return from recall.
     */
    public function __construct(
        RagProviderInterface $ragProvider,
        float $minScore = 0.7,
        int $topK = 5,
    ) {
        $this->ragProvider = $ragProvider;
        $this->minScore = $minScore;
        $this->topK = $topK;
    }

    /**
     * Deletes a memory by its identifier.
     *
     * @param string $id The unique identifier of the memory to delete.
     *
     * @return bool True if the memory was deleted, false if deletion is not
     *         supported or an error occurred.
     */
    public function forget(string $id): bool {
        try {
            $this->ragProvider->delete($id);

            return true;
        } catch (UnsupportedFeatureException) {
            return false;
        } catch (\Throwable) {
            return false;
        }
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
     * Returns the RAG provider.
     *
     * @return RagProviderInterface The RAG provider.
     */
    public function getRagProvider(): RagProviderInterface {
        return $this->ragProvider;
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
     * Searches the RAG provider and filters results by the minimum score
     * threshold.
     *
     * @param string $query The search query text.
     * @param int|null $topK Maximum number of results. If null, uses the
     *        instance default.
     *
     * @return RetrievalResult[] Results sorted by score descending.
     */
    public function recall(string $query, ?int $topK = null): array {
        $topK = $topK ?? $this->topK;

        $results = $this->ragProvider->retrieve($query, $topK);

        $filtered = [];

        foreach ($results as $result) {
            if ($result->getScore() >= $this->minScore) {
                $filtered[] = $result;
            }
        }

        return $filtered;
    }

    /**
     * Stores a fact in long-term memory.
     *
     * Ingests the fact via the RAG provider with metadata. Optionally
     * supersedes (deletes) an existing memory that this fact replaces.
     *
     * @param string $fact The fact text to remember.
     * @param array<string, mixed> $metadata Additional metadata to store
     *        alongside the fact. 'timestamp' is added automatically.
     * @param string|null $supersedes If provided, the ID of an existing memory
     *        that this fact replaces. The old memory is deleted before storing.
     *
     * @return string The unique identifier of the stored memory.
     */
    public function remember(string $fact, array $metadata = [], ?string $supersedes = null): string {
        if ($supersedes !== null) {
            $this->ragProvider->delete($supersedes);
        }

        $metadata['timestamp'] = time();

        return $this->ragProvider->ingest($fact, $metadata);
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
