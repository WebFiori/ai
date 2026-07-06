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
 * Thrown when an AI provider's rate limit has been exceeded.
 *
 * Corresponds to HTTP 429 responses. Includes the retry-after duration
 * when provided by the provider so callers can wait before retrying.
 *
 * @author Ibrahim
 */
class RateLimitException extends AiException {
    /**
     * The number of seconds to wait before retrying.
     *
     * @var int|null
     */
    private ?int $retryAfterSeconds;

    /**
     * Creates a new RateLimitException instance.
     *
     * @param string $message A description of the rate limit error.
     * @param int|null $retryAfterSeconds The number of seconds to wait before
     *        retrying, or null if not provided by the provider.
     * @param \Throwable|null $previous The previous exception, if any.
     */
    public function __construct(string $message, ?int $retryAfterSeconds = null, ?\Throwable $previous = null) {
        parent::__construct($message, 429, $previous);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    /**
     * Returns the number of seconds to wait before retrying.
     *
     * @return int|null The retry-after duration in seconds, or null if
     *         the provider did not include this information.
     */
    public function getRetryAfterSeconds(): ?int {
        return $this->retryAfterSeconds;
    }
}
