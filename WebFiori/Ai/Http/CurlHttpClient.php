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
     * Sends an HTTP request and returns the full response.
     *
     * @param HttpRequest $request The request to send.
     *
     * @return HttpResponse The response received from the server.
     *
     * @throws HttpException If the request fails due to a transport error.
     */
    public function send(HttpRequest $request): HttpResponse {
        $ch = $this->createCurlHandle($request);
        $responseHeaders = [];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$responseHeaders) {
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
            curl_close($ch);

            throw new HttpException(
                'cURL request failed: '.$errorMessage,
                $errorCode
            );
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($onChunk, &$streamError) {
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
            curl_close($ch);

            throw new StreamingException(
                'Stream processing error: '.$streamError->getMessage(),
                0,
                $streamError
            );
        }

        if ($result === false) {
            $errorCode = curl_errno($ch);
            $errorMessage = curl_error($ch);
            curl_close($ch);

            throw new HttpException(
                'cURL streaming request failed: '.$errorMessage,
                $errorCode
            );
        }

        curl_close($ch);
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

        curl_setopt($ch, CURLOPT_URL, $request->getUrl());
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        $method = $request->getMethod();

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else if ($method !== 'GET') {
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

        return $ch;
    }
}
