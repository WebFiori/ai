<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool\FileProcessing;

/**
 * Immutable DTO representing the result of a file conversion.
 *
 * @author Ibrahim
 */
class ConversionResult {
    /**
     * The converted content.
     *
     * @var string
     */
    private string $content;

    /**
     * The actual output format used.
     *
     * @var string
     */
    private string $format;

    /**
     * Additional metadata about the file (sheet names, page count, author, etc.)
     *
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * The MIME type of the original file.
     *
     * @var string
     */
    private string $mimeType;

    /**
     * The original content size in characters before truncation.
     *
     * @var int
     */
    private int $originalSize;

    /**
     * Whether the content was truncated due to max_output.
     *
     * @var bool
     */
    private bool $truncated;

    /**
     * Creates a new ConversionResult instance.
     *
     * @param string $content The converted content.
     * @param string $format The output format used.
     * @param string $mimeType The MIME type of the source file.
     * @param bool $truncated Whether content was truncated.
     * @param int $originalSize Original content size before truncation.
     * @param array<string, mixed> $metadata Additional file metadata.
     */
    public function __construct(
        string $content,
        string $format,
        string $mimeType,
        bool $truncated = false,
        int $originalSize = 0,
        array $metadata = [],
    ) {
        $this->content = $content;
        $this->format = $format;
        $this->mimeType = $mimeType;
        $this->truncated = $truncated;
        $this->originalSize = $originalSize;
        $this->metadata = $metadata;
    }

    /**
     * Returns the converted content.
     *
     * @return string
     */
    public function getContent(): string {
        return $this->content;
    }

    /**
     * Returns the output format used.
     *
     * @return string
     */
    public function getFormat(): string {
        return $this->format;
    }

    /**
     * Returns additional file metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array {
        return $this->metadata;
    }

    /**
     * Returns the MIME type of the source file.
     *
     * @return string
     */
    public function getMimeType(): string {
        return $this->mimeType;
    }

    /**
     * Returns the original content size before truncation.
     *
     * @return int
     */
    public function getOriginalSize(): int {
        return $this->originalSize;
    }

    /**
     * Returns whether the content was truncated.
     *
     * @return bool
     */
    public function isTruncated(): bool {
        return $this->truncated;
    }
}
