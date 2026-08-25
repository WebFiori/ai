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

/**
 * Contract for RAG (Retrieval-Augmented Generation) providers.
 *
 * Implementations handle document retrieval, ingestion, and deletion.
 * This interface enables swapping between local vector-based retrieval
 * and external RAG services (e.g., managed vector databases, search APIs).
 *
 * @author Ibrahim
 */
interface RagProviderInterface {
    /**
     * Deletes a document by ID.
     *
     * @param string $id The document ID to delete.
     *
     * @throws \WebFiori\Ai\Exception\UnsupportedFeatureException If the provider
     *         doesn't support deletion.
     */
    public function delete(string $id): void;

    /**
     * Ingests content into the RAG store.
     *
     * @param string $content The text content to ingest.
     * @param array<string, mixed> $metadata Optional metadata.
     *
     * @return string The document/record ID.
     *
     * @throws \WebFiori\Ai\Exception\UnsupportedFeatureException If the provider
     *         doesn't support ingestion.
     */
    public function ingest(string $content, array $metadata = []): string;
    /**
     * Retrieves relevant documents for a query.
     *
     * @param string $query The search query.
     * @param int $topK Maximum number of results.
     * @param array<string, mixed> $options Additional provider-specific options.
     *
     * @return RetrievalResult[] Results sorted by relevance descending.
     */
    public function retrieve(string $query, int $topK = 5, array $options = []): array;
}
