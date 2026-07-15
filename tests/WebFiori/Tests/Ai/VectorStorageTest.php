<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Embedding\VectorRecord;

/**
 * Tests for VectorRecord and InMemoryVectorStore.
 *
 * @author Ibrahim
 */
class VectorStorageTest extends TestCase {
    // =========================================================================
    // VectorRecord Tests
    // =========================================================================

    /**
     * @test
     */
    public function testVectorRecordConstruction() {
        $record = new VectorRecord('doc-1', [0.1, 0.2, 0.3], ['source' => 'faq'], 0.95);

        $this->assertEquals('doc-1', $record->getId());
        $this->assertEquals([0.1, 0.2, 0.3], $record->getVector());
        $this->assertEquals(['source' => 'faq'], $record->getMetadata());
        $this->assertEquals(0.95, $record->getScore());
    }

    /**
     * @test
     */
    public function testVectorRecordDefaults() {
        $record = new VectorRecord('doc-2', [0.5, 0.6]);

        $this->assertEquals('doc-2', $record->getId());
        $this->assertEquals([0.5, 0.6], $record->getVector());
        $this->assertEquals([], $record->getMetadata());
        $this->assertNull($record->getScore());
    }

    /**
     * @test
     */
    public function testVectorRecordWithEmptyVector() {
        $record = new VectorRecord('empty', []);

        $this->assertEquals('empty', $record->getId());
        $this->assertEquals([], $record->getVector());
    }

    /**
     * @test
     */
    public function testVectorRecordWithComplexMetadata() {
        $metadata = [
            'source' => 'docs',
            'page' => 42,
            'tags' => ['php', 'ai'],
            'active' => true,
        ];
        $record = new VectorRecord('doc-3', [0.1], $metadata);

        $this->assertEquals($metadata, $record->getMetadata());
    }

    // =========================================================================
    // InMemoryVectorStore - Store & Retrieve Tests
    // =========================================================================

