<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Http;

/**
 * Value object representing an HTTP response received from an AI provider API.
 *
 * @author Ibrahim
 */
class HttpResponse {
    /**
     * The response body content.
     *
     * @var string
     */
    private string $body;

    /**
     * Associative array of response headers.
     *
     * @var array<string, string>
     */
    private array $headers;

    /**
     * The HTTP status code.
     *
     * @var int
     */
    private int $statusCode;

    /**
     * Creates a new HTTP response instance.
     *
     * @param int $statusCode The HTTP status code (e.g., 200, 401, 429).
     * @param array<string, string> $headers Associative array of header name => value.
     * @param string $body The response body content.
     */
    public function __construct(int $statusCode, array $headers = [], string $body = '') {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * Returns the response body.
     *
     * @return string The raw response body content.
     */
    public function getBody(): string {
        return $this->body;
    }

    /**
     * Returns a single header value by name.
     *
     * @param string $name The name of the header to retrieve.
     *
     * @return string|null The header value, or null if not set.
     */
    public function getHeader(string $name): ?string {
        return $this->headers[$name] ?? null;
    }

    /**
     * Returns all response headers.
     *
     * @return array<string, string> Associative array of header name => value.
     */
    public function getHeaders(): array {
        return $this->headers;
    }

    /**
     * Returns the decoded JSON body as an associative array.
     *
     * @return array<string, mixed> The decoded JSON response.
     *
     * @throws \JsonException If the body is not valid JSON.
     */
    public function getJson(): array {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Returns the HTTP status code.
     *
     * @return int The HTTP status code (e.g., 200, 401, 429).
     */
    public function getStatusCode(): int {
        return $this->statusCode;
    }

    /**
     * Checks if the response indicates a successful request.
     *
     * @return bool True if the status code is in the 2xx range, false otherwise.
     */
    public function isSuccess(): bool {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
