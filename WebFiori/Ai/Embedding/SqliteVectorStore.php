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
use PDO;
use PDOException;
use RuntimeException;

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
     * PDO database connection.
     *
     * @var PDO
     */
    private PDO $db;

    /**
     * Path to the SQLite database file.
     *
     * @var string
     */
    private string $databasePath;

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

        // Ensure directory exists
        $dir = dirname($databasePath);

        if ($dir !== '.' && !is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException("Failed to create database directory: {$dir}");
            }
        }

        try {
            $this->db = new PDO("sqlite:{$databasePath}");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Enable WAL mode for better concurrent access
            if ($options['wal_mode'] ?? true) {
                $this->db->exec('PRAGMA journal_mode=WAL');
            }

            // Performance tunings
            $this->db->exec('PRAGMA synchronous=NORMAL');
            $this->db->exec('PRAGMA cache_size=-64000'); // 64MB cache

            $this->initializeSchema();
        } catch (PDOException $e) {
            throw new RuntimeException("Failed to open database: {$e->getMessage()}", 0, $e);
        }
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
        $stmt = $this->db->query('SELECT COUNT(*) FROM vectors');

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record to delete.
     *
     * @return bool True if the record was deleted, false if it did not exist.
     */
    public function delete(string $id): bool {
        $stmt = $this->db->prepare('DELETE FROM vectors WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Retrieves a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record.
     *
     * @return VectorRecord|null The record, or null if not found.
     */
    public function get(string $id): ?VectorRecord {
        $stmt = $this->db->prepare('SELECT id, vector, metadata FROM vectors WHERE id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

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
        // Build query with optional metadata filters
        $sql = 'SELECT id, vector, metadata FROM vectors';
        $params = [];
        $paramIndex = 0;

        if (count($filter) > 0) {
            $conditions = [];

            foreach ($filter as $key => $value) {
                // json_extract returns unquoted strings and raw numbers
                // We use named parameters to ensure proper type binding
                $paramName = ':p' . $paramIndex++;
                $pathName = ':path' . $paramIndex++;
                $conditions[] = "json_extract(metadata, {$pathName}) = {$paramName}";
                $params[$pathName] = '$.' . $key;
                $params[$paramName] = $value;
            }

            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $this->db->prepare($sql);

        // Bind with explicit types
        foreach ($params as $name => $value) {
            if (is_int($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_BOOL);
            } else {
                $stmt->bindValue($name, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();

        $results = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $recordVector = json_decode($row['vector'], true);
            $score = $this->cosineSimilarity($vector, $recordVector);

            $results[] = new VectorRecord(
                $row['id'],
                $recordVector,
                json_decode($row['metadata'], true) ?: [],
                $score,
            );
        }

        // Sort by score descending
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
        $sql = 'INSERT OR REPLACE INTO vectors (id, vector, metadata, created_at) VALUES (?, ?, ?, ?)';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $id,
            json_encode($vector),
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
            time(),
        ]);
    }

    /**
     * Stores multiple vector records in a single transaction.
     *
     * More efficient than calling store() multiple times.
     *
     * @param VectorRecord[] $records An array of VectorRecord instances to store.
     */
    public function storeBatch(array $records): void {
        $this->db->beginTransaction();

        try {
            $sql = 'INSERT OR REPLACE INTO vectors (id, vector, metadata, created_at) VALUES (?, ?, ?, ?)';
            $stmt = $this->db->prepare($sql);
            $time = time();

            foreach ($records as $record) {
                $stmt->execute([
                    $record->getId(),
                    json_encode($record->getVector()),
                    json_encode($record->getMetadata(), JSON_UNESCAPED_UNICODE),
                    $time,
                ]);
            }

            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();

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

        // Index for faster metadata filtering (SQLite JSON functions)
        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_vectors_created_at ON vectors(created_at)
        ');
    }
}
