<?php
namespace WebFiori\Tests\Ai\Embedding;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Embedding\SqlServerOption;
use WebFiori\Ai\Embedding\SqlServerVectorStore;
use WebFiori\Ai\Embedding\VectorRecord;

/**
 * Integration tests for SqlServerVectorStore.
 *
 * These tests require:
 *   - the PHP `sqlsrv` extension, and
 *   - a reachable SQL Server instance configured via environment variables.
 *
 * They are SKIPPED (not failed) when either is absent, so the normal unit-test
 * run stays green without a database. To run them locally:
 *
 *   docker compose -f tests/docker/docker-compose.yml up -d
 *   export MSSQL_SERVER="tcp:localhost,1433"
 *   export MSSQL_UID="sa"
 *   export MSSQL_PWD="Str0ng!Passw0rd"
 *   export MSSQL_DATABASE="master"
 *   composer test
 */
class SqlServerVectorStoreTest extends TestCase {
    private string $table = '';

    public function testClear(): void {
        $store = $this->newStore();
        $store->store('id1', [0.1]);
        $store->store('id2', [0.2]);
        $this->assertSame(2, $store->count());

        $store->clear();
        $this->assertSame(0, $store->count());
        $this->assertNull($store->get('id1'));
    }

    public function testCount(): void {
        $store = $this->newStore();
        $this->assertSame(0, $store->count());

        $store->store('id1', [0.1]);
        $this->assertSame(1, $store->count());

        $store->store('id2', [0.2]);
        $this->assertSame(2, $store->count());

        $store->delete('id1');
        $this->assertSame(1, $store->count());
    }

    public function testDelete(): void {
        $store = $this->newStore();
        $store->store('id1', [0.1, 0.2]);
        $store->store('id2', [0.3, 0.4]);
        $this->assertSame(2, $store->count());

        $this->assertTrue($store->delete('id1'));
        $this->assertSame(1, $store->count());
        $this->assertNull($store->get('id1'));
        $this->assertNotNull($store->get('id2'));
    }

    public function testDeleteReturnsFalseForMissingId(): void {
        $store = $this->newStore();
        $this->assertFalse($store->delete('nonexistent'));
    }

    public function testQueryReturnsTopKResults(): void {
        $store = $this->newStore();
        $store->store('id1', [1.0, 0.0, 0.0], ['name' => 'east']);
        $store->store('id2', [0.0, 1.0, 0.0], ['name' => 'north']);
        $store->store('id3', [0.0, 0.0, 1.0], ['name' => 'up']);
        $store->store('id4', [0.9, 0.1, 0.0], ['name' => 'almost-east']);

        $results = $store->query([1.0, 0.0, 0.0], 2);

        $this->assertCount(2, $results);
        $this->assertSame('id1', $results[0]->getId());
        $this->assertSame('id4', $results[1]->getId());
        $this->assertEqualsWithDelta(1.0, $results[0]->getScore(), 0.001);
    }

    public function testQueryWithMetadataFilter(): void {
        $store = $this->newStore();
        $store->store('id1', [1.0, 0.0], ['lang' => 'en']);
        $store->store('id2', [0.9, 0.1], ['lang' => 'fr']);
        $store->store('id3', [0.8, 0.2], ['lang' => 'en']);

        $results = $store->query([1.0, 0.0], 10, ['lang' => 'en']);

        $this->assertCount(2, $results);

        foreach ($results as $r) {
            $this->assertSame('en', $r->getMetadata()['lang']);
        }
    }

    public function testStoreAndGet(): void {
        $store = $this->newStore();
        $vector = [0.1, 0.2, 0.3, 0.4, 0.5];
        $metadata = ['source' => 'test.pdf', 'page' => 1];

        $store->store('v1', $vector, $metadata);
        $record = $store->get('v1');

        $this->assertNotNull($record);
        $this->assertSame('v1', $record->getId());
        $this->assertEqualsWithDelta($vector, $record->getVector(), 0.0);
        $this->assertSame('test.pdf', $record->getMetadata()['source']);
    }

    public function testStoreBatch(): void {
        $store = $this->newStore();
        $store->storeBatch([
            new VectorRecord('id1', [0.1, 0.2], ['source' => 'a.pdf']),
            new VectorRecord('id2', [0.3, 0.4], ['source' => 'b.pdf']),
            new VectorRecord('id3', [0.5, 0.6], ['source' => 'c.pdf']),
        ]);

        $this->assertSame(3, $store->count());
        $this->assertSame('b.pdf', $store->get('id2')->getMetadata()['source']);
    }

    public function testStoreOverwritesExisting(): void {
        $store = $this->newStore();
        $store->store('v1', [0.1, 0.2], ['version' => 1]);
        $store->store('v1', [0.3, 0.4], ['version' => 2]);

        $record = $store->get('v1');
        $this->assertEqualsWithDelta([0.3, 0.4], $record->getVector(), 0.0);
        $this->assertSame(2, $record->getMetadata()['version']);
        $this->assertSame(1, $store->count());
    }

    /**
     * Builds the sqlsrv connection-info array from env vars.
     *
     * @return array<string, mixed>
     */
    private function connectionInfo(): array {
        $info = [];
        $uid = getenv('MSSQL_UID');
        $pwd = getenv('MSSQL_PWD');
        $db = getenv('MSSQL_DATABASE');

        if ($uid !== false) {
            $info[SqlServerOption::CONN_UID] = $uid;
        }

        if ($pwd !== false) {
            $info[SqlServerOption::CONN_PWD] = $pwd;
        }

        if ($db !== false) {
            $info[SqlServerOption::CONN_DATABASE] = $db;
        }
        // Dev containers use self-signed certs.
        $info[SqlServerOption::CONN_TRUST_SERVER_CERTIFICATE] = true;

        return $info;
    }

    /**
     * Creates a store bound to the per-test table.
     *
     * @param bool $autoCreate Whether to create the table on construction.
     */
    private function newStore(bool $autoCreate = false): SqlServerVectorStore {
        return new SqlServerVectorStore((string) getenv('MSSQL_SERVER'), [
            SqlServerOption::CONNECTION_INFO => $this->connectionInfo(),
            SqlServerOption::TABLE => $this->table,
            SqlServerOption::AUTO_CREATE => $autoCreate,
        ]);
    }

    protected function setUp(): void {
        if (!function_exists('sqlsrv_connect')) {
            $this->markTestSkipped('The sqlsrv extension is not installed.');
        }

        if (getenv('MSSQL_SERVER') === false) {
            $this->markTestSkipped('MSSQL_SERVER is not set; skipping SQL Server integration tests.');
        }

        // Unique per-test table so runs don't collide.
        $this->table = 'vec_test_'.str_replace('.', '_', uniqid());
        $store = $this->newStore(true);
        // Ensure a clean slate (AUTO_CREATE made the table; clear any rows).
        $store->clear();
    }

    protected function tearDown(): void {
        if ($this->table === '' || !function_exists('sqlsrv_connect') || getenv('MSSQL_SERVER') === false) {
            return;
        }

        // Drop the per-test table.
        $conn = @sqlsrv_connect((string) getenv('MSSQL_SERVER'), $this->connectionInfo());

        if ($conn !== false) {
            @sqlsrv_query($conn, 'IF OBJECT_ID(N\''.$this->table.'\', N\'U\') IS NOT NULL DROP TABLE '.$this->table);
            sqlsrv_close($conn);
        }
    }
}
