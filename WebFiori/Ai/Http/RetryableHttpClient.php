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

use WebFiori\Ai\RetryConfig;

/**
 * HTTP client decorator that adds automatic retry with exponential backoff.
 *
 * Wraps any HttpClientInterface implementation and transparently retries
 * failed requests according to the provided RetryConfig. Streaming requests
 * are not retried (mid-stream retries are unsafe).
 *
 * ### Retry conditions
 * - HTTP status codes listed in RetryConfig::getRetryableStatusCodes()
 * - Exceptions listed in RetryConfig::getRetryableExceptions()
 * - 429 responses with a Retry-After header use that value instead of backoff
 *
 * ### What is NOT retried
 * - 4xx errors other than those in retryableStatusCodes (e.g. 400, 401, 403)
 * - Streaming requests (sendStreaming delegates directly to the inner client)
 *
 * @author Ibrahim
 */
class RetryableHttpClient implements HttpClientInterface {
    /**
     * The inner HTTP client being decorated.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $inner;

    /**
     * Optional callback for logging retry events.
     *
     * @var callable|null
     */
    private $logCallback;

    /**
     * Retry configuration.
     *
     * @var RetryConfig
     */
    private RetryConfig $retryConfig;

    /**
     * Creates a new RetryableHttpClient.
     *
     * @param HttpClientInterface $inner The underlying HTTP client to decorate.
     * @param RetryConfig $retryConfig Retry configuration.
     * @param callable|null $logCallback Optional logging callback.
     *        Signature: function(string $level, string $message, array $context): void
     */
    public function __construct(
        HttpClientInterface $inner,
        RetryConfig $retryConfig,
        ?callable $logCallback = null
    ) {
        $this->inner = $inner;
        $this->retryConfig = $retryConfig;
        $this->logCallback = $logCallback;
    }

    /**
     * Returns the inner HTTP client.
     *
     * @return HttpClientInterface The decorated client.
     */
    public function getInner(): HttpClientInterface {
        return $this->inner;
    }

    /**
     * Sends an HTTP request with automatic retry on failure.
     *
     * Retries on retryable status codes and exceptions up to maxRetries times,
     * with exponential backoff and jitter between attempts. If a 429 response
     * includes a Retry-After header, that value is used as the delay.
     *
     * @param HttpRequest $request The request to send.
     *
     * @return HttpResponse The successful response.
     *
     * @throws \WebFiori\Ai\Exception\HttpException If all retry attempts fail.
     * @throws \Throwable If a non-retryable exception occurs.
     */
    public function send(HttpRequest $request): HttpResponse {
        $attempt = 0;
        $lastException = null;
        $maxAttempts = $this->retryConfig->getMaxRetries() + 1;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $response = $this->inner->send($request);

                // Check if the response status code should trigger a retry
                if (!$this->retryConfig->isRetryableStatusCode($response->getStatusCode())) {
                    return $response;
                }

                if ($attempt >= $maxAttempts) {
                    return $response;
                }

                $delayMs = $this->resolveDelay($response, $attempt);
                $this->logRetry($attempt, $this->retryConfig->getMaxRetries(), $response->getStatusCode(), $delayMs);
                $this->sleep($delayMs);
            } catch (\Throwable $e) {
                $lastException = $e;

                if (!$this->retryConfig->isRetryableException($e)) {
                    throw $e;
                }

                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                $delayMs = $this->retryConfig->calculateDelayMs($attempt);
                $this->logRetryException($attempt, $this->retryConfig->getMaxRetries(), $e, $delayMs);
                $this->sleep($delayMs);
            }
        }

        // Should not be reached, but satisfy static analysis
        if ($lastException !== null) {
            throw $lastException;
        }

        return $this->inner->send($request);
    }

    /**
     * Sends a streaming request.
     *
     * Streaming requests are not retried — mid-stream retries are unsafe
     * as partial content may have already been delivered to the caller.
     *
     * @param HttpRequest $request The request to send.
     * @param callable $onChunk Callback invoked for each data chunk.
     */
    public function sendStreaming(HttpRequest $request, callable $onChunk): void {
        $this->inner->sendStreaming($request, $onChunk);
    }

    /**
     * Logs a retry event triggered by an HTTP status code.
     *
     * @param int $attempt Current attempt number.
     * @param int $maxRetries Maximum configured retries.
     * @param int $statusCode The HTTP status code that triggered the retry.
     * @param int $delayMs Delay before next attempt in milliseconds.
     */
    private function logRetry(int $attempt, int $maxRetries, int $statusCode, int $delayMs): void {
        if ($this->logCallback === null) {
            return;
        }

        ($this->logCallback)('warning', 'Retrying request after retryable status code', [
            'attempt' => $attempt,
            'max_retries' => $maxRetries,
            'status_code' => $statusCode,
            'delay_ms' => $delayMs,
        ]);
    }

    /**
     * Logs a retry event triggered by an exception.
     *
     * @param int $attempt Current attempt number.
     * @param int $maxRetries Maximum configured retries.
     * @param \Throwable $e The exception that triggered the retry.
     * @param int $delayMs Delay before next attempt in milliseconds.
     */
    private function logRetryException(int $attempt, int $maxRetries, \Throwable $e, int $delayMs): void {
        if ($this->logCallback === null) {
            return;
        }

        ($this->logCallback)('warning', 'Retrying request after exception', [
            'attempt' => $attempt,
            'max_retries' => $maxRetries,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'delay_ms' => $delayMs,
        ]);
    }

    /**
     * Resolves the delay for the next retry attempt.
     *
     * Uses the Retry-After header value if present (for 429 responses),
     * otherwise falls back to calculated exponential backoff.
     *
     * @param HttpResponse $response The response that triggered the retry.
     * @param int $attempt The current attempt number.
     *
     * @return int Delay in milliseconds.
     */
    private function resolveDelay(HttpResponse $response, int $attempt): int {
        // Respect Retry-After header (in seconds) if present
        $retryAfter = $response->getHeader('Retry-After')
            ?? $response->getHeader('retry-after');

        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return (int) ($retryAfter * 1000); // Convert to milliseconds
        }

        return $this->retryConfig->calculateDelayMs($attempt);
    }

    /**
     * Sleeps for the given number of milliseconds.
     *
     * @param int $milliseconds The duration to sleep.
     */
    private function sleep(int $milliseconds): void {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
