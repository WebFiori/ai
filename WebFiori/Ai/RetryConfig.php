<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai;

use WebFiori\Ai\Exception\HttpException;

/**
 * Configuration for automatic retry behavior on failed AI API requests.
 *
 * Retry uses exponential backoff with jitter to avoid thundering herd
 * problems. When a 429 response includes a Retry-After header, that
 * value is used instead of the calculated backoff delay.
 *
 * ### Usage
 * ```php
 * $provider->setRetryConfig(new RetryConfig(
 *     maxRetries: 3,
 *     initialDelayMs: 1000,
 *     maxDelayMs: 30000,
 *     backoffMultiplier: 2.0,
 *     retryableStatusCodes: [429, 500, 502, 503, 504],
 * ));
 * ```
 *
 * ### Backoff formula
 * ```
 * delay = min(initialDelayMs * (backoffMultiplier ^ attempt), maxDelayMs)
 * delay = delay + random_jitter(0, delay * 0.2)   // ±20% jitter
 * ```
 *
 * @author Ibrahim
 */
class RetryConfig {
    /**
     * Multiplier applied to the delay on each retry attempt.
     *
     * @var float
     */
    private float $backoffMultiplier;

    /**
     * Initial delay in milliseconds before the first retry.
     *
     * @var int
     */
    private int $initialDelayMs;

    /**
     * Maximum delay in milliseconds between retries.
     *
     * @var int
     */
    private int $maxDelayMs;

    /**
     * Maximum number of retry attempts.
     *
     * @var int
     */
    private int $maxRetries;

    /**
     * Exception class names that should trigger a retry.
     *
     * @var string[]
     */
    private array $retryableExceptions;

    /**
     * HTTP status codes that should trigger a retry.
     *
     * @var int[]
     */
    private array $retryableStatusCodes;

    /**
     * Creates a new RetryConfig instance.
     *
     * @param int $maxRetries Maximum number of retry attempts. Default is 3.
     * @param int $initialDelayMs Initial delay in milliseconds. Default is 1000 (1 second).
     * @param int $maxDelayMs Maximum delay in milliseconds. Default is 30000 (30 seconds).
     * @param float $backoffMultiplier Multiplier applied on each retry. Default is 2.0.
     * @param int[] $retryableStatusCodes HTTP status codes that trigger a retry.
     *        Defaults to [429, 500, 502, 503, 504].
     * @param string[] $retryableExceptions Exception class names that trigger a retry.
     *        Defaults to [HttpException::class].
     */
    public function __construct(
        int $maxRetries = 3,
        int $initialDelayMs = 1000,
        int $maxDelayMs = 30000,
        float $backoffMultiplier = 2.0,
        array $retryableStatusCodes = [429, 500, 502, 503, 504],
        array $retryableExceptions = [HttpException::class]
    ) {
        $this->maxRetries = $maxRetries;
        $this->initialDelayMs = $initialDelayMs;
        $this->maxDelayMs = $maxDelayMs;
        $this->backoffMultiplier = $backoffMultiplier;
        $this->retryableStatusCodes = $retryableStatusCodes;
        $this->retryableExceptions = $retryableExceptions;
    }

    /**
     * Calculates the delay in milliseconds for the given attempt number.
     *
     * Applies exponential backoff with ±20% random jitter to prevent
     * thundering herd problems. The result is capped at maxDelayMs.
     *
     * @param int $attempt The current attempt number (1-based).
     *
     * @return int The delay in milliseconds to wait before this attempt.
     */
    public function calculateDelayMs(int $attempt): int {
        $delay = (int) ($this->initialDelayMs * ($this->backoffMultiplier ** ($attempt - 1)));
        $delay = min($delay, $this->maxDelayMs);

        // Add ±20% jitter
        $jitter = (int) ($delay * 0.2 * (mt_rand() / mt_getrandmax() * 2 - 1));

        return max(0, $delay + $jitter);
    }

    /**
     * Returns the backoff multiplier.
     *
     * @return float The multiplier applied to the delay on each retry.
     */
    public function getBackoffMultiplier(): float {
        return $this->backoffMultiplier;
    }

    /**
     * Returns the initial delay in milliseconds.
     *
     * @return int The delay before the first retry attempt.
     */
    public function getInitialDelayMs(): int {
        return $this->initialDelayMs;
    }

    /**
     * Returns the maximum delay in milliseconds.
     *
     * @return int The cap applied to the calculated backoff delay.
     */
    public function getMaxDelayMs(): int {
        return $this->maxDelayMs;
    }

    /**
     * Returns the maximum number of retry attempts.
     *
     * @return int The retry limit.
     */
    public function getMaxRetries(): int {
        return $this->maxRetries;
    }

    /**
     * Returns the exception class names that trigger a retry.
     *
     * @return string[] Array of fully-qualified exception class names.
     */
    public function getRetryableExceptions(): array {
        return $this->retryableExceptions;
    }

    /**
     * Returns the HTTP status codes that trigger a retry.
     *
     * @return int[] Array of retryable HTTP status codes.
     */
    public function getRetryableStatusCodes(): array {
        return $this->retryableStatusCodes;
    }

    /**
     * Checks if the given exception should trigger a retry.
     *
     * @param \Throwable $e The exception to check.
     *
     * @return bool True if the exception is an instance of a retryable class.
     */
    public function isRetryableException(\Throwable $e): bool {
        foreach ($this->retryableExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the given HTTP status code should trigger a retry.
     *
     * @param int $statusCode The HTTP status code to check.
     *
     * @return bool True if the status code is in the retryable list.
     */
    public function isRetryableStatusCode(int $statusCode): bool {
        return in_array($statusCode, $this->retryableStatusCodes, true);
    }
}
