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

use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\StreamingException;

/**
 * HTTP client implementation using PHP's cURL extension.
 *
 * Handles standard request/response interactions as well as streaming
 * responses via CURLOPT_WRITEFUNCTION for Server-Sent Events (SSE).
 *
 * Supports connection reuse (HTTP Keep-Alive) for improved performance
 * in multi-request scenarios like tool calling loops.
 *
 * @author Ibrahim
 */
class CurlHttpClient implements HttpClientInterface {
    /**
     * Connection timeout in seconds.
     *
     * @var int
     */
    private int $connectTimeout;

    /**
     * The host of the last request (for connection reuse validation).
     *
     * @var string|null
     */
    private ?string $lastHost = null;

    /**
     * Persistent cURL handle for connection reuse.
     *
     * @var \CurlHandle|null
     */
    private ?\CurlHandle $persistentHandle = null;

    /**
     * Whether connection reuse is enabled.
     *
     * @var bool
     */
    private bool $reuseConnection = false;

    /**
     * Request timeout in seconds.
     *
     * @var int
     */
    private int $timeout;

    /**
     * Whether to verify SSL certificates.
     *
     * @var bool
     */
    private bool $verifySsl;

    /**
     * Creates a new CurlHttpClient instance.
     *
     * @param int $timeout Request timeout in seconds. Default is 120
     *        (AI requests can be slow).
     * @param int $connectTimeout Connection timeout in seconds. Default is 10.
     * @param bool $verifySsl Whether to verify SSL certificates. Default is true.
     */
    public function __construct(int $timeout = 120, int $connectTimeout = 10, bool $verifySsl = true) {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->verifySsl = $verifySsl;
    }

    /**
     * Destructor - closes persistent handle if open.
     */
    public function __destruct() {
        $this->closeConnection();
    }

    /**
     * Closes the persistent connection if open.
     *
     * Call this when done with multiple requests to release resources.
     * The connection will be automatically closed when the client is destroyed.
     */
    public function closeConnection(): void {
        if ($this->persistentHandle !== null) {
            // curl_close() is a deprecated no-op since PHP 8.0 (CurlHandle is a
            // GC-managed object). Dropping the reference frees the handle.
            $this->persistentHandle = null;
            $this->lastHost = null;
        }
    }

    /**
     * Enables or disables connection reuse (HTTP Keep-Alive).
     *
     * When enabled, the cURL handle is reused across multiple requests,
     * avoiding TCP and SSL handshake overhead for subsequent requests
     * to the same host. This significantly improves performance in
     * scenarios with multiple sequential requests (e.g., tool calling).
     *
     * ```php
     * $client = new CurlHttpClient();
     * $client->enableConnectionReuse(true);
     * // Subsequent requests reuse the same connection
     * ```
     *
     * @param bool $enable True to enable connection reuse, false to disable.
     *
     * @return self Returns the instance for method chaining.
     */
    public function enableConnectionReuse(bool $enable = true): self {
        $this->reuseConnection = $enable;

        if (!$enable) {
            $this->closeConnection();
        }

        return $this;
    }

    /**
     * Returns the connection timeout in seconds.
     *
     * @return int The connection timeout value.
     */
    public function getConnectTimeout(): int {
        return $this->connectTimeout;
    }

    /**
     * Returns the request timeout in seconds.
     *
     * @return int The timeout value.
     */
    public function getTimeout(): int {
        return $this->timeout;
    }

    /**
     * Returns whether SSL certificate verification is enabled.
     *
     * @return bool True if SSL verification is enabled, false otherwise.
     */
    public function getVerifySsl(): bool {
        return $this->verifySsl;
    }

    /**
     * Returns whether connection reuse is enabled.
     *
     * @return bool True if connection reuse is enabled.
     */
    public function isConnectionReuseEnabled(): bool {
        return $this->reuseConnection;
    }

