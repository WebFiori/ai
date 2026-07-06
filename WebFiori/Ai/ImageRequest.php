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
 * Represents a request to generate an image from a text prompt.
 *
 * Encapsulates all parameters needed for image generation across
 * different providers (DALL-E, Imagen, etc.).
 *
 * @author Ibrahim
 */
class ImageRequest {
    /**
     * The number of images to generate.
     *
     * @var int
     */
    private int $count;

    /**
     * The response format ('url' or 'base64').
     *
     * @var string
     */
    private string $format;

    /**
     * A text description of content to exclude from the generated image.
     *
     * @var string|null
     */
    private ?string $negativePrompt;

    /**
     * The text prompt describing the image to generate.
     *
     * @var string
     */
    private string $prompt;

    /**
     * The quality of the generated image (e.g., 'standard', 'hd').
     *
     * @var string
     */
    private string $quality;

    /**
     * The size of the generated image (e.g., '1024x1024').
     *
     * @var string
     */
    private string $size;

    /**
     * The style of the generated image (e.g., 'vivid', 'natural').
     *
     * @var string|null
     */
    private ?string $style;

    /**
     * Creates a new ImageRequest instance.
     *
     * @param string $prompt The text prompt describing the image to generate.
     * @param string $size The size of the generated image. Default is '1024x1024'.
     * @param int $count The number of images to generate. Default is 1.
     * @param string $quality The quality level. Default is 'standard'.
     * @param string $format The response format. Default is 'url'.
     * @param string|null $style The image style, or null for provider default.
     * @param string|null $negativePrompt A description of what to exclude, or null.
     */
    public function __construct(
        string $prompt,
        string $size = '1024x1024',
        int $count = 1,
        string $quality = 'standard',
        string $format = 'url',
        ?string $style = null,
        ?string $negativePrompt = null
    ) {
        $this->prompt = $prompt;
        $this->size = $size;
        $this->count = $count;
        $this->quality = $quality;
        $this->format = $format;
        $this->style = $style;
        $this->negativePrompt = $negativePrompt;
    }

    /**
     * Returns the number of images to generate.
     *
     * @return int The image count.
     */
    public function getCount(): int {
        return $this->count;
    }

    /**
     * Returns the response format.
     *
     * @return string The format ('url' or 'base64').
     */
    public function getFormat(): string {
        return $this->format;
    }

    /**
     * Returns the negative prompt.
     *
     * @return string|null The negative prompt, or null if not set.
     */
    public function getNegativePrompt(): ?string {
        return $this->negativePrompt;
    }

    /**
     * Returns the text prompt.
     *
     * @return string The image generation prompt.
     */
    public function getPrompt(): string {
        return $this->prompt;
    }

    /**
     * Returns the quality level.
     *
     * @return string The quality (e.g., 'standard', 'hd').
     */
    public function getQuality(): string {
        return $this->quality;
    }

    /**
     * Returns the image size.
     *
     * @return string The size (e.g., '1024x1024', '1792x1024').
     */
    public function getSize(): string {
        return $this->size;
    }

    /**
     * Returns the image style.
     *
     * @return string|null The style, or null if not set.
     */
    public function getStyle(): ?string {
        return $this->style;
    }
}
