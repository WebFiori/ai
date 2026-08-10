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
 * Represents an image generation response from an AI provider.
 *
 * Contains one or more generated images along with model information.
 *
 * @author Ibrahim
 */
class ImageResponse {
    /**
     * The generated images.
     *
     * @var GeneratedImage[]
     */
    private array $images;

    /**
     * The model identifier that generated the images.
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
     * Creates a new ImageResponse instance.
     *
     * @param GeneratedImage[] $images The generated images.
     * @param string $model The model identifier that generated the images.
     * @param string|null $requestId Unique request identifier for tracing.
     */
    public function __construct(array $images, string $model, ?string $requestId = null) {
        $this->images = $images;
        $this->model = $model;
        $this->requestId = $requestId;
    }

    /**
     * Returns all generated images.
     *
     * @return GeneratedImage[] An array of generated images.
     */
    public function getImages(): array {
        return $this->images;
    }

    /**
     * Returns the model identifier that generated the images.
     *
     * @return string The model name (e.g., 'dall-e-3', 'imagen-3').
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
}