    /**
     * @test
     */
    public function testStoreAndGet() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.1, 0.2, 0.3], ['source' => 'test']);

        $record = $store->get('doc-1');
        $this->assertNotNull($record);
        $this->assertEquals('doc-1', $record->getId());
        $this->assertEquals([0.1, 0.2, 0.3], $record->getVector());
        $this->assertEquals(['source' => 'test'], $record->getMetadata());
        $this->assertNull($record->getScore());
    }

    /**
     * @test
     */
    public function testGetNonExistent() {
        $store = new InMemoryVectorStore();

        $this->assertNull($store->get('does-not-exist'));
    }

    /**
     * @test
     */
    public function testStoreOverwritesExisting() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.1, 0.2], ['version' => 1]);
        $store->store('doc-1', [0.3, 0.4], ['version' => 2]);

        $record = $store->get('doc-1');
        $this->assertEquals([0.3, 0.4], $record->getVector());
        $this->assertEquals(['version' => 2], $record->getMetadata());
        $this->assertEquals(1, $store->count());
    }

    /**
     * @test
     */
    public function testStoreWithoutMetadata() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.1, 0.2]);

        $record = $store->get('doc-1');
        $this->assertEquals([], $record->getMetadata());
    }

    // =========================================================================
    // InMemoryVectorStore - StoreBatch Tests
    // =========================================================================

    /**
     * @test
     */
    public function testStoreBatch() {
        $store = new InMemoryVectorStore();
        $store->storeBatch([
            new VectorRecord('doc-1', [0.1, 0.2], ['type' => 'a']),
            new VectorRecord('doc-2', [0.3, 0.4], ['type' => 'b']),
            new VectorRecord('doc-3', [0.5, 0.6], ['type' => 'c']),
        ]);

        $this->assertEquals(3, $store->count());
        $this->assertNotNull($store->get('doc-1'));
        $this->assertNotNull($store->get('doc-2'));
        $this->assertNotNull($store->get('doc-3'));
    }

    /**
     * @test
     */
    public function testStoreBatchOverwritesExisting() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.1, 0.2], ['version' => 1]);

        $store->storeBatch([
            new VectorRecord('doc-1', [0.9, 0.8], ['version' => 2]),
            new VectorRecord('doc-2', [0.3, 0.4]),
        ]);

        $this->assertEquals(2, $store->count());
        $record = $store->get('doc-1');
        $this->assertEquals([0.9, 0.8], $record->getVector());
        $this->assertEquals(['version' => 2], $record->getMetadata());
    }

    /**
     * @test
     */
    public function testStoreBatchEmpty() {
        $store = new InMemoryVectorStore();
        $store->storeBatch([]);

        $this->assertEquals(0, $store->count());
    }

    // =========================================================================
    // InMemoryVectorStore - Delete Tests
    // =========================================================================

    /**
     * @test
     */
    public function testDeleteExisting() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.1, 0.2]);

        $this->assertTrue($store->delete('doc-1'));
        $this->assertNull($store->get('doc-1'));
        $this->assertEquals(0, $store->count());
    }

    /**
     * @test
     */
    public function testDeleteNonExistent() {
        $store = new InMemoryVectorStore();

        $this->assertFalse($store->delete('does-not-exist'));
    }

    /**
     * @test
     */
    public function testDeleteDoesNotAffectOtherRecords() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.1, 0.2]);
        $store->store('doc-2', [0.3, 0.4]);

        $store->delete('doc-1');

        $this->assertNull($store->get('doc-1'));
        $this->assertNotNull($store->get('doc-2'));
        $this->assertEquals(1, $store->count());
    }

    // =========================================================================
    // InMemoryVectorStore - Count Tests
    // =========================================================================

    /**
     * @test
     */
    public function testCountEmpty() {
        $store = new InMemoryVectorStore();

        $this->assertEquals(0, $store->count());
    }

    /**
     * @test
     */
    public function testCountAfterOperations() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.1]);
        $store->store('doc-2', [0.2]);
        $this->assertEquals(2, $store->count());

        $store->delete('doc-1');
        $this->assertEquals(1, $store->count());
    }

    // =========================================================================
    // InMemoryVectorStore - Query Tests (Cosine Similarity)
    // =========================================================================

    /**
     * @test
     */
    public function testQueryReturnsMostSimilar() {
        $store = new InMemoryVectorStore();
        // Identical direction to query vector
        $store->store('similar', [1.0, 0.0, 0.0]);
        // Orthogonal to query vector
        $store->store('orthogonal', [0.0, 1.0, 0.0]);
        // Opposite direction to query vector
        $store->store('opposite', [-1.0, 0.0, 0.0]);

        $results = $store->query([1.0, 0.0, 0.0], 3);

        $this->assertCount(3, $results);
        $this->assertEquals('similar', $results[0]->getId());
        $this->assertEqualsWithDelta(1.0, $results[0]->getScore(), 0.0001);
        $this->assertEquals('orthogonal', $results[1]->getId());
        $this->assertEqualsWithDelta(0.0, $results[1]->getScore(), 0.0001);
        $this->assertEquals('opposite', $results[2]->getId());
        $this->assertEqualsWithDelta(-1.0, $results[2]->getScore(), 0.0001);
    }

    /**
     * @test
     */
    public function testQueryTopKLimitsResults() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [1.0, 0.0]);
        $store->store('doc-2', [0.9, 0.1]);
        $store->store('doc-3', [0.8, 0.2]);
        $store->store('doc-4', [0.7, 0.3]);
        $store->store('doc-5', [0.6, 0.4]);

        $results = $store->query([1.0, 0.0], 2);

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function testQueryDefaultTopK() {
        $store = new InMemoryVectorStore();

        for ($i = 0; $i < 15; $i++) {
            $store->store("doc-$i", [cos($i * 0.1), sin($i * 0.1)]);
        }

        $results = $store->query([1.0, 0.0]);

        $this->assertCount(10, $results);
    }

    /**
     * @test
     */
    public function testQueryEmptyStore() {
        $store = new InMemoryVectorStore();

        $results = $store->query([1.0, 0.0, 0.0], 5);

        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function testQueryWithMetadataFilter() {
        $store = new InMemoryVectorStore();
        $store->store('php-doc', [0.9, 0.1], ['language' => 'php']);
        $store->store('python-doc', [0.85, 0.15], ['language' => 'python']);
        $store->store('php-tutorial', [0.8, 0.2], ['language' => 'php']);

        $results = $store->query([1.0, 0.0], 10, ['language' => 'php']);

        $this->assertCount(2, $results);
        $this->assertEquals('php-doc', $results[0]->getId());
        $this->assertEquals('php-tutorial', $results[1]->getId());
    }

    /**
     * @test
     */
    public function testQueryWithMultipleFilterCriteria() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.9, 0.1], ['language' => 'php', 'type' => 'tutorial']);
        $store->store('doc-2', [0.85, 0.15], ['language' => 'php', 'type' => 'reference']);
        $store->store('doc-3', [0.8, 0.2], ['language' => 'python', 'type' => 'tutorial']);

        $results = $store->query([1.0, 0.0], 10, ['language' => 'php', 'type' => 'tutorial']);

        $this->assertCount(1, $results);
        $this->assertEquals('doc-1', $results[0]->getId());
    }

    /**
     * @test
     */
    public function testQueryFilterNoMatches() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.9, 0.1], ['language' => 'php']);

        $results = $store->query([1.0, 0.0], 10, ['language' => 'rust']);

        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function testQueryFilterKeyNotPresent() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.9, 0.1], ['language' => 'php']);
        $store->store('doc-2', [0.8, 0.2]);

        $results = $store->query([1.0, 0.0], 10, ['language' => 'php']);

        $this->assertCount(1, $results);
        $this->assertEquals('doc-1', $results[0]->getId());
    }

    /**
     * @test
     */
    public function testQueryResultsHaveScores() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [1.0, 0.0]);
        $store->store('doc-2', [0.0, 1.0]);

        $results = $store->query([1.0, 0.0], 2);

        foreach ($results as $result) {
            $this->assertNotNull($result->getScore());
        }
    }

    /**
     * @test
     */
    public function testQueryResultsSortedByScoreDescending() {
        $store = new InMemoryVectorStore();
        $store->store('low', [0.0, 1.0]);
        $store->store('high', [1.0, 0.0]);
        $store->store('mid', [0.7, 0.7]);

        $results = $store->query([1.0, 0.0], 3);

        $this->assertGreaterThanOrEqual($results[1]->getScore(), $results[0]->getScore());
        $this->assertGreaterThanOrEqual($results[2]->getScore(), $results[1]->getScore());
    }

    /**
     * @test
     */
    public function testQueryWithZeroVector() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [1.0, 0.0]);
        $store->store('doc-2', [0.0, 1.0]);

        // Zero vector should give 0 similarity with everything
        $results = $store->query([0.0, 0.0], 2);

        $this->assertCount(2, $results);
        $this->assertEqualsWithDelta(0.0, $results[0]->getScore(), 0.0001);
        $this->assertEqualsWithDelta(0.0, $results[1]->getScore(), 0.0001);
    }

    /**
     * @test
     */
    public function testQueryWithStoredZeroVector() {
        $store = new InMemoryVectorStore();
        $store->store('zero', [0.0, 0.0]);
        $store->store('nonzero', [1.0, 0.0]);

        $results = $store->query([1.0, 0.0], 2);

        // Zero vector record should have score 0
        $zeroResult = null;
        $nonzeroResult = null;

        foreach ($results as $r) {
            if ($r->getId() === 'zero') {
                $zeroResult = $r;
            }

            if ($r->getId() === 'nonzero') {
                $nonzeroResult = $r;
            }
        }

        $this->assertEqualsWithDelta(0.0, $zeroResult->getScore(), 0.0001);
        $this->assertEqualsWithDelta(1.0, $nonzeroResult->getScore(), 0.0001);
    }

    /**
     * @test
     */
    public function testQueryTopKGreaterThanStoreSize() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [1.0, 0.0]);
        $store->store('doc-2', [0.0, 1.0]);

        $results = $store->query([1.0, 0.0], 100);

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function testQueryPreservesMetadata() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [1.0, 0.0], ['title' => 'PHP Guide', 'page' => 5]);

        $results = $store->query([1.0, 0.0], 1);

        $this->assertEquals(['title' => 'PHP Guide', 'page' => 5], $results[0]->getMetadata());
    }

    /**
     * @test
     */
    public function testQueryPreservesVector() {
        $store = new InMemoryVectorStore();
        $store->store('doc-1', [0.5, 0.8, 0.3]);

        $results = $store->query([1.0, 0.0, 0.0], 1);

        $this->assertEquals([0.5, 0.8, 0.3], $results[0]->getVector());
    }

    // =========================================================================
    // InMemoryVectorStore - Integration / End-to-End Tests
    // =========================================================================

    /**
     * @test
     */
    public function testFullWorkflow() {
        $store = new InMemoryVectorStore();

        // Store some vectors
        $store->store('doc-1', [0.9, 0.1, 0.0], ['topic' => 'php']);
        $store->store('doc-2', [0.1, 0.9, 0.0], ['topic' => 'python']);
        $store->store('doc-3', [0.8, 0.2, 0.0], ['topic' => 'php']);

        // Verify count
        $this->assertEquals(3, $store->count());

        // Query without filter
        $results = $store->query([1.0, 0.0, 0.0], 2);
        $this->assertCount(2, $results);
        $this->assertEquals('doc-1', $results[0]->getId());

        // Query with filter
        $results = $store->query([1.0, 0.0, 0.0], 10, ['topic' => 'python']);
        $this->assertCount(1, $results);
        $this->assertEquals('doc-2', $results[0]->getId());

        // Delete
        $this->assertTrue($store->delete('doc-2'));
        $this->assertEquals(2, $store->count());
        $this->assertNull($store->get('doc-2'));

        // Query again after delete
        $results = $store->query([0.1, 0.9, 0.0], 10);
        $this->assertCount(2, $results);
    }
}
