<?php

namespace WebFiori\Tests\Ai\Rag;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Rag\RetrievalResult;

class RetrievalResultTest extends TestCase {
    public function testConstructorAndGetters(): void {
        $result = new RetrievalResult(
            id: 'chunk-001',
            text: 'This is the retrieved text.',
            score: 0.89,
            metadata: ['source' => 'report.pdf', 'page' => 5],
        );

        $this->assertEquals('chunk-001', $result->getId());
        $this->assertEquals('This is the retrieved text.', $result->getText());
        $this->assertEquals(0.89, $result->getScore());
        $this->assertEquals(['source' => 'report.pdf', 'page' => 5], $result->getMetadata());
    }

    public function testGetSource(): void {
        $result = new RetrievalResult(
            id: 'id1',
            text: 'Text',
            score: 0.9,
            metadata: ['source' => 'document.pdf'],
        );

        $this->assertEquals('document.pdf', $result->getSource());
    }

    public function testGetSourceReturnsNullWhenMissing(): void {
        $result = new RetrievalResult(
            id: 'id1',
            text: 'Text',
            score: 0.9,
        );

        $this->assertNull($result->getSource());
    }

    public function testGetPage(): void {
        $result = new RetrievalResult(
            id: 'id1',
            text: 'Text',
            score: 0.9,
            metadata: ['page' => 42],
        );

        $this->assertEquals(42, $result->getPage());
    }

    public function testGetPageReturnsNullWhenMissing(): void {
        $result = new RetrievalResult(
            id: 'id1',
            text: 'Text',
            score: 0.9,
        );

        $this->assertNull($result->getPage());
    }

    public function testToArray(): void {
        $result = new RetrievalResult(
            id: 'chunk-001',
            text: 'Sample text.',
            score: 0.8765,
            metadata: ['source' => 'doc.pdf', 'page' => 3],
        );

        $array = $result->toArray();

        $this->assertEquals('chunk-001', $array['id']);
        $this->assertEquals('Sample text.', $array['text']);
        $this->assertEquals(0.8765, $array['score']);
        $this->assertEquals('doc.pdf', $array['source']);
        $this->assertEquals(3, $array['page']);
    }

    public function testToArrayOmitsMissingSourceAndPage(): void {
        $result = new RetrievalResult(
            id: 'id1',
            text: 'Text',
            score: 0.5,
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('text', $array);
        $this->assertArrayHasKey('score', $array);
        $this->assertArrayNotHasKey('source', $array);
        $this->assertArrayNotHasKey('page', $array);
    }

    public function testScoreIsRoundedInToArray(): void {
        $result = new RetrievalResult(
            id: 'id1',
            text: 'Text',
            score: 0.123456789,
        );

        $array = $result->toArray();

        $this->assertEquals(0.1235, $array['score']); // Rounded to 4 decimals
    }
}
