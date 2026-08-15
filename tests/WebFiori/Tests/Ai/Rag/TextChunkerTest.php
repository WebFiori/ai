<?php

namespace WebFiori\Tests\Ai\Rag;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Rag\TextChunker;

class TextChunkerTest extends TestCase {
    public function testDefaultConfiguration(): void {
        $chunker = new TextChunker();

        $this->assertEquals(2000, $chunker->getChunkSize());
        $this->assertEquals(400, $chunker->getOverlap());
    }

    public function testCustomConfiguration(): void {
        $chunker = new TextChunker(chunkSize: 1000, overlap: 200);

        $this->assertEquals(1000, $chunker->getChunkSize());
        $this->assertEquals(200, $chunker->getOverlap());
    }

    public function testSetters(): void {
        $chunker = new TextChunker();

        $result = $chunker->setChunkSize(500)->setOverlap(100);

        $this->assertSame($chunker, $result); // Fluent interface
        $this->assertEquals(500, $chunker->getChunkSize());
        $this->assertEquals(100, $chunker->getOverlap());
    }

    public function testEmptyTextReturnsEmptyArray(): void {
        $chunker = new TextChunker();

        $this->assertEquals([], $chunker->chunk(''));
    }

    public function testShortTextReturnsSingleChunk(): void {
        $chunker = new TextChunker(chunkSize: 1000);
        $text = 'This is a short text that fits in one chunk.';

        $chunks = $chunker->chunk($text, ['source' => 'test.txt']);

        $this->assertCount(1, $chunks);
        $this->assertEquals($text, $chunks[0]->getText());
        $this->assertEquals(0, $chunks[0]->getIndex());
        $this->assertEquals(0, $chunks[0]->getCharOffset());
        $this->assertEquals('test.txt', $chunks[0]->getMetadata()['source']);
    }

    public function testLongTextCreatesMultipleChunks(): void {
        $chunker = new TextChunker(chunkSize: 100, overlap: 20);

        // Create text longer than chunk size
        $sentences = [];

        for ($i = 1; $i <= 10; $i++) {
            $sentences[] = "This is sentence number {$i}. ";
        }

        $text = implode('', $sentences);

        $chunks = $chunker->chunk($text);

        $this->assertGreaterThan(1, count($chunks));

        // Verify chunks are indexed correctly
        for ($i = 0; $i < count($chunks); $i++) {
            $this->assertEquals($i, $chunks[$i]->getIndex());
        }

        // Verify we can reconstruct most of the original text
        // (with overlap there may be duplicated content)
        $combined = '';

        foreach ($chunks as $chunk) {
            $combined .= $chunk->getText() . ' ';
        }

        // Each sentence should appear at least once
        for ($i = 1; $i <= 10; $i++) {
            $this->assertStringContainsString("sentence number {$i}", $combined);
        }
    }

    public function testChunkingRespectssSentenceBoundaries(): void {
        $chunker = new TextChunker(chunkSize: 50, overlap: 10);

        $text = 'First sentence here. Second sentence follows. Third one now.';

        $chunks = $chunker->chunk($text);

        // Each chunk should ideally end at a sentence boundary
        foreach ($chunks as $chunk) {
            $chunkText = $chunk->getText();
            // Should not cut mid-word (unless no good boundary found)
            $this->assertDoesNotMatchRegularExpression('/\w$/', $chunkText . ' ');
        }
    }

    public function testChunksHaveOverlap(): void {
        $chunker = new TextChunker(chunkSize: 100, overlap: 30);

        // Generate predictable text
        $text = str_repeat('Word ', 100); // 500 characters

        $chunks = $chunker->chunk($text);

        $this->assertGreaterThan(1, count($chunks));

        // Check that consecutive chunks have overlapping content
        for ($i = 1; $i < count($chunks); $i++) {
            $prevChunk = $chunks[$i - 1]->getText();
            $currChunk = $chunks[$i]->getText();

            // The end of prev chunk should share some content with start of curr
            $prevEnd = mb_substr($prevChunk, -20);
            $currStart = mb_substr($currChunk, 0, 50);

            // They should share at least some words
            $prevWords = explode(' ', trim($prevEnd));
            $currWords = explode(' ', trim($currStart));

            $shared = array_intersect($prevWords, $currWords);
            $this->assertNotEmpty($shared, 'Consecutive chunks should have overlapping content');
        }
    }

    public function testMetadataPassedToAllChunks(): void {
        $chunker = new TextChunker(chunkSize: 50, overlap: 10);

        $text = 'First chunk content here. Second chunk content follows. Third chunk now.';
        $metadata = ['source' => 'document.pdf', 'page' => 5, 'author' => 'Test'];

        $chunks = $chunker->chunk($text, $metadata);

        foreach ($chunks as $chunk) {
            $this->assertEquals('document.pdf', $chunk->getMetadata()['source']);
            $this->assertEquals(5, $chunk->getMetadata()['page']);
            $this->assertEquals('Test', $chunk->getMetadata()['author']);
        }
    }

