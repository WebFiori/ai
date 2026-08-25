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
 * Immutable DTO representing a retrieval result.
 *
 * Returned by {@see RagProviderInterface::retrieve()} with the retrieved text,
 * similarity score, and source metadata for citation.
 *
 * @author Ibrahim
 */
class RetrievalResult {
    /**
     * Unique identifier of the retrieved record.
     *
     * @var string
     */
    private string $id;

    /**
     * Metadata associated with this result.
     *
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * Cosine similarity score (0 to 1).
     *
     * @var float
     */
    private float $score;

    /**
     * The retrieved text content.
     *
     * @var string
     */
    private string $text;

    /**
     * Creates a new RetrievalResult instance.
     *
     * @param string $id Unique identifier.
     * @param string $text Retrieved text content.
     * @param float $score Similarity score (0 to 1).
     * @param array<string, mixed> $metadata Associated metadata.
     */
    public function __construct(
        string $id,
        string $text,
        float $score,
        array $metadata = [],
    ) {
        $this->id = $id;
        $this->text = $text;
        $this->score = $score;
        $this->metadata = $metadata;
    }

    /**
     * Returns the unique identifier.
     *
     * @return string The record ID.
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Returns the associated metadata.
     *
     * @return array<string, mixed> The metadata.
     */
    public function getMetadata(): array {
        return $this->metadata;
    }

    /**
     * Returns the page number if available.
     *
     * Convenience method for accessing common metadata.
     *
     * @return int|null The page number, or null if not set.
     */
    public function getPage(): ?int {
        return isset($this->metadata['page']) ? (int) $this->metadata['page'] : null;
    }

    /**
     * Returns the similarity score.
     *
     * Higher scores indicate better matches. Typically ranges from 0 to 1
     * for cosine similarity.
     *
     * @return float The score.
     */
    public function getScore(): float {
        return $this->score;
    }

    /**
     * Returns the source identifier if available.
     *
     * Convenience method for accessing common metadata.
     *
     * @return string|null The source (e.g., filename), or null if not set.
     */
    public function getSource(): ?string {
        return $this->metadata['source'] ?? null;
    }

    /**
     * Returns the retrieved text content.
     *
     * @return string The text.
     */
    public function getText(): string {
        return $this->text;
    }

    /**
     * Converts the result to an array for JSON serialization.
     *
     * Useful for returning structured data from the RetrievalTool.
     *
     * @return array<string, mixed> Array representation.
     */
    public function toArray(): array {
        $result = [
            'id' => $this->id,
            'text' => $this->text,
            'score' => round($this->score, 4),
        ];

        if ($this->getSource() !== null) {
            $result['source'] = $this->getSource();
        }

        if ($this->getPage() !== null) {
            $result['page'] = $this->getPage();
        }

        return $result;
    }
}
