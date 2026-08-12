<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Cache;

/**
 * Configuration options for response caching.
 *
 * Controls TTL values and determines which requests should be cached
 * based on parameters like temperature.
 *
 * @author Ibrahim
 */
class CacheConfig {
    /**
     * Default TTL for chat responses in seconds.
     *
     * @var int
     */
    private int $defaultTtl;

    /**
     * TTL for embedding responses in seconds.
     *
     * @var int
     */
    private int $embeddingTtl;

    /**
     * Whether caching is enabled.
     *
     * @var bool
     */
    private bool $enabled;

    /**
     * Temperature threshold above which chat responses are not cached.
     *
     * Null means cache all temperatures.
     *
     * @var float|null
     */
    private ?float $skipCacheAboveTemperature;

    /**
     * Creates a new CacheConfig instance.
     *
     * @param bool $enabled Whether caching is enabled.
     * @param int $defaultTtl Default TTL for chat responses in seconds.
     * @param int $embeddingTtl TTL for embedding responses in seconds.
     * @param float|null $skipCacheAboveTemperature Temperature above which to skip caching.
     *        Use 0.0 to only cache deterministic responses (temperature=0).
     *        Use null to cache all responses regardless of temperature.
     */
    public function __construct(
        bool $enabled = true,
        int $defaultTtl = 3600,
        int $embeddingTtl = 86400,
        ?float $skipCacheAboveTemperature = 0.0
    ) {
        $this->enabled = $enabled;
        $this->defaultTtl = $defaultTtl;
        $this->embeddingTtl = $embeddingTtl;
        $this->skipCacheAboveTemperature = $skipCacheAboveTemperature;
    }

    /**
     * Returns the default TTL for chat responses.
     *
     * @return int TTL in seconds.
     */
    public function getDefaultTtl(): int {
        return $this->defaultTtl;
    }

    /**
     * Returns the TTL for embedding responses.
     *
     * @return int TTL in seconds.
     */
    public function getEmbeddingTtl(): int {
        return $this->embeddingTtl;
    }

    /**
     * Returns the temperature threshold for caching.
     *
     * @return float|null The threshold, or null if all temperatures are cached.
     */
    public function getSkipCacheAboveTemperature(): ?float {
        return $this->skipCacheAboveTemperature;
    }

    /**
     * Returns whether caching is enabled.
     *
     * @return bool True if caching is enabled.
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Determines if a chat request should be cached based on temperature.
     *
     * @param float|null $temperature The request temperature, or null if not set.
     *
     * @return bool True if the request should be cached.
     */
    public function shouldCacheChat(?float $temperature): bool {
        if (!$this->enabled) {
            return false;
        }

        if ($this->skipCacheAboveTemperature === null) {
            return true;
        }

        $temp = $temperature ?? 1.0; // Default temperature for most providers

        return $temp <= $this->skipCacheAboveTemperature;
    }

    /**
     * Determines if an embedding request should be cached.
     *
     * Embeddings are always deterministic, so they are cached if enabled.
     *
     * @return bool True if embeddings should be cached.
     */
    public function shouldCacheEmbedding(): bool {
        return $this->enabled;
    }
}
