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

/**
 * File-based persistent vector storage.
 *
 * Stores vectors as individual JSON files with a manifest index for fast
 * lookups. Suitable for small to medium datasets (up to ~10,000 vectors).
 * For larger datasets, use {@see SqliteVectorStore} or a dedicated vector
 * database.
 *
 * File layout:
 * ```
 * /storage-path/
 * ├── manifest.json       # Index mapping IDs to filenames and metadata
 * └── vectors/
 *     ├── {hash1}.json    # Individual vector records
 *     ├── {hash2}.json
 *     └── ...
 * ```
 *
 * Thread safety: Uses `flock()` for manifest writes to handle concurrent
 * requests safely. Vector files are written atomically via rename.
 *
 * @author Ibrahim
 */
class FileVectorStore implements VectorStorageInterface {
    /**
     * Manifest data: id => ['file' => filename, 'metadata' => [...]]
     *
     * @var array<string, array{file: string, metadata: array<string, mixed>}>
     */
    private array $manifest = [];

    /**
     * Path to the manifest file.
     *
     * @var string
     */
    private string $manifestPath;

    /**
     * Base storage directory path.
     *
     * @var string
     */
    private string $storagePath;

    /**
     * Path to the vectors subdirectory.
     *
     * @var string
     */
    private string $vectorsPath;

    /**
     * Creates a new FileVectorStore instance.
     *
     * @param string $storagePath Base directory for storage. Will be created
     *        if it doesn't exist and $createIfMissing is true.
     * @param bool $createIfMissing Whether to create the directory if missing.
     *
     * @throws InvalidArgumentException If path is empty.
     * @throws RuntimeException If directory cannot be created or accessed.
     */
    public function __construct(string $storagePath, bool $createIfMissing = true) {
        if ($storagePath === '') {
            throw new InvalidArgumentException('Storage path cannot be empty');
        }

        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR);
        $this->vectorsPath = $this->storagePath.DIRECTORY_SEPARATOR.'vectors';
        $this->manifestPath = $this->storagePath.DIRECTORY_SEPARATOR.'manifest.json';

        if (!is_dir($this->storagePath)) {
            if (!$createIfMissing) {
                throw new RuntimeException("Storage directory does not exist: {$this->storagePath}");
            }

            if (!mkdir($this->storagePath, 0755, true)) {
                throw new RuntimeException("Failed to create storage directory: {$this->storagePath}");
            }
        }

        if (!is_dir($this->vectorsPath)) {
            if (!mkdir($this->vectorsPath, 0755, true)) {
                throw new RuntimeException("Failed to create vectors directory: {$this->vectorsPath}");
            }
        }

