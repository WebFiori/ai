<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Embedding;

/**
 * Represents a stored vector with its associated metadata.
 *
 * This DTO is used both for storing vectors and for returning query results.
 * When returned from a similarity query, the score property contains the
 * cosine similarity value between 0 and 1.
 *
 * @author Ibrahim
 */
class VectorRecord {
    /**
     * The unique identifier of this vector record.
     *
     * @var string
     */
    private string $id;

    /**
     * Arbitrary metadata associated with this vector.
     *
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * The similarity score (populated when returned from a query).
     *
     * @var float|null
     */
    private ?float $score;

    /**
     * The embedding vector.
     *
     * @var float[]
     */
    private array $vector;

    /**
     * Creates a new VectorRecord instance.
     *
     * @param string $id The unique identifier for this record.
     * @param float[] $vector The embedding vector.
     * @param array<string, mixed> $metadata Optional metadata key-value pairs.
     * @param float|null $score Optional similarity score (set when returned from queries).
     */
    public function __construct(string $id, array $vector, array $metadata = [], ?float $score = null) {
        $this->id = $id;
        $this->vector = $vector;
        $this->metadata = $metadata;
        $this->score = $score;
    }

    /**
     * Returns the unique identifier of this record.
     *
     * @return string The record ID.
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Returns the metadata associated with this record.
     *
     * @return array<string, mixed> The metadata key-value pairs.
     */
    public function getMetadata(): array {
        return $this->metadata;
    }

    /**
     * Returns the similarity score.
     *
     * This is only populated when the record is returned from a query operation.
     * The score represents cosine similarity between 0 and 1, where 1 means
     * identical vectors.
     *
     * @return float|null The similarity score, or null if not from a query.
     */
    public function getScore(): ?float {
        return $this->score;
    }

    /**
     * Returns the embedding vector.
     *
     * @return float[] The vector as an array of floats.
     */
    public function getVector(): array {
        return $this->vector;
    }
}
