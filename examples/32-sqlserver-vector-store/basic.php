<?php

/**
 * Example 32: SQL Server Vector Store (CLI)
 *
 * Run: php examples/32-sqlserver-vector-store/basic.php
 *
 * Demonstrates storing and querying embedding vectors in Microsoft SQL Server
 * via SqlServerVectorStore. To keep the example self-contained, it uses
 * synthetic vectors — no AI provider or API key is required, only a reachable
 * SQL Server instance.
 *
 * Start a local server with:
 *   docker compose -f tests/docker/docker-compose.yml up -d
 *
 * Then configure the connection via environment variables:
 *   export MSSQL_SERVER="tcp:localhost,1433"
 *   export MSSQL_UID="sa"
 *   export MSSQL_PWD="Str0ng!Passw0rd"
 *   export MSSQL_DATABASE="master"
 *
 * Demonstrates:
 * 1. Connecting to SQL Server and auto-creating the schema
 * 2. Storing vectors (with metadata) individually and in a batch
 * 3. Querying by cosine similarity
 * 4. Filtering query results by metadata
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Embedding\SqlServerOption;
use WebFiori\Ai\Embedding\SqlServerVectorStore;
use WebFiori\Ai\Embedding\VectorRecord;

if (!function_exists('sqlsrv_connect')) {
    fwrite(STDERR, "The 'sqlsrv' PHP extension is required to run this example.\n");
    fwrite(STDERR, "Install Microsoft's ODBC driver + `pecl install sqlsrv pdo_sqlsrv`, then enable the extension.\n");

    exit(1);
}

$server = getenv('MSSQL_SERVER');

if ($server === false) {
    fwrite(STDERR, "Set MSSQL_SERVER (and MSSQL_UID / MSSQL_PWD / MSSQL_DATABASE) first.\n");
    fwrite(STDERR, "See this example's README for a one-line Docker setup.\n");

    exit(1);
}

$connectionInfo = [
    SqlServerOption::CONN_TRUST_SERVER_CERTIFICATE => true,
];

if (getenv('MSSQL_UID') !== false) {
    $connectionInfo[SqlServerOption::CONN_UID] = getenv('MSSQL_UID');
}

if (getenv('MSSQL_PWD') !== false) {
    $connectionInfo[SqlServerOption::CONN_PWD] = getenv('MSSQL_PWD');
}

if (getenv('MSSQL_DATABASE') !== false) {
    $connectionInfo[SqlServerOption::CONN_DATABASE] = getenv('MSSQL_DATABASE');
}

// Step 1: Connect. AUTO_CREATE is true here for a self-contained demo; in a
// least-privilege production setup, leave it false and provision the table
// out-of-band using SqlServerVectorStore::getSchemaSql().
echo "Connecting to SQL Server...\n";
$store = new SqlServerVectorStore($server, [
    SqlServerOption::CONNECTION_INFO => $connectionInfo,
    SqlServerOption::TABLE => 'demo_vectors',
    SqlServerOption::AUTO_CREATE => true,
]);
$store->clear();
echo "Connected. Table 'demo_vectors' is ready.\n\n";

// Step 2: Store synthetic vectors with metadata.
// (In a real app these come from $client->embed(...).)
$store->store('doc-1', [1.0, 0.0, 0.0], ['title' => 'East',  'topic' => 'direction']);
$store->store('doc-2', [0.0, 1.0, 0.0], ['title' => 'North', 'topic' => 'direction']);

$store->storeBatch([
    new VectorRecord('doc-3', [0.0, 0.0, 1.0], ['title' => 'Up',          'topic' => 'direction']),
    new VectorRecord('doc-4', [0.9, 0.1, 0.0], ['title' => 'Almost East', 'topic' => 'direction']),
    new VectorRecord('doc-5', [0.2, 0.2, 0.9], ['title' => 'Mostly Up',   'topic' => 'other']),
]);

echo 'Stored '.$store->count()." vectors.\n\n";

// Step 3: Query by cosine similarity (nearest to "East").
echo "Top 3 nearest to [1, 0, 0]:\n";
$results = $store->query([1.0, 0.0, 0.0], 3);

foreach ($results as $record) {
    printf("  [%.3f] %s (%s)\n", $record->getScore(), $record->getMetadata()['title'], $record->getId());
}

// Step 4: Query with a metadata filter (only topic = "direction").
echo "\nTop 3 nearest to [1, 0, 0] where topic = 'direction':\n";
$filtered = $store->query([1.0, 0.0, 0.0], 3, ['topic' => 'direction']);

foreach ($filtered as $record) {
    printf("  [%.3f] %s (topic=%s)\n", $record->getScore(), $record->getMetadata()['title'], $record->getMetadata()['topic']);
}

// Cleanup for a repeatable demo.
$store->clear();
echo "\nDone. (Demo rows cleared.)\n";
