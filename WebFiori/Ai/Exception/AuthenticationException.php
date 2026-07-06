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
 * Thrown when authentication with an AI provider fails.
 *
 * This indicates invalid, expired, or missing API keys or credentials.
 * Corresponds to HTTP 401 responses from provider APIs.
 *
 * @author Ibrahim
 */
class AuthenticationException extends AiException {
    /**
     * The HTTP status code returned by the provider.
     *
     * @var int
     */
    private int $statusCode;

    /**
     * Creates a new AuthenticationException instance.
     *
     * @param string $message A description of the authentication failure.
     * @param int $statusCode The HTTP status code (typically 401 or 403).
     * @param \Throwable|null $previous The previous exception, if any.
     */
    public function __construct(string $message, int $statusCode = 401, ?\Throwable $previous = null) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    /**
     * Returns the HTTP status code associated with this error.
     *
     * @return int The HTTP status code (typically 401 or 403).
     */
    public function getStatusCode(): int {
        return $this->statusCode;
    }
}
