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
     * Creates a new ImageResponse instance.
     *
     * @param GeneratedImage[] $images The generated images.
     * @param string $model The model identifier that generated the images.
     */
    public function __construct(array $images, string $model) {
        $this->images = $images;
        $this->model = $model;
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
}
