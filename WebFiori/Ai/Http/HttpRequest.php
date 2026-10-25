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

use WebFiori\Ai\Cancellation\CancellationToken;

/**
 * Value object representing an outbound HTTP request to an AI provider API.
 *
 * @author Ibrahim
 */
class HttpRequest {
    /**
     * The request body content.
     *
     * @var string|null
     */
    private ?string $body;

    /**
     * An optional cancellation token for aborting the request in flight.
     *
     * @var CancellationToken|null
     */
    private ?CancellationToken $cancellationToken;

    /**
     * Associative array of HTTP headers.
     *
     * @var array<string, string>
     */
    private array $headers;

    /**
     * The HTTP method (GET, POST, etc.).
     *
     * @var string
     */
    private string $method;

    /**
     * The full URL to send the request to.
     *
     * @var string
     */
    private string $url;

    /**
     * Creates a new HTTP request instance.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.).
     * @param string $url The full URL to send the request to.
     * @param array<string, string> $headers Associative array of header name => value.
     * @param string|null $body The request body (typically JSON-encoded).
     * @param CancellationToken|null $cancellationToken Optional token for
     *        aborting the request in flight.
     */
    public function __construct(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?CancellationToken $cancellationToken = null
    ) {
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->cancellationToken = $cancellationToken;
    }

    /**
     * Returns the request body.
     *
     * @return string|null The request body, or null if no body is set.
     */
    public function getBody(): ?string {
        return $this->body;
    }

    /**
     * Returns the cancellation token associated with this request, if any.
     *
     * @return CancellationToken|null The token, or null if the request is not
     *         cancellable.
     */
    public function getCancellationToken(): ?CancellationToken {
        return $this->cancellationToken;
    }

    /**
     * Returns a copy of this request carrying the given cancellation token.
     *
     * The original request is left unchanged (immutable value object). Passing
     * null returns a copy with no token.
     *
     * @param CancellationToken|null $token The token to attach, or null.
     *
     * @return self A new request instance with the token applied.
     */
    public function withCancellationToken(?CancellationToken $token): self {
        return new self($this->method, $this->url, $this->headers, $this->body, $token);
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
     * Returns all request headers.
     *
     * @return array<string, string> Associative array of header name => value.
     */
    public function getHeaders(): array {
        return $this->headers;
    }

    /**
     * Returns the HTTP method.
     *
     * @return string The HTTP method in uppercase (e.g., 'POST', 'GET').
     */
    public function getMethod(): string {
        return $this->method;
    }

    /**
     * Returns the request URL.
     *
     * @return string The full URL of the request.
     */
    public function getUrl(): string {
        return $this->url;
    }
}
