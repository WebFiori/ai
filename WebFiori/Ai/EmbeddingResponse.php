<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai;

/**
 * Represents an embedding response from an AI provider.
 *
 * Contains one or more vector embeddings generated from input text,
 * along with the model used and token usage information.
 *
 * @author Ibrahim
 */
class EmbeddingResponse {
    /**
     * The model identifier that generated the embeddings.
     *
     * @var string
     */
    private string $model;

    /**
     * Unique identifier for the request that produced this response.
     *
     * @var string|null
     */
    private ?string $requestId;

    /**
     * Token usage information for this response.
     *
     * @var Usage|null
     */
    private ?Usage $usage;

    /**
     * The generated vector embeddings.
     *
     * @var float[][]
     */
    private array $vectors;

    /**
     * Creates a new EmbeddingResponse instance.
     *
     * @param float[][] $vectors An array of embedding vectors.
     * @param string $model The model identifier that generated the embeddings.
     * @param Usage|null $usage Token usage information, or null if not available.
     * @param string|null $requestId Unique request identifier for tracing.
     */
    public function __construct(array $vectors, string $model, ?Usage $usage = null, ?string $requestId = null) {
        $this->vectors = $vectors;
        $this->model = $model;
        $this->usage = $usage;
        $this->requestId = $requestId;
    }

    /**
     * Returns the number of dimensions in the embedding vectors.
     *
     * @return int The dimension count, or 0 if no vectors are present.
     */
    public function getDimensions(): int {
        if (count($this->vectors) === 0) {
            return 0;
        }

        return count($this->vectors[0]);
    }

    /**
     * Returns the model identifier that generated the embeddings.
     *
     * @return string The model name (e.g., 'text-embedding-3-small').
     */
    public function getModel(): string {
        return $this->model;
    }

    /**
     * Returns the unique request identifier for tracing and correlation.
     *
     * @return string|null The request ID, or null if not set.
     */
    public function getRequestId(): ?string {
        return $this->requestId;
    }

    /**
     * Returns the token usage information for this response.
     *
     * @return Usage|null The usage data, or null if not reported by the provider.
     */
    public function getUsage(): ?Usage {
        return $this->usage;
    }

    /**
     * Returns the first embedding vector.
     *
     * @return float[] The first embedding vector.
     *
     * @throws \RuntimeException If no vectors are present in the response.
     */
    public function getVector(): array {
        if (count($this->vectors) === 0) {
            throw new \RuntimeException('No embedding vectors in response.');
        }

        return $this->vectors[0];
    }

    /**
     * Returns all embedding vectors.
     *
     * @return float[][] An array of embedding vectors.
     */
    public function getVectors(): array {
        return $this->vectors;
    }
}