        $this->loadManifest();
    }

    /**
     * Removes all vectors from the store.
     *
     * Deletes all vector files and resets the manifest.
     */
    public function clear(): void {
        foreach ($this->manifest as $entry) {
            $filePath = $this->vectorsPath.DIRECTORY_SEPARATOR.$entry['file'];

            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $this->manifest = [];
        $this->saveManifest();
    }

    /**
     * Returns the number of vectors in the store.
     *
     * @return int The vector count.
     */
    public function count(): int {
        return count($this->manifest);
    }

    /**
     * Deletes a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record to delete.
     *
     * @return bool True if the record was deleted, false if it did not exist.
     */
    public function delete(string $id): bool {
        if (!isset($this->manifest[$id])) {
            return false;
        }

        $filePath = $this->vectorsPath.DIRECTORY_SEPARATOR.$this->manifest[$id]['file'];

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        unset($this->manifest[$id]);
        $this->saveManifest();

        return true;
    }

    /**
     * Retrieves a vector record by its identifier.
     *
     * Loads the vector data from disk on demand (lazy loading).
     *
     * @param string $id The unique identifier of the record.
     *
     * @return VectorRecord|null The record, or null if not found.
     */
    public function get(string $id): ?VectorRecord {
        if (!isset($this->manifest[$id])) {
            return null;
        }

        return $this->loadVector($id);
    }

    /**
     * Returns the storage directory path.
     *
     * @return string The base storage path.
     */
    public function getStoragePath(): string {
        return $this->storagePath;
    }

    /**
     * Queries the store for the most similar vectors using cosine similarity.
     *
     * Loads vectors from disk as needed and computes similarity scores.
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
        $results = [];

        foreach ($this->manifest as $id => $entry) {
            // Filter by metadata before loading vector (optimization)
            if (!$this->matchesFilter($entry['metadata'], $filter)) {
                continue;
            }

            $record = $this->loadVector($id);

            if ($record === null) {
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
        $filename = $this->generateFilename($id);
        $filePath = $this->vectorsPath.DIRECTORY_SEPARATOR.$filename;

        // Delete old file if ID exists with different filename
        if (isset($this->manifest[$id]) && $this->manifest[$id]['file'] !== $filename) {
            $oldPath = $this->vectorsPath.DIRECTORY_SEPARATOR.$this->manifest[$id]['file'];

            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        $data = [
            'id' => $id,
            'vector' => $vector,
            'metadata' => $metadata,
        ];

        $this->writeFileAtomic($filePath, json_encode($data, JSON_UNESCAPED_UNICODE));

        $this->manifest[$id] = [
            'file' => $filename,
            'metadata' => $metadata,
        ];

        $this->saveManifest();
    }

    /**
     * Stores multiple vector records in a single operation.
     *
     * More efficient than calling store() multiple times as manifest
     * is only saved once at the end.
     *
     * @param VectorRecord[] $records An array of VectorRecord instances to store.
     */
    public function storeBatch(array $records): void {
        foreach ($records as $record) {
            $id = $record->getId();
            $filename = $this->generateFilename($id);
            $filePath = $this->vectorsPath.DIRECTORY_SEPARATOR.$filename;

            // Delete old file if ID exists with different filename
            if (isset($this->manifest[$id]) && $this->manifest[$id]['file'] !== $filename) {
                $oldPath = $this->vectorsPath.DIRECTORY_SEPARATOR.$this->manifest[$id]['file'];

                if (file_exists($oldPath)) {
                    unlink($oldPath);
                }
            }

            $data = [
                'id' => $id,
                'vector' => $record->getVector(),
                'metadata' => $record->getMetadata(),
            ];

            $this->writeFileAtomic($filePath, json_encode($data, JSON_UNESCAPED_UNICODE));

            $this->manifest[$id] = [
                'file' => $filename,
                'metadata' => $record->getMetadata(),
            ];
        }

        $this->saveManifest();
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
     * Generates a filename for a vector record.
     *
     * @param string $id The record ID.
     *
     * @return string The filename (without path).
     */
    private function generateFilename(string $id): string {
        return md5($id).'.json';
    }

    /**
     * Loads the manifest from disk.
     */
    private function loadManifest(): void {
        if (!file_exists($this->manifestPath)) {
            $this->manifest = [];

            return;
        }

        $content = file_get_contents($this->manifestPath);

        if ($content === false) {
            throw new RuntimeException("Failed to read manifest: {$this->manifestPath}");
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            $this->manifest = [];

            return;
        }

        $this->manifest = $data;
    }

    /**
     * Loads a vector record from disk.
     *
     * @param string $id The record ID.
     *
     * @return VectorRecord|null The record, or null if file is missing/corrupt.
     */
    private function loadVector(string $id): ?VectorRecord {
        if (!isset($this->manifest[$id])) {
            return null;
        }

        $filePath = $this->vectorsPath.DIRECTORY_SEPARATOR.$this->manifest[$id]['file'];

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['id'], $data['vector'])) {
            return null;
        }

        return new VectorRecord(
            $data['id'],
            $data['vector'],
            $data['metadata'] ?? [],
        );
    }

    /**
     * Checks if metadata matches the given filter.
     *
     * @param array<string, mixed> $metadata The metadata to check.
     * @param array<string, mixed> $filter The filter criteria.
     *
     * @return bool True if metadata matches all filter criteria.
     */
    private function matchesFilter(array $metadata, array $filter): bool {
        if (count($filter) === 0) {
            return true;
        }

        foreach ($filter as $key => $value) {
            if (!array_key_exists($key, $metadata) || $metadata[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Saves the manifest to disk with file locking.
     */
    private function saveManifest(): void {
        $content = json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tempPath = $this->manifestPath.'.tmp';

        if (file_put_contents($tempPath, $content) === false) {
            throw new RuntimeException("Failed to write manifest temp file: {$tempPath}");
        }

        $handle = fopen($this->manifestPath.'.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException("Failed to create manifest lock file");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Failed to acquire manifest lock");
            }

            if (!rename($tempPath, $this->manifestPath)) {
                throw new RuntimeException("Failed to rename manifest file");
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Writes a file atomically using a temp file and rename.
     *
     * @param string $path Target file path.
     * @param string $content File content.
     */
    private function writeFileAtomic(string $path, string $content): void {
        $tempPath = $path.'.tmp';

        if (file_put_contents($tempPath, $content) === false) {
            throw new RuntimeException("Failed to write temp file: {$tempPath}");
        }

        if (!rename($tempPath, $path)) {
            throw new RuntimeException("Failed to rename file: {$path}");
        }
    }
}
