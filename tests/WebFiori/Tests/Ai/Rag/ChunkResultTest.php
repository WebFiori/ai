<?php

namespace WebFiori\Tests\Ai\Rag;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Rag\ChunkResult;

class ChunkResultTest extends TestCase {
    public function testConstructorAndGetters(): void {
        $chunk = new ChunkResult(
            id: 'chunk-001',
            text: 'This is the chunk text.',
            index: 0,
            charOffset: 0,
            tokenEstimate: 6,
            metadata: ['source' => 'test.pdf', 'page' => 1],
        );

        $this->assertEquals('chunk-001', $chunk->getId());
        $this->assertEquals('This is the chunk text.', $chunk->getText());
        $this->assertEquals(0, $chunk->getIndex());
        $this->assertEquals(0, $chunk->getCharOffset());
        $this->assertEquals(6, $chunk->getTokenEstimate());
        $this->assertEquals(['source' => 'test.pdf', 'page' => 1], $chunk->getMetadata());
    }

    public function testGetAllMetadataMergesChunkInfo(): void {
        $chunk = new ChunkResult(
            id: 'chunk-002',
            text: 'Sample text content.',
            index: 5,
            charOffset: 1000,
            tokenEstimate: 4,
            metadata: ['source' => 'report.pdf', 'category' => 'water'],
        );

        $allMeta = $chunk->getAllMetadata();

        // Original metadata preserved
        $this->assertEquals('report.pdf', $allMeta['source']);
        $this->assertEquals('water', $allMeta['category']);

        // Chunk-specific fields added
        $this->assertEquals(5, $allMeta['chunk_index']);
        $this->assertEquals(1000, $allMeta['chunk_offset']);
        $this->assertEquals('Sample text content.', $allMeta['text']);
    }

    public function testEmptyMetadata(): void {
        $chunk = new ChunkResult(
            id: 'chunk-003',
            text: 'Text without metadata.',
            index: 0,
            charOffset: 0,
            tokenEstimate: 4,
        );

        $this->assertEquals([], $chunk->getMetadata());

        $allMeta = $chunk->getAllMetadata();
        $this->assertCount(3, $allMeta); // Only chunk-specific fields
        $this->assertArrayHasKey('chunk_index', $allMeta);
        $this->assertArrayHasKey('chunk_offset', $allMeta);
        $this->assertArrayHasKey('text', $allMeta);
    }

    public function testUnicodeText(): void {
        $arabicText = 'هذا نص عربي للاختبار.';

        $chunk = new ChunkResult(
            id: 'chunk-ar',
            text: $arabicText,
            index: 0,
            charOffset: 0,
            tokenEstimate: 5,
            metadata: ['lang' => 'ar'],
        );

        $this->assertEquals($arabicText, $chunk->getText());
        $this->assertEquals($arabicText, $chunk->getAllMetadata()['text']);
    }
}
