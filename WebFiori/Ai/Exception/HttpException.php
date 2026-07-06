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
 * Thrown when an HTTP transport error occurs.
 *
 * This covers connection failures, DNS resolution errors, timeouts,
 * and other network-level problems that prevent the request from
 * reaching the AI provider.
 *
 * @author Ibrahim
 */
class HttpException extends AiException {
    /**
     * The cURL error code, if applicable.
     *
     * @var int
     */
    private int $curlErrorCode;

    /**
     * Creates a new HttpException instance.
     *
     * @param string $message A description of the transport error.
     * @param int $curlErrorCode The cURL error code (e.g., CURLE_COULDNT_CONNECT).
     * @param \Throwable|null $previous The previous exception, if any.
     */
    public function __construct(string $message, int $curlErrorCode = 0, ?\Throwable $previous = null) {
        parent::__construct($message, $curlErrorCode, $previous);
        $this->curlErrorCode = $curlErrorCode;
    }

    /**
     * Returns the cURL error code.
     *
     * @return int The cURL error code, or 0 if not applicable.
     *
     * @see https://curl.se/libcurl/c/libcurl-errors.html
     */
    public function getCurlErrorCode(): int {
        return $this->curlErrorCode;
    }
}
