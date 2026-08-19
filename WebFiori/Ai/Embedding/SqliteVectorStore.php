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

use InvalidArgumentException;
use RuntimeException;
use SQLite3;

/**
 * SQLite-based persistent vector storage.
 *
 * Uses SQLite for better I/O performance compared to file-per-record storage.
 * Suitable for medium datasets (10,000 to 500,000 vectors). For larger datasets,
 * use a dedicated vector database (Pinecone, Qdrant, pgvector, etc.).
 *
 * Vectors are stored as JSON blobs. Search uses brute-force cosine similarity
 * computed in PHP (SQLite has no native vector operations).
 *
 * Features:
 * - WAL mode for concurrent reads
 * - Metadata filtering via JSON functions
 * - Atomic transactions for batch operations
 *
 * @author Ibrahim
 */
class SqliteVectorStore implements VectorStorageInterface {
    /**
     * Path to the SQLite database file.
     *
     * @var string
     */
    private string $databasePath;
    /**
     * SQLite3 database connection.
     *
     * @var SQLite3
     */
    private SQLite3 $db;

    /**
     * Creates a new SqliteVectorStore instance.
     *
     * @param string $databasePath Path to the SQLite database file. Will be
     *        created if it doesn't exist.
     * @param array<string, mixed> $options Configuration options:
     *        - 'wal_mode': Enable WAL mode for better concurrency (default: true)
     *
     * @throws InvalidArgumentException If path is empty.
     * @throws RuntimeException If database cannot be created or accessed.
     */
    public function __construct(string $databasePath, array $options = []) {
        if ($databasePath === '') {
            throw new InvalidArgumentException('Database path cannot be empty');
        }

        $this->databasePath = $databasePath;

        $dir = dirname($databasePath);

        if ($dir !== '.' && !is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException("Failed to create database directory: {$dir}");
            }
        }

        try {
            $this->db = new SQLite3($databasePath);
            $this->db->enableExceptions(true);

            if ($options['wal_mode'] ?? true) {
                $this->db->exec('PRAGMA journal_mode=WAL');
            }

            $this->db->exec('PRAGMA synchronous=NORMAL');
            $this->db->exec('PRAGMA cache_size=-64000');

            $this->initializeSchema();
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to open database: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Closes the database connection.
     */
    public function __destruct() {
        $this->db->close();
    }

    /**
     * Removes all vectors from the store.
     */
    public function clear(): void {
        $this->db->exec('DELETE FROM vectors');
    }

    /**
     * Returns the number of vectors in the store.
     *
     * @return int The vector count.
     */
    public function count(): int {
        return (int) $this->db->querySingle('SELECT COUNT(*) FROM vectors');
    }

    /**
     * Deletes a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record to delete.
     *
     * @return bool True if the record was deleted, false if it did not exist.
     */
    public function delete(string $id): bool {
        $stmt = $this->db->prepare('DELETE FROM vectors WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $stmt->execute();

        return $this->db->changes() > 0;
    }

    /**
     * Retrieves a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record.
     *
     * @return VectorRecord|null The record, or null if not found.
     */
    public function get(string $id): ?VectorRecord {
        $stmt = $this->db->prepare('SELECT id, vector, metadata FROM vectors WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $result = $stmt->execute();

        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            return null;
        }

        return new VectorRecord(
            $row['id'],
            json_decode($row['vector'], true),
            json_decode($row['metadata'], true) ?: [],
        );
    }

    /**
     * Returns the database file path.
     *
     * @return string The database path.
     */
    public function getDatabasePath(): string {
        return $this->databasePath;
    }

    /**
     * Queries the store for the most similar vectors using cosine similarity.
     *
     * Loads all matching vectors and computes similarity in PHP.
     * Results are sorted by similarity in descending order.
     *
     * @param float[] $vector The query vector to compare against.
     * @param int $topK The maximum number of results to return.
     * @param array<string, mixed> $filter Optional metadata filter. Only records
     *        whose metadata contains all specified key-value pairs are considered.
     *
     * @return VectorRecord[] The most similar records, sorted by score descending.
     */
    public function query(array $vector, int $topK = 10, array $filter = []): array {
        $sql = 'SELECT id, vector, metadata FROM vectors';

        if (count($filter) > 0) {
            $conditions = [];

            foreach ($filter as $key => $value) {
                $escapedPath = SQLite3::escapeString('$.'.$key);

                if (is_string($value)) {
                    $escapedValue = SQLite3::escapeString($value);
                    $conditions[] = "json_extract(metadata, '{$escapedPath}') = '{$escapedValue}'";
                } else {
                    $conditions[] = "json_extract(metadata, '{$escapedPath}') = ".(is_bool($value) ? (int) $value : $value);
                }
            }

            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        $result = $this->db->query($sql);
        $results = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $recordVector = json_decode($row['vector'], true);
            $score = $this->cosineSimilarity($vector, $recordVector);

            $results[] = new VectorRecord(
                $row['id'],
                $recordVector,
                json_decode($row['metadata'], true) ?: [],
                $score,
            );
        }

        usort($results, function (VectorRecord $a, VectorRecord $b): int
        {
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
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO vectors (id, vector, metadata, created_at) VALUES (:id, :vector, :metadata, :created_at)'
        );
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $stmt->bindValue(':vector', json_encode($vector), SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($metadata, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
        $stmt->bindValue(':created_at', time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * Stores multiple vector records in a single transaction.
     *
     * More efficient than calling store() multiple times.
     *
     * @param VectorRecord[] $records An array of VectorRecord instances to store.
     */
    public function storeBatch(array $records): void {
        $this->db->exec('BEGIN TRANSACTION');

        try {
            $stmt = $this->db->prepare(
                'INSERT OR REPLACE INTO vectors (id, vector, metadata, created_at) VALUES (:id, :vector, :metadata, :created_at)'
            );
            $time = time();

            foreach ($records as $record) {
                $stmt->bindValue(':id', $record->getId(), SQLITE3_TEXT);
                $stmt->bindValue(':vector', json_encode($record->getVector()), SQLITE3_TEXT);
                $stmt->bindValue(':metadata', json_encode($record->getMetadata(), JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                $stmt->bindValue(':created_at', $time, SQLITE3_INTEGER);
                $stmt->execute();
                $stmt->reset();
            }

            $this->db->exec('COMMIT');
        } catch (\Exception $e) {
            $this->db->exec('ROLLBACK');

            throw new RuntimeException("Batch store failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Reclaims disk space after deleting records.
     *
     * SQLite does not automatically shrink the database file when records
     * are deleted. Call this method periodically if you delete many records.
     */
    public function vacuum(): void {
        $this->db->exec('VACUUM');
    }

    /**
     * Computes the cosine similarity between two vectors.
     *
     * @param float[] $a First vector.
     * @param float[] $b Second vector.
     *
     * @return float Similarity score between -1 and 1.
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
     * Creates the database schema if it doesn't exist.
     */
    private function initializeSchema(): void {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS vectors (
                id TEXT PRIMARY KEY,
                vector TEXT NOT NULL,
                metadata TEXT NOT NULL DEFAULT "{}",
                created_at INTEGER NOT NULL
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_vectors_created_at ON vectors(created_at)
        ');
    }
}
