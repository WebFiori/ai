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

/**
 * Immutable DTO representing a document chunk.
 *
 * Returned by {@see TextChunker::chunk()} with metadata about the chunk's
 * position in the original document. Used during ingestion to store chunks
 * in a vector store.
 *
 * @author Ibrahim
 */
class ChunkResult {
    /**
     * Character offset in the original document.
     *
     * @var int
     */
    private int $charOffset;

    /**
     * Unique identifier for this chunk.
     *
     * @var string
     */
    private string $id;

    /**
     * Position index of this chunk (0-based).
     *
     * @var int
     */
    private int $index;

    /**
     * Source metadata passed through from chunking.
     *
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * The chunk text content.
     *
     * @var string
     */
    private string $text;

    /**
     * Estimated token count for this chunk.
     *
     * @var int
     */
    private int $tokenEstimate;

    /**
     * Creates a new ChunkResult instance.
     *
     * @param string $id Unique identifier for this chunk.
     * @param string $text The chunk text content.
     * @param int $index Position index of this chunk (0-based).
     * @param int $charOffset Character offset in the original document.
     * @param int $tokenEstimate Estimated token count.
     * @param array<string, mixed> $metadata Source metadata.
     */
    public function __construct(
        string $id,
        string $text,
        int $index,
        int $charOffset,
        int $tokenEstimate,
        array $metadata = [],
    ) {
        $this->id = $id;
        $this->text = $text;
        $this->index = $index;
        $this->charOffset = $charOffset;
        $this->tokenEstimate = $tokenEstimate;
        $this->metadata = $metadata;
    }

    /**
     * Returns all metadata including chunk-specific fields.
     *
     * Merges the source metadata with chunk position information:
     * - 'chunk_index': The position index
     * - 'chunk_offset': The character offset
     * - 'text': The chunk text (for retrieval)
     *
     * @return array<string, mixed> Complete metadata for storage.
     */
    public function getAllMetadata(): array {
        return array_merge($this->metadata, [
            'chunk_index' => $this->index,
            'chunk_offset' => $this->charOffset,
            'text' => $this->text,
        ]);
    }

    /**
     * Returns the character offset in the original document.
     *
     * @return int The character offset (0-based).
     */
    public function getCharOffset(): int {
        return $this->charOffset;
    }

    /**
     * Returns the unique identifier for this chunk.
     *
     * @return string The chunk ID.
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Returns the position index of this chunk.
     *
     * @return int The index (0-based).
     */
    public function getIndex(): int {
        return $this->index;
    }

    /**
     * Returns the source metadata.
     *
     * Does not include chunk-specific fields. Use {@see getAllMetadata()}
     * to get complete metadata for storage.
     *
     * @return array<string, mixed> The source metadata.
     */
    public function getMetadata(): array {
        return $this->metadata;
    }

    /**
     * Returns the chunk text content.
     *
     * @return string The text.
     */
    public function getText(): string {
        return $this->text;
    }

    /**
     * Returns the estimated token count.
     *
     * Based on character-ratio estimation (~4 chars per token).
     *
     * @return int The estimated tokens.
     */
    public function getTokenEstimate(): int {
        return $this->tokenEstimate;
    }
}
