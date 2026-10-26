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
 * SQL Server-backed persistent vector storage.
 *
 * Stores vectors centrally in Microsoft SQL Server (2016+) using the `sqlsrv`
 * extension, so RAG and agent-memory vectors can live in the same RDBMS as the
 * rest of the application instead of in per-host SQLite files.
 *
 * Vectors are stored as NVARCHAR(MAX) JSON (parity with {@see SqliteVectorStore}).
 * Metadata equality filters are pushed into the SQL WHERE clause using
 * JSON_VALUE() so only candidate rows cross the wire; cosine similarity is then
 * computed in PHP. Server-side scoring via the native VECTOR type
 * (SQL Server 2025+) is intentionally out of scope for this version.
 *
 * See ADR-0058 for the full design rationale.
 *
 * @author Ibrahim
 */
class SqlServerVectorStore implements VectorStorageInterface {
    /**
     * The sqlsrv connection resource.
     *
     * @var resource
     */
    private $connection;
    /**
     * The quoted/validated table name used in queries.
     *
     * @var string
     */
    private string $table;

    /**
     * Creates a new SqlServerVectorStore instance.
     *
     * The store opens and owns its own connection; it is closed on destruction.
     *
     * @param string $serverName The server string passed to sqlsrv_connect(),
     *        e.g. "tcp:localhost,1433".
     * @param array<string, mixed> $options Configuration options keyed by
     *        {@see SqlServerOption} constants:
     *        - CONNECTION_INFO: array passed verbatim to sqlsrv_connect().
     *        - TABLE: table name (default "vectors").
     *        - AUTO_CREATE: issue idempotent DDL to create the table (default false).
     *
     * @throws InvalidArgumentException If the server name or table name is invalid.
     * @throws RuntimeException If the sqlsrv extension is missing or the
     *         connection/schema setup fails.
     */
    public function __construct(string $serverName, array $options = []) {
        if ($serverName === '') {
            throw new InvalidArgumentException('Server name cannot be empty');
        }

        if (!function_exists('sqlsrv_connect')) {
            throw new RuntimeException('The "sqlsrv" PHP extension is required by SqlServerVectorStore.');
        }

        $this->table = $this->validateTableName($options[SqlServerOption::TABLE] ?? 'vectors');

        $connectionInfo = $options[SqlServerOption::CONNECTION_INFO] ?? [];

        if (!is_array($connectionInfo)) {
            throw new InvalidArgumentException('CONNECTION_INFO must be an array.');
        }

        $conn = sqlsrv_connect($serverName, $connectionInfo);

        if ($conn === false) {
            throw new RuntimeException('Failed to connect to SQL Server: '.$this->lastErrorMessage());
        }

        $this->connection = $conn;

        if ($options[SqlServerOption::AUTO_CREATE] ?? false) {
            $this->createSchema();
        }
    }

    /**
     * Closes the database connection.
     */
    public function __destruct() {
        if (is_resource($this->connection)) {
            sqlsrv_close($this->connection);
        }
    }

    /**
     * Removes all vectors from the store.
     */
    public function clear(): void {
        $this->exec('DELETE FROM '.$this->table);
    }

