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
 * In-memory cache implementation for testing and simple use cases.
 *
 * Entries are stored in a PHP array and lost when the process ends.
 * For production use with persistence, implement CacheInterface with
 * Redis, Memcached, files, or a database backend.
 *
 * @author Ibrahim
 */
class InMemoryCache implements CacheInterface {
    /**
     * Cache storage: key => ['response' => CachedResponse, 'expiresAt' => int].
     *
     * @var array<string, array{response: CachedResponse, expiresAt: int}>
     */
    private array $cache = [];

    /**
     * Removes all entries from the cache.
     */
    public function clear(): void {
        $this->cache = [];
    }

    /**
     * Returns the number of entries in the cache (including expired).
     *
     * Useful for testing.
     *
     * @return int The number of entries.
     */
    public function count(): int {
        return count($this->cache);
    }

    /**
     * Removes an entry from the cache.
     *
     * @param string $key The cache key.
     */
    public function delete(string $key): void {
        unset($this->cache[$key]);
    }

    /**
     * Retrieves a cached response by key.
     *
     * Returns null if the key doesn't exist or has expired.
     *
     * @param string $key The cache key.
     *
     * @return CachedResponse|null The cached response, or null.
     */
    public function get(string $key): ?CachedResponse {
        if (!$this->has($key)) {
            return null;
        }

        return $this->cache[$key]['response'];
    }

    /**
     * Checks if a non-expired entry exists for the given key.
     *
     * Automatically removes expired entries when checked.
     *
     * @param string $key The cache key.
     *
     * @return bool True if a valid entry exists.
     */
    public function has(string $key): bool {
        if (!isset($this->cache[$key])) {
            return false;
        }

        if ($this->cache[$key]['expiresAt'] < time()) {
            unset($this->cache[$key]);

            return false;
        }

        return true;
    }

    /**
     * Stores a response in the cache.
     *
     * @param string $key The cache key.
     * @param CachedResponse $response The response to cache.
     * @param int $ttlSeconds Time-to-live in seconds.
     */
    public function set(string $key, CachedResponse $response, int $ttlSeconds): void {
        $this->cache[$key] = [
            'response' => $response,
            'expiresAt' => time() + $ttlSeconds,
        ];
    }
}
