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
 * Represents a single generated image returned by an AI provider.
 *
 * @author Ibrahim
 */
class GeneratedImage {
    /**
     * The base64-encoded image data.
     *
     * @var string|null
     */
    private ?string $base64;

    /**
     * The revised prompt used by the provider (if the provider modified the original).
     *
     * @var string|null
     */
    private ?string $revisedPrompt;

    /**
     * The URL of the generated image.
     *
     * @var string|null
     */
    private ?string $url;

    /**
     * Creates a new GeneratedImage instance.
     *
     * @param string|null $url The URL of the generated image, or null if not available.
     * @param string|null $base64 The base64-encoded image data, or null if not available.
     * @param string|null $revisedPrompt The revised prompt, or null if not modified.
     */
    public function __construct(?string $url = null, ?string $base64 = null, ?string $revisedPrompt = null) {
        $this->url = $url;
        $this->base64 = $base64;
        $this->revisedPrompt = $revisedPrompt;
    }

    /**
     * Returns the base64-encoded image data.
     *
     * @return string|null The base64 data, or null if not available.
     */
    public function getBase64(): ?string {
        return $this->base64;
    }

    /**
     * Returns the revised prompt used by the provider.
     *
     * Some providers (e.g., DALL-E 3) rewrite the prompt for better results.
     * This returns the modified version, or null if unchanged.
     *
     * @return string|null The revised prompt, or null if not modified.
     */
    public function getRevisedPrompt(): ?string {
        return $this->revisedPrompt;
    }

    /**
     * Returns the URL of the generated image.
     *
     * @return string|null The image URL, or null if not available.
     */
    public function getUrl(): ?string {
        return $this->url;
    }
}
