<?php

namespace WebFiori\Tests\Ai\Embedding;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Embedding\FileVectorStore;
use WebFiori\Ai\Embedding\VectorRecord;

class FileVectorStoreTest extends TestCase {
    private string $testDir;

    protected function setUp(): void {
        $this->testDir = sys_get_temp_dir() . '/file-vector-store-test-' . uniqid();
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->testDir);
    }

    public function testConstructorCreatesDirectory(): void {
        $store = new FileVectorStore($this->testDir);

        $this->assertDirectoryExists($this->testDir);
        $this->assertDirectoryExists($this->testDir . '/vectors');
        $this->assertEquals($this->testDir, $store->getStoragePath());
    }

    public function testConstructorThrowsOnEmptyPath(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Storage path cannot be empty');

        new FileVectorStore('');
    }

    public function testConstructorThrowsWhenDirectoryMissingAndCreateDisabled(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage directory does not exist');

        new FileVectorStore('/nonexistent/path/xyz', createIfMissing: false);
    }

    public function testStoreAndGet(): void {
        $store = new FileVectorStore($this->testDir);

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
        $store = new FileVectorStore($this->testDir);

        $this->assertNull($store->get('nonexistent'));
    }

    public function testStoreOverwritesExisting(): void {
        $store = new FileVectorStore($this->testDir);

        $id = 'test-vector-1';
        $store->store($id, [0.1, 0.2], ['version' => 1]);
        $store->store($id, [0.3, 0.4], ['version' => 2]);

        $record = $store->get($id);

        $this->assertEquals([0.3, 0.4], $record->getVector());
        $this->assertEquals(['version' => 2], $record->getMetadata());
        $this->assertEquals(1, $store->count());
    }

    public function testDelete(): void {
        $store = new FileVectorStore($this->testDir);

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
        $store = new FileVectorStore($this->testDir);

        $this->assertFalse($store->delete('nonexistent'));
    }

    public function testCount(): void {
        $store = new FileVectorStore($this->testDir);

        $this->assertEquals(0, $store->count());

        $store->store('id1', [0.1]);
        $this->assertEquals(1, $store->count());

        $store->store('id2', [0.2]);
        $this->assertEquals(2, $store->count());

        $store->delete('id1');
        $this->assertEquals(1, $store->count());
    }

    public function testClear(): void {
        $store = new FileVectorStore($this->testDir);

        $store->store('id1', [0.1]);
        $store->store('id2', [0.2]);
        $store->store('id3', [0.3]);

        $this->assertEquals(3, $store->count());

        $store->clear();

        $this->assertEquals(0, $store->count());
        $this->assertNull($store->get('id1'));
    }

    public function testStoreBatch(): void {
        $store = new FileVectorStore($this->testDir);

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
        $store = new FileVectorStore($this->testDir);

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
        $store = new FileVectorStore($this->testDir);

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
        $store = new FileVectorStore($this->testDir);

        $store->store('id1', [1.0, 0.0], ['category' => 'water']);

        $results = $store->query([1.0, 0.0], filter: ['category' => 'energy']);

        $this->assertCount(0, $results);
    }

    public function testPersistenceAcrossInstances(): void {
        // Store data with first instance
        $store1 = new FileVectorStore($this->testDir);
        $store1->store('id1', [0.1, 0.2, 0.3], ['source' => 'test.pdf']);
        $store1->store('id2', [0.4, 0.5, 0.6], ['source' => 'other.pdf']);

        // Create new instance pointing to same directory
        $store2 = new FileVectorStore($this->testDir);

        $this->assertEquals(2, $store2->count());

        $record = $store2->get('id1');
        $this->assertNotNull($record);
        $this->assertEquals([0.1, 0.2, 0.3], $record->getVector());
        $this->assertEquals('test.pdf', $record->getMetadata()['source']);
    }

    public function testQueryWithEmptyStore(): void {
        $store = new FileVectorStore($this->testDir);

        $results = $store->query([1.0, 0.0, 0.0]);

        $this->assertCount(0, $results);
    }

    public function testStoreWithEmptyMetadata(): void {
        $store = new FileVectorStore($this->testDir);

        $store->store('id1', [0.1, 0.2]);

        $record = $store->get('id1');

        $this->assertEquals([], $record->getMetadata());
    }

    public function testUnicodeInMetadata(): void {
        $store = new FileVectorStore($this->testDir);

        $metadata = [
            'title' => 'معيار المياه',
            'description' => '水资源标准',
            'emoji' => '💧🌊',
        ];

        $store->store('id1', [0.1, 0.2], $metadata);

        // Reload from disk
        $store2 = new FileVectorStore($this->testDir);
        $record = $store2->get('id1');

        $this->assertEquals('معيار المياه', $record->getMetadata()['title']);
        $this->assertEquals('水资源标准', $record->getMetadata()['description']);
        $this->assertEquals('💧🌊', $record->getMetadata()['emoji']);
    }

    public function testLargeVector(): void {
        $store = new FileVectorStore($this->testDir);

        // 1536 dimensions (like OpenAI text-embedding-3-small)
        $vector = array_fill(0, 1536, 0.1);

        $store->store('id1', $vector, ['model' => 'text-embedding-3-small']);

        $record = $store->get('id1');

        $this->assertCount(1536, $record->getVector());
        $this->assertEquals($vector, $record->getVector());
    }

    /**
     * Recursively removes a directory.
     */
    private function removeDirectory(string $path): void {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
