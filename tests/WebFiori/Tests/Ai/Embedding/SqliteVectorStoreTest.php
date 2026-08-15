<?php

namespace WebFiori\Tests\Ai\Embedding;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Embedding\SqliteVectorStore;
use WebFiori\Ai\Embedding\VectorRecord;

class SqliteVectorStoreTest extends TestCase {
    private string $testDb;

    protected function setUp(): void {
        $this->testDb = sys_get_temp_dir() . '/sqlite-vector-store-test-' . uniqid() . '.db';
    }

    protected function tearDown(): void {
        if (file_exists($this->testDb)) {
            unlink($this->testDb);
        }

        // Clean up WAL files
        if (file_exists($this->testDb . '-wal')) {
            unlink($this->testDb . '-wal');
        }

        if (file_exists($this->testDb . '-shm')) {
            unlink($this->testDb . '-shm');
        }
    }

    public function testConstructorCreatesDatabase(): void {
        $store = new SqliteVectorStore($this->testDb);

        $this->assertFileExists($this->testDb);
        $this->assertEquals($this->testDb, $store->getDatabasePath());
    }

    public function testConstructorThrowsOnEmptyPath(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database path cannot be empty');

        new SqliteVectorStore('');
    }

    public function testConstructorCreatesParentDirectory(): void {
        $nestedPath = sys_get_temp_dir() . '/nested-' . uniqid() . '/subdir/test.db';
        $store = new SqliteVectorStore($nestedPath);

        $this->assertFileExists($nestedPath);

        // Cleanup
        unlink($nestedPath);
        rmdir(dirname($nestedPath));
        rmdir(dirname(dirname($nestedPath)));
    }

    public function testStoreAndGet(): void {
        $store = new SqliteVectorStore($this->testDb);

        $id = 'test-vector-1';
        $vector = [0.1, 0.2, 0.3, 0.4, 0.5];
        $metadata = ['source' => 'test.pdf', 'page' => 1];

        $store->store($id, $vector, $metadata);

        $record = $store->get($id);

        $this->assertNotNull($record);
        $this->assertEquals($id, $record->getId());
        $this->assertEquals($vector, $record->getVector());
        $this->assertEquals($metadata, $record->getMetadata());
    }

    public function testGetReturnsNullForMissingId(): void {
        $store = new SqliteVectorStore($this->testDb);

        $this->assertNull($store->get('nonexistent'));
    }

    public function testStoreOverwritesExisting(): void {
        $store = new SqliteVectorStore($this->testDb);

        $id = 'test-vector-1';
        $store->store($id, [0.1, 0.2], ['version' => 1]);
        $store->store($id, [0.3, 0.4], ['version' => 2]);

        $record = $store->get($id);

        $this->assertEquals([0.3, 0.4], $record->getVector());
        $this->assertEquals(['version' => 2], $record->getMetadata());
        $this->assertEquals(1, $store->count());
    }

    public function testDelete(): void {
        $store = new SqliteVectorStore($this->testDb);

        $store->store('id1', [0.1, 0.2]);
        $store->store('id2', [0.3, 0.4]);

        $this->assertEquals(2, $store->count());

        $result = $store->delete('id1');

        $this->assertTrue($result);
        $this->assertEquals(1, $store->count());
        $this->assertNull($store->get('id1'));
        $this->assertNotNull($store->get('id2'));
    }

    public function testDeleteReturnsFalseForMissingId(): void {
        $store = new SqliteVectorStore($this->testDb);

        $this->assertFalse($store->delete('nonexistent'));
    }

    public function testCount(): void {
        $store = new SqliteVectorStore($this->testDb);

        $this->assertEquals(0, $store->count());

        $store->store('id1', [0.1]);
        $this->assertEquals(1, $store->count());

        $store->store('id2', [0.2]);
        $this->assertEquals(2, $store->count());

        $store->delete('id1');
        $this->assertEquals(1, $store->count());
    }

    public function testClear(): void {
        $store = new SqliteVectorStore($this->testDb);

        $store->store('id1', [0.1]);
        $store->store('id2', [0.2]);
        $store->store('id3', [0.3]);

        $this->assertEquals(3, $store->count());

        $store->clear();

        $this->assertEquals(0, $store->count());
        $this->assertNull($store->get('id1'));
    }

    public function testStoreBatch(): void {
        $store = new SqliteVectorStore($this->testDb);

        $records = [
            new VectorRecord('id1', [0.1, 0.2], ['source' => 'a.pdf']),
            new VectorRecord('id2', [0.3, 0.4], ['source' => 'b.pdf']),
            new VectorRecord('id3', [0.5, 0.6], ['source' => 'c.pdf']),
        ];

        $store->storeBatch($records);

        $this->assertEquals(3, $store->count());
        $this->assertEquals([0.1, 0.2], $store->get('id1')->getVector());
        $this->assertEquals('b.pdf', $store->get('id2')->getMetadata()['source']);
    }

