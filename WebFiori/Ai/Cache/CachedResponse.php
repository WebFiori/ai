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
 * Wrapper for cached AI responses with metadata.
 *
 * Stores the serialized response data along with information about
 * when it was cached and what type of response it contains.
 *
 * @author Ibrahim
 */
class CachedResponse {
    /**
     * Unix timestamp when this entry was created.
     *
     * @var int
     */
    private int $createdAt;

    /**
     * The cached data (serialized response).
     *
     * @var mixed
     */
    private mixed $data;

    /**
     * The type of response (e.g., 'chat', 'embedding').
     *
     * @var string
     */
    private string $type;

    /**
     * Creates a new CachedResponse instance.
     *
     * @param mixed $data The response data to cache.
     * @param string $type The response type ('chat' or 'embedding').
     * @param int|null $createdAt Unix timestamp, defaults to current time.
     */
    public function __construct(mixed $data, string $type, ?int $createdAt = null) {
        $this->data = $data;
        $this->type = $type;
        $this->createdAt = $createdAt ?? time();
    }

    /**
     * Returns the timestamp when this entry was created.
     *
     * @return int Unix timestamp.
     */
    public function getCreatedAt(): int {
        return $this->createdAt;
    }

    /**
     * Returns the cached data.
     *
     * @return mixed The cached response data.
     */
    public function getData(): mixed {
        return $this->data;
    }

    /**
     * Returns the response type.
     *
     * @return string The type ('chat' or 'embedding').
     */
    public function getType(): string {
        return $this->type;
    }
}
