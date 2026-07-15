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
 * In-memory vector storage for testing and small datasets.
 *
 * Uses brute-force cosine similarity search. Suitable for unit tests
 * and datasets with fewer than 10,000 vectors. Data is lost when the
 * process ends.
 *
 * For production workloads, implement {@see VectorStorageInterface} with
 * a dedicated vector database (Pinecone, Qdrant, Weaviate, pgvector, etc.).
 *
 * @author Ibrahim
 */
class InMemoryVectorStore implements VectorStorageInterface {
    /**
     * Stored records indexed by ID.
     *
     * @var array<string, VectorRecord>
     */
    private array $records = [];

    /**
     * Returns the number of records currently stored.
     *
     * @return int The record count.
     */
    public function count(): int {
        return count($this->records);
    }

    /**
     * Deletes a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record to delete.
     *
     * @return bool True if the record was deleted, false if it did not exist.
     */
    public function delete(string $id): bool {
        if (!isset($this->records[$id])) {
            return false;
        }

        unset($this->records[$id]);

        return true;
    }

    /**
     * Retrieves a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record.
     *
     * @return VectorRecord|null The record, or null if not found.
     */
    public function get(string $id): ?VectorRecord {
        return $this->records[$id] ?? null;
    }

    /**
     * Queries the store for the most similar vectors using cosine similarity.
     *
     * Returns records sorted by cosine similarity in descending order.
     * Optionally filters by metadata before computing similarity.
     *
     * @param float[] $vector The query vector to compare against.
     * @param int $topK The maximum number of results to return.
     * @param array<string, mixed> $filter Optional metadata filter. Only records
     *        whose metadata contains all specified key-value pairs are considered.
     *
     * @return VectorRecord[] The most similar records, sorted by score descending.
     */
    public function query(array $vector, int $topK = 10, array $filter = []): array {
        $results = [];

        foreach ($this->records as $record) {
            if (!$this->matchesFilter($record, $filter)) {
                continue;
            }

            $score = $this->cosineSimilarity($vector, $record->getVector());
            $results[] = new VectorRecord(
                $record->getId(),
                $record->getVector(),
                $record->getMetadata(),
                $score,
            );
        }

        usort($results, function (VectorRecord $a, VectorRecord $b): int {
            return $b->getScore() <=> $a->getScore();
        });

        return array_slice($results, 0, $topK);
    }

    /**
     * Stores a single vector record.
     *
     * If a record with the same ID already exists, it is overwritten.
     *
     * @param string $id The unique identifier for this record.
     * @param float[] $vector The embedding vector.
     * @param array<string, mixed> $metadata Optional metadata key-value pairs.
     */
    public function store(string $id, array $vector, array $metadata = []): void {
        $this->records[$id] = new VectorRecord($id, $vector, $metadata);
    }

    /**
     * Stores multiple vector records in a single operation.
     *
     * If any record shares an ID with an existing record, the existing
     * record is overwritten.
     *
     * @param VectorRecord[] $records An array of VectorRecord instances to store.
     */
    public function storeBatch(array $records): void {
        foreach ($records as $record) {
            $this->records[$record->getId()] = new VectorRecord(
                $record->getId(),
                $record->getVector(),
                $record->getMetadata(),
            );
        }
    }

    /**
     * Computes the cosine similarity between two vectors.
     *
     * Returns a value between -1 and 1, where 1 means identical direction,
     * 0 means orthogonal, and -1 means opposite direction.
     *
     * @param float[] $a First vector.
     * @param float[] $b Second vector.
     *
     * @return float The cosine similarity score.
     */
    private function cosineSimilarity(array $a, array $b): float {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $length = count($a);

        for ($i = 0; $i < $length; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator == 0.0) {
            return 0.0;
        }

        return $dotProduct / $denominator;
    }

    /**
     * Checks if a record's metadata matches the given filter.
     *
     * A record matches if its metadata contains all key-value pairs
     * specified in the filter.
     *
     * @param VectorRecord $record The record to check.
     * @param array<string, mixed> $filter The filter criteria.
     *
     * @return bool True if the record matches the filter.
     */
    private function matchesFilter(VectorRecord $record, array $filter): bool {
        if (count($filter) === 0) {
            return true;
        }

        $metadata = $record->getMetadata();

        foreach ($filter as $key => $value) {
            if (!array_key_exists($key, $metadata) || $metadata[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