    /**
     * Sends an HTTP request and returns the full response.
     *
     * @param HttpRequest $request The request to send.
     *
     * @return HttpResponse The response received from the server.
     *
     * @throws HttpException If the request fails due to a transport error.
     */
    public function send(HttpRequest $request): HttpResponse {
        $ch = $this->getOrCreateHandle($request);
        $responseHeaders = [];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$responseHeaders)
        {
            $parts = explode(':', $headerLine, 2);

            if (count($parts) === 2) {
                $responseHeaders[trim($parts[0])] = trim($parts[1]);
            }

            return strlen($headerLine);
        });

        $body = curl_exec($ch);

        if ($body === false) {
            $errorCode = curl_errno($ch);
            $errorMessage = curl_error($ch);

            if (!$this->reuseConnection) {
                unset($ch);
            } else {
                // Connection may be broken, reset it
                $this->closeConnection();
            }

            throw new HttpException(
                'cURL request failed: '.$errorMessage,
                $errorCode
            );
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!$this->reuseConnection) {
            unset($ch);
        }

        return new HttpResponse($statusCode, $responseHeaders, $body);
    }

    /**
     * Sends an HTTP request and processes the response as a stream.
     *
     * Tokens are delivered incrementally via the onChunk callback as data
     * arrives from the server.
     *
     * @param HttpRequest $request The request to send.
     * @param callable $onChunk Callback invoked for each chunk of data received.
     *        The callback signature is: function(string $chunk): void
     *
     * @throws HttpException If the request fails due to a transport error.
     * @throws StreamingException If an error occurs during stream processing.
     */
    public function sendStreaming(HttpRequest $request, callable $onChunk): void {
        $ch = $this->createCurlHandle($request);
        $streamError = null;

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($onChunk, &$streamError)
        {
            try {
                $onChunk($data);
            } catch (\Throwable $e) {
                $streamError = $e;

                return 0; // Returning 0 aborts the transfer
            }

            return strlen($data);
        });

        $result = curl_exec($ch);

        if ($streamError !== null) {
            unset($ch);

            throw new StreamingException(
                'Stream processing error: '.$streamError->getMessage(),
                0,
                $streamError
            );
        }

        if ($result === false) {
            $errorCode = curl_errno($ch);
            $errorMessage = curl_error($ch);
            unset($ch);

            throw new HttpException(
                'cURL streaming request failed: '.$errorMessage,
                $errorCode
            );
        }

        unset($ch);
    }

    /**
     * Sets the connection timeout in seconds.
     *
     * @param int $seconds The connection timeout value. Must be greater than 0.
     *
     * @return self Returns the instance for method chaining.
     */
    public function setConnectTimeout(int $seconds): self {
        $this->connectTimeout = $seconds;

        return $this;
    }

    /**
     * Sets the request timeout in seconds.
     *
     * @param int $seconds The timeout value. Must be greater than 0.
     *
     * @return self Returns the instance for method chaining.
     */
    public function setTimeout(int $seconds): self {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Sets whether to verify SSL certificates.
     *
     * @param bool $verify True to enable SSL verification, false to disable.
     *        Disabling SSL verification is not recommended in production.
     *
     * @return self Returns the instance for method chaining.
     */
    public function setVerifySsl(bool $verify): self {
        $this->verifySsl = $verify;

        return $this;
    }

    /**
     * Configures a cURL handle with the request parameters.
     *
     * @param \CurlHandle $ch The cURL handle to configure.
     * @param HttpRequest $request The HTTP request.
     */
    private function configureHandle(\CurlHandle $ch, HttpRequest $request): void {
        curl_setopt($ch, CURLOPT_URL, $request->getUrl());
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        $method = $request->getMethod();

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $body = $request->getBody();

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $headers = [];

        foreach ($request->getHeaders() as $name => $value) {
            $headers[] = $name.': '.$value;
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    }

    /**
     * Creates and configures a cURL handle for the given request.
     *
     * @param HttpRequest $request The HTTP request to configure the handle for.
     *
     * @return \CurlHandle The configured cURL handle.
     *
     * @throws HttpException If the cURL handle cannot be initialized.
     */
    private function createCurlHandle(HttpRequest $request): \CurlHandle {
        $ch = curl_init();

        if ($ch === false) {
            throw new HttpException('Failed to initialize cURL handle.');
        }

        $this->configureHandle($ch, $request);

        // Enable TCP Keep-Alive for connection reuse
        if ($this->reuseConnection) {
            curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
            curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 30);
        }

        return $ch;
    }

    /**
     * Gets an existing handle or creates a new one for connection reuse.
     *
     * @param HttpRequest $request The HTTP request.
     *
     * @return \CurlHandle The cURL handle.
     *
     * @throws HttpException If the handle cannot be created.
     */
    private function getOrCreateHandle(HttpRequest $request): \CurlHandle {
        if (!$this->reuseConnection) {
            return $this->createCurlHandle($request);
        }

        // Extract host from URL
        $parsedUrl = parse_url($request->getUrl());
        $currentHost = ($parsedUrl['scheme'] ?? 'https').'://'.($parsedUrl['host'] ?? '');

        // If host changed or no handle exists, create new one
        if ($this->persistentHandle === null || $this->lastHost !== $currentHost) {
            $this->closeConnection();
            $this->persistentHandle = $this->createCurlHandle($request);
            $this->lastHost = $currentHost;
        } else {
            // Reuse handle but reconfigure for new request
            curl_reset($this->persistentHandle);
            $this->configureHandle($this->persistentHandle, $request);

            // Re-enable Keep-Alive after reset
            curl_setopt($this->persistentHandle, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($this->persistentHandle, CURLOPT_TCP_KEEPIDLE, 60);
            curl_setopt($this->persistentHandle, CURLOPT_TCP_KEEPINTVL, 30);
        }

        return $this->persistentHandle;
    }
}
