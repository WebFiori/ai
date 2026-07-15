<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Embedding;

/**
 * Contract for vector storage backends.
 *
 * Implementations of this interface manage the persistence and retrieval
 * of embedding vectors for similarity search. Developers can implement
 * this interface with any vector database (Pinecone, Qdrant, Weaviate,
 * pgvector, Redis VSS, etc.).
 *
 * @author Ibrahim
 */
interface VectorStorageInterface {
    /**
     * Deletes a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record to delete.
     *
     * @return bool True if the record was deleted, false if it did not exist.
     */
    public function delete(string $id): bool;

    /**
     * Retrieves a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record.
     *
     * @return VectorRecord|null The record, or null if not found.
     */
    public function get(string $id): ?VectorRecord;

    /**
     * Queries the store for the most similar vectors.
     *
     * Returns records sorted by cosine similarity in descending order
     * (most similar first). Each returned record will have its score
     * property set to the cosine similarity value.
     *
     * @param float[] $vector The query vector to compare against.
     * @param int $topK The maximum number of results to return.
     * @param array<string, mixed> $filter Optional metadata filter. Only records
     *        whose metadata contains all specified key-value pairs are considered.
     *
     * @return VectorRecord[] The most similar records, sorted by score descending.
     */
    public function query(array $vector, int $topK = 10, array $filter = []): array;

    /**
     * Stores a single vector record.
     *
     * If a record with the same ID already exists, it is overwritten.
     *
     * @param string $id The unique identifier for this record.
     * @param float[] $vector The embedding vector.
     * @param array<string, mixed> $metadata Optional metadata key-value pairs.
     */
    public function store(string $id, array $vector, array $metadata = []): void;

    /**
     * Stores multiple vector records in a single operation.
     *
     * If any record shares an ID with an existing record, the existing
     * record is overwritten.
     *
     * @param VectorRecord[] $records An array of VectorRecord instances to store.
     */
    public function storeBatch(array $records): void;
}