    /**
     * Returns the number of vectors in the store.
     *
     * @return int The vector count.
     */
    public function count(): int {
        $stmt = $this->exec('SELECT COUNT(*) AS c FROM '.$this->table);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Deletes a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record to delete.
     *
     * @return bool True if a record was deleted, false if it did not exist.
     */
    public function delete(string $id): bool {
        $stmt = $this->exec('DELETE FROM '.$this->table.' WHERE id = ?', [$id]);
        $affected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return $affected > 0;
    }

    /**
     * Retrieves a vector record by its identifier.
     *
     * @param string $id The unique identifier of the record.
     *
     * @return VectorRecord|null The record, or null if not found.
     */
    public function get(string $id): ?VectorRecord {
        $stmt = $this->exec(
            'SELECT id, vector, metadata FROM '.$this->table.' WHERE id = ?',
            [$id]
        );
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($row === null || $row === false) {
            return null;
        }

        return new VectorRecord(
            $row['id'],
            json_decode($row['vector'], true),
            json_decode($row['metadata'], true) ?: [],
        );
    }

    /**
     * Returns the DDL used to provision the store's table and index.
     *
     * Useful for provisioning the schema out-of-band (e.g. by a DBA or a
     * privileged setup command) when AUTO_CREATE is left false.
     *
     * @param string $table The table name. Defaults to "vectors".
     *
     * @return string The CREATE TABLE + CREATE INDEX statements.
     *
     * @throws InvalidArgumentException If the table name is invalid.
     */
    public static function getSchemaSql(string $table = 'vectors'): string {
        $table = self::validateTableNameStatic($table);

        return "IF OBJECT_ID(N'".$table."', N'U') IS NULL\n"
            ."BEGIN\n"
            ."    CREATE TABLE ".$table." (\n"
            ."        id         NVARCHAR(255) NOT NULL PRIMARY KEY,\n"
            ."        vector     NVARCHAR(MAX) NOT NULL,\n"
            ."        metadata   NVARCHAR(MAX) NOT NULL CONSTRAINT DF_".$table."_metadata DEFAULT '{}',\n"
            ."        created_at BIGINT        NOT NULL\n"
            ."    );\n"
            ."    CREATE INDEX idx_".$table."_created_at ON ".$table."(created_at);\n"
            ."END";
    }

    /**
     * Queries the store for the most similar vectors using cosine similarity.
     *
     * The metadata filter (equality pairs, AND-combined, over top-level keys) is
     * pushed into the WHERE clause via JSON_VALUE so only candidate rows are
     * loaded; cosine similarity is then computed in PHP and the results are
     * sorted by score descending.
     *
     * @param float[] $vector The query vector to compare against.
     * @param int $topK The maximum number of results to return.
     * @param array<string, mixed> $filter Optional metadata filter. Only records
     *        whose metadata contains all specified key-value pairs are considered.
     *
     * @return VectorRecord[] The most similar records, sorted by score descending.
     */
    public function query(array $vector, int $topK = 10, array $filter = []): array {
        $sql = 'SELECT id, vector, metadata FROM '.$this->table;
        $params = [];

        if (count($filter) > 0) {
            $conditions = [];

            foreach ($filter as $key => $value) {
                $path = '$.'.str_replace(['\\', '\''], ['\\\\', '\'\''], (string) $key);
                $conditions[] = "JSON_VALUE(metadata, '".$path."') = ?";

                if (is_bool($value)) {
                    $params[] = (string) (int) $value;
                } else {
                    $params[] = (string) $value;
                }
            }

            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        $stmt = $this->exec($sql, $params);
        $results = [];

        while (($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) !== null && $row !== false) {
            $recordVector = json_decode($row['vector'], true);
            $score = $this->cosineSimilarity($vector, $recordVector);

            $results[] = new VectorRecord(
                $row['id'],
                $recordVector,
                json_decode($row['metadata'], true) ?: [],
                $score,
            );
        }
        sqlsrv_free_stmt($stmt);

        usort($results, function (VectorRecord $a, VectorRecord $b): int
        {
            return $b->getScore() <=> $a->getScore();
        });

        return array_slice($results, 0, $topK);
    }

    /**
     * Stores a single vector record.
     *
     * If a record with the same ID already exists, it is overwritten
     * (UPDATE-then-INSERT within a transaction).
     *
     * @param string $id The unique identifier for this record.
     * @param float[] $vector The embedding vector.
     * @param array<string, mixed> $metadata Optional metadata key-value pairs.
     */
    public function store(string $id, array $vector, array $metadata = []): void {
        $this->transactional(function () use ($id, $vector, $metadata): void
        {
            $this->upsert($id, $vector, $metadata, time());
        });
    }

    /**
     * Stores multiple vector records in a single transaction.
     *
     * @param VectorRecord[] $records An array of VectorRecord instances to store.
     */
    public function storeBatch(array $records): void {
        $this->transactional(function () use ($records): void
        {
            $time = time();

            foreach ($records as $record) {
                $this->upsert(
                    $record->getId(),
                    $record->getVector(),
                    $record->getMetadata(),
                    $time
                );
            }
        });
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
     * Creates the table/index if they do not already exist (idempotent DDL).
     */
    private function createSchema(): void {
        $this->exec(self::getSchemaSql($this->table));
    }

    /**
     * Prepares, executes, and returns a statement, throwing on failure.
     *
     * @param string $sql The SQL statement.
     * @param array<int, mixed> $params Positional parameters.
     *
     * @return resource The executed statement resource.
     *
     * @throws RuntimeException If the query fails.
     */
    private function exec(string $sql, array $params = []) {
        $stmt = sqlsrv_query($this->connection, $sql, $params);

        if ($stmt === false) {
            throw new RuntimeException('SQL Server query failed: '.$this->lastErrorMessage());
        }

        return $stmt;
    }

    /**
     * Returns a human-readable string from the most recent sqlsrv errors.
     *
     * @return string The concatenated error messages, or 'unknown error'.
     */
    private function lastErrorMessage(): string {
        $errors = sqlsrv_errors();

        if ($errors === null || $errors === []) {
            return 'unknown error';
        }

        $messages = array_map(static fn (array $e): string => $e['message'] ?? '', $errors);

        return implode('; ', array_filter($messages));
    }

    /**
     * Runs a callback inside a transaction, committing on success and rolling
     * back on failure.
     *
     * @param callable $work The unit of work to execute.
     *
     * @throws RuntimeException If the transaction cannot be managed or the work
     *         fails.
     */
    private function transactional(callable $work): void {
        if (sqlsrv_begin_transaction($this->connection) === false) {
            throw new RuntimeException('Failed to begin transaction: '.$this->lastErrorMessage());
        }

        try {
            $work();
            sqlsrv_commit($this->connection);
        } catch (\Throwable $e) {
            sqlsrv_rollback($this->connection);

            throw new RuntimeException('Store operation failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Performs an UPDATE-then-INSERT upsert for a single record.
     *
     * @param string $id The record identifier.
     * @param float[] $vector The embedding vector.
     * @param array<string, mixed> $metadata The metadata.
     * @param int $time The created_at timestamp.
     */
    private function upsert(string $id, array $vector, array $metadata, int $time): void {
        $vectorJson = json_encode($vector);
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE);

        $update = $this->exec(
            'UPDATE '.$this->table.' SET vector = ?, metadata = ?, created_at = ? WHERE id = ?',
            [$vectorJson, $metadataJson, $time, $id]
        );
        $affected = sqlsrv_rows_affected($update);
        sqlsrv_free_stmt($update);

        if ($affected <= 0) {
            $insert = $this->exec(
                'INSERT INTO '.$this->table.' (id, vector, metadata, created_at) VALUES (?, ?, ?, ?)',
                [$id, $vectorJson, $metadataJson, $time]
            );
            sqlsrv_free_stmt($insert);
        }
    }

    /**
     * Validates a table name (instance helper).
     *
     * @param mixed $table The candidate table name.
     *
     * @return string The validated table name.
     *
     * @throws InvalidArgumentException If the name is not a valid identifier.
     */
    private function validateTableName($table): string {
        if (!is_string($table)) {
            throw new InvalidArgumentException('Table name must be a string.');
        }

        return self::validateTableNameStatic($table);
    }

    /**
     * Validates a SQL Server table identifier.
     *
     * Only letters, digits, and underscores are allowed (optionally with a
     * single schema prefix), preventing SQL injection via the table name since
     * it cannot be parameterized.
     *
     * @param string $table The candidate table name.
     *
     * @return string The validated table name.
     *
     * @throws InvalidArgumentException If the name is not a valid identifier.
     */
    private static function validateTableNameStatic(string $table): string {
        if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException(
                'Invalid table name: must be a valid SQL identifier (letters, digits, underscore).'
            );
        }

        return $table;
    }
}
