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
 * Thrown when an AI provider returns an API error.
 *
 * This is the general exception for provider-side errors that are not
 * authentication failures or rate limit issues. Carries the HTTP status
 * code and provider-specific error details.
 *
 * @author Ibrahim
 */
class ProviderException extends AiException {
    /**
     * The provider-specific error code, if available.
     *
     * @var string|null
     */
    private ?string $providerErrorCode;

    /**
     * The HTTP status code returned by the provider.
     *
     * @var int
     */
    private int $statusCode;

    /**
     * Creates a new ProviderException instance.
     *
     * @param string $message A description of the provider error.
     * @param int $statusCode The HTTP status code returned by the provider.
     * @param string|null $providerErrorCode The provider-specific error code
     *        (e.g., 'model_not_found', 'invalid_request_error').
     * @param \Throwable|null $previous The previous exception, if any.
     */
    public function __construct(
        string $message,
        int $statusCode = 500,
        ?string $providerErrorCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->providerErrorCode = $providerErrorCode;
    }

    /**
     * Returns the provider-specific error code.
     *
     * @return string|null The provider error code, or null if not available.
     */
    public function getProviderErrorCode(): ?string {
        return $this->providerErrorCode;
    }

    /**
     * Returns the HTTP status code associated with this error.
     *
     * @return int The HTTP status code (e.g., 400, 500, 503).
     */
    public function getStatusCode(): int {
        return $this->statusCode;
    }
}
