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
 * Contract for cache implementations used to store AI responses.
 *
 * Implementations can use any backing store (memory, files, Redis, etc.).
 * The interface is intentionally minimal to make adapters easy to write.
 *
 * @author Ibrahim
 */
interface CacheInterface {
    /**
     * Removes all entries from the cache.
     */
    public function clear(): void;

    /**
     * Removes an entry from the cache.
     *
     * @param string $key The cache key.
     */
    public function delete(string $key): void;

    /**
     * Retrieves a cached response by key.
     *
     * @param string $key The cache key.
     *
     * @return CachedResponse|null The cached response, or null if not found or expired.
     */
    public function get(string $key): ?CachedResponse;

    /**
     * Checks if a non-expired entry exists for the given key.
     *
     * @param string $key The cache key.
     *
     * @return bool True if a valid entry exists.
     */
    public function has(string $key): bool;

    /**
     * Stores a response in the cache.
     *
     * @param string $key The cache key.
     * @param CachedResponse $response The response to cache.
     * @param int $ttlSeconds Time-to-live in seconds.
     */
    public function set(string $key, CachedResponse $response, int $ttlSeconds): void;
}
