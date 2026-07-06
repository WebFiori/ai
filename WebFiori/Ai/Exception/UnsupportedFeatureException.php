<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Exception;

/**
 * Thrown when a provider does not support a requested operation.
 *
 * For example, calling embed() on a provider that only supports chat,
 * or calling generateImage() on Anthropic (which has no image generation).
 *
 * @author Ibrahim
 */
class UnsupportedFeatureException extends AiException {
    /**
     * The name of the unsupported feature.
     *
     * @var string
     */
    private string $feature;

    /**
     * The name of the provider that does not support the feature.
     *
     * @var string
     */
    private string $providerName;

    /**
     * Creates a new UnsupportedFeatureException instance.
     *
     * @param string $feature The name of the unsupported feature
     *        (e.g., 'embeddings', 'image_generation').
     * @param string $providerName The name of the provider that does not
     *        support the feature.
     */
    public function __construct(string $feature, string $providerName) {
        parent::__construct(
            sprintf("The '%s' feature is not supported by the '%s' provider.", $feature, $providerName)
        );
        $this->feature = $feature;
        $this->providerName = $providerName;
    }

    /**
     * Returns the name of the unsupported feature.
     *
     * @return string The feature name (e.g., 'embeddings', 'image_generation').
     */
    public function getFeature(): string {
        return $this->feature;
    }

    /**
     * Returns the name of the provider that does not support the feature.
     *
     * @return string The provider name.
     */
    public function getProviderName(): string {
        return $this->providerName;
    }
}
