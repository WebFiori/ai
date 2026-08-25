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

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Embedding\VectorStorageInterface;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * Local RAG provider backed by a vector store and embedding provider.
 *
 * Embeds queries and content using a provider, stores vectors in a local
 * vector store, and retrieves results above a minimum score threshold.
 *
 * Example:
 * ```php
 * $provider = new LocalRagProvider(
 *     store: $vectorStore,
 *     embedder: $openaiClient,
 *     minScore: 0.7,
 * );
 *
 * // Ingest content
 * $id = $provider->ingest('PHP is a server-side language.', ['source' => 'docs']);
 *
 * // Retrieve relevant documents
 * $results = $provider->retrieve('What is PHP?', topK: 5);
 * ```
 *
 * @author Ibrahim
 */
class LocalRagProvider implements RagProviderInterface {
    /**
     * Provider for generating embeddings.
     *
     * @var ProviderInterface
     */
    private ProviderInterface $embedder;
    /**
     * Embedding model to use (overrides provider default).
     *
     * @var string|null
     */
    private ?string $embeddingModel;

    /**
     * Minimum similarity score threshold.
     *
     * @var float
     */
    private float $minScore;

    /**
     * Vector store for similarity search and storage.
     *
     * @var VectorStorageInterface
     */
    private VectorStorageInterface $store;

    /**
     * Creates a new LocalRagProvider instance.
     *
     * @param VectorStorageInterface $store Vector store backend.
     * @param ProviderInterface $embedder Provider for generating embeddings.
     * @param float $minScore Minimum similarity score threshold (0.0 to 1.0).
     * @param string|null $embeddingModel Model name for embeddings (null uses provider default).
     */
    public function __construct(
        VectorStorageInterface $store,
        ProviderInterface $embedder,
        float $minScore = 0.0,
        ?string $embeddingModel = null,
    ) {
        $this->store = $store;
        $this->embedder = $embedder;
        $this->minScore = $minScore;
        $this->embeddingModel = $embeddingModel;
    }

    /**
     * Deletes a document by ID.
     *
     * @param string $id The document ID to delete.
     */
    public function delete(string $id): void {
        $this->store->delete($id);
    }

    /**
     * Returns the embedding model name.
     *
     * @return string|null The model name, or null if using provider default.
     */
    public function getEmbeddingModel(): ?string {
        return $this->embeddingModel;
    }

    /**
     * Returns the minimum score threshold.
     *
     * @return float The minimum score.
     */
    public function getMinScore(): float {
        return $this->minScore;
    }

    /**
     * Returns the vector store instance.
     *
     * @return VectorStorageInterface The vector store.
     */
    public function getStore(): VectorStorageInterface {
        return $this->store;
    }

    /**
     * Ingests content into the RAG store.
     *
     * Generates an embedding for the content and stores it in the vector
     * store with the original text preserved in metadata.
     *
     * @param string $content The text content to ingest.
     * @param array<string, mixed> $metadata Optional metadata.
     *
     * @return string The generated document ID.
     */
    public function ingest(string $content, array $metadata = []): string {
        // 1. Generate ID
        $id = 'doc_'.substr(md5($content.microtime(true)), 0, 12);

        // 2. Embed content
        $embedOptions = [];

        if ($this->embeddingModel !== null) {
            $embedOptions[ChatOption::MODEL] = $this->embeddingModel;
        }

        $vector = $this->embedder->embed($content, $embedOptions)->getVector();

        // 3. Store with text in metadata
        $metadata['text'] = $content;
        $this->store->store($id, $vector, $metadata);

        return $id;
    }

    /**
     * Retrieves relevant documents for a query.
     *
     * Embeds the query using the configured provider, searches the vector
     * store, and returns results above the minimum score threshold.
     *
     * @param string $query The search query.
     * @param int $topK Maximum number of results.
     * @param array<string, mixed> $options Additional options. Supports:
     *        - 'filter': array<string, mixed> metadata filter for vector store.
     *
     * @return RetrievalResult[] Results sorted by relevance descending.
     */
    public function retrieve(string $query, int $topK = 5, array $options = []): array {
        // 1. Embed query
        $embedOptions = [];

        if ($this->embeddingModel !== null) {
            $embedOptions[ChatOption::MODEL] = $this->embeddingModel;
        }

        $vector = $this->embedder->embed($query, $embedOptions)->getVector();

        // 2. Query vector store
        $filter = $options['filter'] ?? [];
        $records = $this->store->query($vector, $topK, $filter);

        // 3. Filter by minScore, convert to RetrievalResult[]
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

        return $results;
    }

    /**
     * Sets the minimum score threshold.
     *
     * Results below this threshold are filtered out.
     *
     * @param float $minScore Minimum score (0.0 to 1.0).
     *
     * @return self For method chaining.
     */
    public function setMinScore(float $minScore): self {
        $this->minScore = $minScore;

        return $this;
    }
}
