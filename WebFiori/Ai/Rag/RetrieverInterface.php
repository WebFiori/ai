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
 * Contract for retrieval implementations.
 *
 * Implementations handle query embedding, vector search, and result formatting.
 * This interface enables mocking in tests and swapping retrieval strategies
 * (e.g., simple vector search vs. hybrid search).
 *
 * @author Ibrahim
 */
interface RetrieverInterface {
    /**
     * Retrieves relevant chunks for a query.
     *
     * Embeds the query, searches the vector store, and returns matching chunks
     * sorted by relevance.
     *
     * @param string $query The search query.
     * @param int $topK Maximum number of results to return.
     * @param array<string, mixed> $filter Optional metadata filter.
     *
     * @return RetrievalResult[] Retrieved results sorted by score descending.
     */
    public function retrieve(string $query, int $topK = 5, array $filter = []): array;
}