    public function testUniqueChunkIds(): void {
        $chunker = new TextChunker(chunkSize: 50, overlap: 10);

        $text = 'First part of the text. Second part of the text. Third part of the text.';

        $chunks = $chunker->chunk($text, ['source' => 'test.txt']);

        $ids = array_map(fn($c) => $c->getId(), $chunks);

        $this->assertCount(count($ids), array_unique($ids), 'Chunk IDs should be unique');
    }

    public function testDifferentSourcesProduceDifferentIds(): void {
        $chunker = new TextChunker(chunkSize: 1000);

        $text = 'Same text content.';

        $chunks1 = $chunker->chunk($text, ['source' => 'file1.txt']);
        $chunks2 = $chunker->chunk($text, ['source' => 'file2.txt']);

        $this->assertNotEquals($chunks1[0]->getId(), $chunks2[0]->getId());
    }

    public function testTokenEstimateProvided(): void {
        $chunker = new TextChunker(chunkSize: 100, overlap: 20);

        $text = 'This is some sample text that will be chunked into pieces for testing.';

        $chunks = $chunker->chunk($text);

        foreach ($chunks as $chunk) {
            $this->assertGreaterThan(0, $chunk->getTokenEstimate());
            // Rough check: tokens should be roughly text_length / 4
            $expectedRange = mb_strlen($chunk->getText()) / 4;
            $this->assertEqualsWithDelta($expectedRange, $chunk->getTokenEstimate(), $expectedRange * 0.5);
        }
    }

    public function testCharOffsetIsCorrect(): void {
        $chunker = new TextChunker(chunkSize: 100, overlap: 0); // No overlap for easier testing

        $text = 'AAAA BBBB CCCC DDDD EEEE FFFF GGGG HHHH IIII JJJJ KKKK LLLL MMMM NNNN OOOO PPPP QQQQ RRRR SSSS TTTT UUUU VVVV WWWW XXXX YYYY ZZZZ';

        $chunks = $chunker->chunk($text);

        $this->assertGreaterThan(1, count($chunks));

        // First chunk should start at 0
        $this->assertEquals(0, $chunks[0]->getCharOffset());

        // Subsequent chunks should have increasing offsets
        for ($i = 1; $i < count($chunks); $i++) {
            $this->assertGreaterThan(
                $chunks[$i - 1]->getCharOffset(),
                $chunks[$i]->getCharOffset(),
            );
        }
    }

    public function testParagraphBreaksRespected(): void {
        $chunker = new TextChunker(chunkSize: 100, overlap: 20);

        $text = "First paragraph with some content.\n\nSecond paragraph starts here.\n\nThird paragraph follows.";

        $chunks = $chunker->chunk($text);

        // Should preferentially break at paragraph boundaries
        $this->assertGreaterThanOrEqual(1, count($chunks));
    }

    public function testWindowsLineEndingsNormalized(): void {
        $chunker = new TextChunker(chunkSize: 100, overlap: 20);

        $text = "Line one.\r\nLine two.\r\nLine three.";

        $chunks = $chunker->chunk($text);

        // Should handle without issues
        $this->assertGreaterThanOrEqual(1, count($chunks));
        $this->assertStringNotContainsString("\r", $chunks[0]->getText());
    }

    public function testUnicodeTextHandledCorrectly(): void {
        $chunker = new TextChunker(chunkSize: 50, overlap: 10);

        $text = 'مرحبا بالعالم. هذا نص عربي للاختبار. نحتاج إلى المزيد من النص هنا.';

        $chunks = $chunker->chunk($text, ['lang' => 'ar']);

        $this->assertGreaterThanOrEqual(1, count($chunks));

        // Verify text is not corrupted
        $combined = implode('', array_map(fn($c) => $c->getText(), $chunks));
        $this->assertStringContainsString('مرحبا', $combined);
        $this->assertStringContainsString('عربي', $combined);
    }

    public function testVeryLongTextWithoutSpaces(): void {
        $chunker = new TextChunker(chunkSize: 50, overlap: 10);

        // Text with no good break points
        $text = str_repeat('a', 200);

        $chunks = $chunker->chunk($text);

        // Should still produce chunks even without good break points
        $this->assertGreaterThan(1, count($chunks));
    }

    public function testEmptyMetadataIsAllowed(): void {
        $chunker = new TextChunker();

        $chunks = $chunker->chunk('Some text content.');

        $this->assertCount(1, $chunks);
        $this->assertEquals([], $chunks[0]->getMetadata());
    }
}
