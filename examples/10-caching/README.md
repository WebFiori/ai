# 10 — Caching

Response caching to reduce API costs and latency for repeated queries.

## What It Demonstrates

- Setting up a cache with `setCache()`
- Configuring caching behavior with `CacheConfig`
- Cache hits for identical requests
- Temperature-based caching (only cache deterministic responses)
- Embedding caching (always deterministic)

## Files

| File | Description |
|------|-------------|
| `cache.php` | CLI script — demonstrates chat caching |
| `embeddings.php` | CLI script — demonstrates embedding caching |

## Running

```bash
# Chat caching
php examples/10-caching/cache.php

# Embedding caching
php examples/10-caching/embeddings.php
```

## Key Concepts

### Basic Setup

```php
use WebFiori\Ai\Cache\InMemoryCache;
use WebFiori\Ai\Cache\CacheConfig;

$provider->setCache(new InMemoryCache());
$provider->setCacheConfig(new CacheConfig(
    defaultTtl: 3600,        // 1 hour for chat
    embeddingTtl: 86400,     // 24 hours for embeddings
));
```

### Temperature-Based Caching

By default, only deterministic responses (temperature=0) are cached:

```php
// Only cache temperature=0 (default)
$provider->setCacheConfig(new CacheConfig(
    skipCacheAboveTemperature: 0.0,
));

// Cache all temperatures
$provider->setCacheConfig(new CacheConfig(
    skipCacheAboveTemperature: null,
));

// Cache temperature <= 0.5
$provider->setCacheConfig(new CacheConfig(
    skipCacheAboveTemperature: 0.5,
));
```

### What Gets Cached

| Operation | Cached | Reason |
|-----------|--------|--------|
| `chat()` with temperature=0 | ✅ | Deterministic |
| `chat()` with temperature>0 | Configurable | Non-deterministic |
| `chat()` with `auto_execute_tools` | ❌ | Side effects |
| `embed()` | ✅ | Always deterministic |
| `streamChat()` | ❌ | Streaming incompatible |
| `generateImage()` | ❌ | Non-deterministic |

### Custom Cache Implementations

Implement `CacheInterface` to use Redis, Memcached, files, or any other backend:

```php
use WebFiori\Ai\Cache\CacheInterface;
use WebFiori\Ai\Cache\CachedResponse;

class RedisCache implements CacheInterface {
    public function __construct(private \Redis $redis) {}
    
    public function get(string $key): ?CachedResponse {
        $data = $this->redis->get($key);
        return $data ? unserialize($data) : null;
    }
    
    public function set(string $key, CachedResponse $response, int $ttlSeconds): void {
        $this->redis->setex($key, $ttlSeconds, serialize($response));
    }
    
    public function has(string $key): bool {
        return $this->redis->exists($key) > 0;
    }
    
    public function delete(string $key): void {
        $this->redis->del($key);
    }
    
    public function clear(): void {
        $this->redis->flushDB();
    }
}
```
