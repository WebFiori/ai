# 32 — SQL Server Vector Store

Store and query embedding vectors in Microsoft SQL Server using
`SqlServerVectorStore`, a `VectorStorageInterface` implementation backed by the
`sqlsrv` extension. Useful when your application is standardized on SQL Server
and you want RAG / agent-memory vectors in the same database (one source of
truth, covered by your existing backup / HA / monitoring) instead of per-host
SQLite files.

See [ADR-0058](https://github.com/WebFiori/docs/blob/main/adr/0058-ai-sqlserver-vector-store.md)
for the design rationale.

## What It Demonstrates

- Connecting to SQL Server and (optionally) auto-creating the schema
- Storing vectors with metadata, individually and via `storeBatch()`
- Querying by cosine similarity
- Filtering query results by metadata (pushed into the SQL `WHERE` clause)

The example uses **synthetic vectors**, so no AI provider or API key is needed —
only a reachable SQL Server instance.

## Requirements

- **SQL Server 2016+** (needs `JSON_VALUE` for metadata filtering).
- The PHP **`sqlsrv`** extension and Microsoft's **ODBC Driver 17/18**.

Install the driver + extension (Debian/Ubuntu example):

```bash
# Microsoft ODBC driver (see Microsoft docs for your distro)
sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev
# PHP extension
sudo pecl install sqlsrv pdo_sqlsrv
# then enable extension=sqlsrv in your php.ini
```

## Running

Start a local SQL Server with the provided compose file:

```bash
docker compose -f tests/docker/docker-compose.yml up -d
```

Configure the connection and run:

```bash
export MSSQL_SERVER="tcp:localhost,1433"
export MSSQL_UID="sa"
export MSSQL_PWD="Str0ng!Passw0rd"
export MSSQL_DATABASE="master"

php examples/32-sqlserver-vector-store/basic.php
```

## Files

| File | Description |
|------|-------------|
| `basic.php` | CLI script — connect, store, query, and metadata-filter with synthetic vectors |

## Using it with RAG (LocalRagProvider)

`SqlServerVectorStore` is a drop-in `VectorStorageInterface`, so it plugs into
`LocalRagProvider` (see [example 27](../27-rag-providers/)) exactly like
`SqliteVectorStore` — just swap the store:

```php
use WebFiori\Ai\Embedding\SqlServerOption;
use WebFiori\Ai\Embedding\SqlServerVectorStore;
use WebFiori\Ai\Rag\LocalRagProvider;

$store = new SqlServerVectorStore('tcp:localhost,1433', [
    SqlServerOption::CONNECTION_INFO => [
        SqlServerOption::CONN_DATABASE => 'app_db',
        SqlServerOption::CONN_UID      => 'app_user',
        SqlServerOption::CONN_PWD      => '...',
    ],
]);

$rag = new LocalRagProvider(store: $store, embedder: $client);
```

## Production note — schema provisioning

`AUTO_CREATE` defaults to `false`. The recommended deployment uses a dedicated,
least-privilege SQL Server account that typically lacks DDL rights, so the store
does not create its table by default. Provision it once (as a privileged user)
using the DDL helper:

```php
echo SqlServerVectorStore::getSchemaSql('vectors');
// Run the printed CREATE TABLE / CREATE INDEX via your migration tool or DBA.
```

Then run the app with `AUTO_CREATE => false` (the default). The example sets
`AUTO_CREATE => true` only so it is self-contained.