    public function testQueryReturnsTopKResults(): void {
        $store = new SqliteVectorStore($this->testDb);

        // Store vectors - using simple vectors where similarity is predictable
        $store->store('id1', [1.0, 0.0, 0.0], ['name' => 'east']);
        $store->store('id2', [0.0, 1.0, 0.0], ['name' => 'north']);
        $store->store('id3', [0.0, 0.0, 1.0], ['name' => 'up']);
        $store->store('id4', [0.9, 0.1, 0.0], ['name' => 'almost-east']);

        // Query for vector similar to [1, 0, 0]
        $results = $store->query([1.0, 0.0, 0.0], topK: 2);

        $this->assertCount(2, $results);
        $this->assertEquals('id1', $results[0]->getId()); // Exact match
        $this->assertEquals('id4', $results[1]->getId()); // Close match
        $this->assertEqualsWithDelta(1.0, $results[0]->getScore(), 0.001);
    }

    public function testQueryWithMetadataFilter(): void {
        $store = new SqliteVectorStore($this->testDb);

        $store->store('id1', [1.0, 0.0], ['category' => 'water', 'year' => 2024]);
        $store->store('id2', [0.9, 0.1], ['category' => 'water', 'year' => 2023]);
        $store->store('id3', [0.8, 0.2], ['category' => 'energy', 'year' => 2024]);

        // Filter by category
        $results = $store->query([1.0, 0.0], topK: 10, filter: ['category' => 'water']);

        $this->assertCount(2, $results);
        $this->assertEquals('water', $results[0]->getMetadata()['category']);
        $this->assertEquals('water', $results[1]->getMetadata()['category']);

        // Filter by multiple fields
        $results = $store->query([1.0, 0.0], topK: 10, filter: ['category' => 'water', 'year' => 2024]);

        $this->assertCount(1, $results);
        $this->assertEquals('id1', $results[0]->getId());
    }

    public function testQueryReturnsEmptyArrayWhenNoMatches(): void {
        $store = new SqliteVectorStore($this->testDb);

        $store->store('id1', [1.0, 0.0], ['category' => 'water']);

        $results = $store->query([1.0, 0.0], filter: ['category' => 'energy']);

        $this->assertCount(0, $results);
    }

    public function testPersistenceAcrossInstances(): void {
        // Store data with first instance
        $store1 = new SqliteVectorStore($this->testDb);
        $store1->store('id1', [0.1, 0.2, 0.3], ['source' => 'test.pdf']);
        $store1->store('id2', [0.4, 0.5, 0.6], ['source' => 'other.pdf']);

        // Create new instance pointing to same database
        $store2 = new SqliteVectorStore($this->testDb);

        $this->assertEquals(2, $store2->count());

        $record = $store2->get('id1');
        $this->assertNotNull($record);
        $this->assertEquals([0.1, 0.2, 0.3], $record->getVector());
        $this->assertEquals('test.pdf', $record->getMetadata()['source']);
    }

    public function testQueryWithEmptyStore(): void {
        $store = new SqliteVectorStore($this->testDb);

        $results = $store->query([1.0, 0.0, 0.0]);

        $this->assertCount(0, $results);
    }

    public function testStoreWithEmptyMetadata(): void {
        $store = new SqliteVectorStore($this->testDb);

        $store->store('id1', [0.1, 0.2]);

        $record = $store->get('id1');

        $this->assertEquals([], $record->getMetadata());
    }

    public function testUnicodeInMetadata(): void {
        $store = new SqliteVectorStore($this->testDb);

        $metadata = [
            'title' => 'معيار المياه',
            'description' => '水资源标准',
            'emoji' => '💧🌊',
        ];

        $store->store('id1', [0.1, 0.2], $metadata);

        // Reload from database
        $store2 = new SqliteVectorStore($this->testDb);
        $record = $store2->get('id1');

        $this->assertEquals('معيار المياه', $record->getMetadata()['title']);
        $this->assertEquals('水资源标准', $record->getMetadata()['description']);
        $this->assertEquals('💧🌊', $record->getMetadata()['emoji']);
    }

    public function testLargeVector(): void {
        $store = new SqliteVectorStore($this->testDb);

        // 1536 dimensions (like OpenAI text-embedding-3-small)
        $vector = array_fill(0, 1536, 0.1);

        $store->store('id1', $vector, ['model' => 'text-embedding-3-small']);

        $record = $store->get('id1');

        $this->assertCount(1536, $record->getVector());
        $this->assertEquals($vector, $record->getVector());
    }

    public function testVacuum(): void {
        $store = new SqliteVectorStore($this->testDb);

        // Store and delete some records
        for ($i = 0; $i < 100; $i++) {
            $store->store("id{$i}", array_fill(0, 100, 0.1));
        }

        $store->clear();

        // Should not throw
        $store->vacuum();

        $this->assertEquals(0, $store->count());
    }

    public function testDisableWalMode(): void {
        $store = new SqliteVectorStore($this->testDb, ['wal_mode' => false]);

        $store->store('id1', [0.1, 0.2]);

        $this->assertNotNull($store->get('id1'));
    }

    public function testStoreBatchIsAtomic(): void {
        $store = new SqliteVectorStore($this->testDb);

        $store->store('existing', [0.1, 0.2]);

        $records = [
            new VectorRecord('new1', [0.1, 0.2]),
            new VectorRecord('new2', [0.3, 0.4]),
        ];

        $store->storeBatch($records);

        $this->assertEquals(3, $store->count());
    }
}
