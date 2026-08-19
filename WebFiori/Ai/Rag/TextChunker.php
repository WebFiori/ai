<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Rag;

use WebFiori\Ai\Context\TokenEstimator;

/**
 * Splits text documents into overlapping chunks for embedding.
 *
 * Chunks are created with configurable size and overlap to ensure context
 * is preserved across chunk boundaries. The chunker attempts to respect
 * sentence boundaries when splitting to avoid cutting mid-sentence.
 *
 * Example:
 * ```php
 * $chunker = new TextChunker(chunkSize: 2000, overlap: 400);
 * $chunks = $chunker->chunk($documentText, ['source' => 'report.pdf']);
 *
 * foreach ($chunks as $chunk) {
 *     $vector = $provider->embed($chunk->getText())->getEmbedding();
 *     $store->store($chunk->getId(), $vector, $chunk->getAllMetadata());
 * }
 * ```
 *
 * @author Ibrahim
 */
class TextChunker {
    /**
     * Target chunk size in characters.
     *
     * @var int
     */
    private int $chunkSize;

    /**
     * Overlap between consecutive chunks in characters.
     *
     * @var int
     */
    private int $overlap;

    /**
     * Token estimator for calculating token counts.
     *
     * @var TokenEstimator
     */
    private TokenEstimator $tokenEstimator;

    /**
     * Creates a new TextChunker instance.
     *
     * @param int $chunkSize Target chunk size in characters (default: 2000).
     * @param int $overlap Overlap between chunks in characters (default: 400).
     * @param TokenEstimator|null $tokenEstimator Token estimator instance.
     *        If null, a new instance is created.
     */
    public function __construct(
        int $chunkSize = 2000,
        int $overlap = 400,
        ?TokenEstimator $tokenEstimator = null,
    ) {
        $this->chunkSize = $chunkSize;
        $this->overlap = $overlap;
        $this->tokenEstimator = $tokenEstimator ?? new TokenEstimator();
    }

    /**
     * Splits text into chunks.
     *
     * Each chunk is approximately the configured size with the configured
     * overlap. The chunker attempts to break at sentence boundaries when
     * possible.
     *
     * @param string $text The document text to chunk.
     * @param array<string, mixed> $metadata Metadata to attach to all chunks
     *        (e.g., source filename, page number).
     *
     * @return ChunkResult[] Array of chunk results.
     */
    public function chunk(string $text, array $metadata = []): array {
        if ($text === '') {
            return [];
        }

        // Normalize line endings
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);

        $textLength = mb_strlen($text);
        $chunks = [];
        $position = 0;
        $index = 0;

        while ($position < $textLength) {
            // Calculate end position for this chunk
            $endPosition = min($position + $this->chunkSize, $textLength);

            // If not at the end, try to find a good break point
            if ($endPosition < $textLength) {
                $endPosition = $this->findBreakPoint($text, $position, $endPosition);
            }

            // Extract chunk text
            $chunkText = mb_substr($text, $position, $endPosition - $position);
            $chunkText = trim($chunkText);

            if ($chunkText !== '') {
                // Generate unique ID based on source and position
                $sourceKey = $metadata['source'] ?? 'doc';
                $id = md5($sourceKey.'-'.$position.'-'.$index);

                $chunks[] = new ChunkResult(
                    id: $id,
                    text: $chunkText,
                    index: $index,
                    charOffset: $position,
                    tokenEstimate: $this->tokenEstimator->countText($chunkText),
                    metadata: $metadata,
                );

                $index++;
            }

            // Move to next position with overlap
            $nextPosition = $endPosition - $this->overlap;

            // Ensure we make progress
            if ($nextPosition <= $position) {
                $nextPosition = $endPosition;
            }

            // If we've reached the end, stop
            if ($endPosition >= $textLength) {
                break;
            }

            $position = $nextPosition;
        }

        return $chunks;
    }

    /**
     * Returns the configured chunk size.
     *
     * @return int Chunk size in characters.
     */
    public function getChunkSize(): int {
        return $this->chunkSize;
    }

    /**
     * Returns the configured overlap.
     *
     * @return int Overlap in characters.
     */
    public function getOverlap(): int {
        return $this->overlap;
    }

    /**
     * Sets the chunk size.
     *
     * @param int $size Chunk size in characters.
     *
     * @return self For method chaining.
     */
    public function setChunkSize(int $size): self {
        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Sets the overlap.
     *
     * @param int $overlap Overlap in characters.
     *
     * @return self For method chaining.
     */
    public function setOverlap(int $overlap): self {
        $this->overlap = $overlap;

        return $this;
    }

    /**
     * Finds a good break point near the target position.
     *
     * Looks for sentence boundaries (. ? !) or paragraph breaks within
     * a tolerance range. Falls back to word boundaries if no sentence
     * boundary is found.
     *
     * @param string $text The full text.
     * @param int $start Start position of the chunk.
     * @param int $target Target end position.
     *
     * @return int Adjusted end position.
     */
    private function findBreakPoint(string $text, int $start, int $target): int {
        // Search window: 20% of chunk size before target
        $tolerance = (int) ($this->chunkSize * 0.2);
        $searchStart = max($start, $target - $tolerance);
        $searchText = mb_substr($text, $searchStart, $target - $searchStart);

        // Look for sentence endings (. ? !) followed by whitespace
        $patterns = [
            '/\.\s+(?=[A-Z\p{Lu}])/u',  // Period followed by capital letter
            '/[.!?]\s+/u',                // Sentence-ending punctuation
            '/\n\n+/u',                   // Paragraph breaks
            '/\n/u',                       // Line breaks
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $searchText, $matches, PREG_OFFSET_CAPTURE)) {
                // Use the last match in the search window
                $lastMatch = end($matches[0]);
                $matchPos = $lastMatch[1];
                $matchLen = mb_strlen($lastMatch[0]);

                return $searchStart + $matchPos + $matchLen;
            }
        }

        // Fall back to word boundary (space)
        $lastSpace = mb_strrpos($searchText, ' ');

        if ($lastSpace !== false) {
            return $searchStart + $lastSpace + 1;
        }

        // No good break point found, use target
        return $target;
    }
}
