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
use WebFiori\Ai\RateLimitStatus;

/**
 * HTTP client decorator that tracks rate limit headers from AI provider responses.
 *
 * After each response, parses provider-specific rate limit headers and updates
 * the current {@see RateLimitStatus}. Logs a warning when the remaining capacity
 * drops below the configured threshold.
 *
 * Supported header formats:
 * - **OpenAI**: `x-ratelimit-limit-requests`, `x-ratelimit-remaining-requests`,
 *   `x-ratelimit-reset-requests`, `x-ratelimit-limit-tokens`,
 *   `x-ratelimit-remaining-tokens`, `x-ratelimit-reset-tokens`
 * - **Anthropic**: `anthropic-ratelimit-requests-limit`,
 *   `anthropic-ratelimit-requests-remaining`, `anthropic-ratelimit-tokens-limit`,
 *   `anthropic-ratelimit-tokens-remaining`
 * - **Generic**: `x-ratelimit-remaining`, `retry-after`
 *
 * @author Ibrahim
 */
class RateLimitAwareHttpClient implements HttpClientInterface {
    /**
     * The inner HTTP client being decorated.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $inner;

    /**
     * Last known rate limit status, updated after each response.
     *
     * @var RateLimitStatus|null
     */
    private ?RateLimitStatus $lastStatus = null;

    /**
     * Optional logging callback.
     *
     * @var callable|null
     */
    private $logCallback;

    /**
     * Fraction of remaining capacity below which a warning is logged (0.0–1.0).
     *
     * Default is 0.1 (warn when less than 10% of requests remain).
     *
     * @var float
     */
    private float $warningThreshold;

    /**
     * Creates a new RateLimitAwareHttpClient.
     *
     * @param HttpClientInterface $inner The underlying HTTP client to decorate.
     * @param float $warningThreshold Fraction of remaining capacity that triggers
     *        a warning log. Default is 0.1 (10%).
     * @param callable|null $logCallback Optional logging callback.
     *        Signature: function(string $level, string $message, array $context): void
     */
    public function __construct(
        HttpClientInterface $inner,
        float $warningThreshold = 0.1,
        ?callable $logCallback = null
    ) {
        $this->inner = $inner;
        $this->warningThreshold = $warningThreshold;
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
     * Returns the last known rate limit status from the most recent response.
     *
     * Returns null if no response has been received yet or if the provider
     * does not include rate limit headers.
     *
     * @return RateLimitStatus|null The current rate limit status.
     */
    public function getLastStatus(): ?RateLimitStatus {
        return $this->lastStatus;
    }

    /**
     * Sends a request and parses rate limit headers from the response.
     *
     * @param HttpRequest $request The request to send.
     *
     * @return HttpResponse The response from the provider.
     *
     * @throws HttpException If the request fails.
     */
    public function send(HttpRequest $request): HttpResponse {
        $response = $this->inner->send($request);
        $this->processHeaders($response->getHeaders());

        return $response;
    }

    /**
     * Sends a streaming request. Rate limit headers are not parsed for streams.
     *
     * @param HttpRequest $request The request to send.
     * @param callable $onChunk Callback invoked for each data chunk.
     */
    public function sendStreaming(HttpRequest $request, callable $onChunk): void {
        $this->inner->sendStreaming($request, $onChunk);
    }

    /**
     * Logs a warning if remaining capacity is below the configured threshold.
     *
     * @param RateLimitStatus $status The current status to check.
     */
    private function checkThreshold(RateLimitStatus $status): void {
        if ($this->logCallback === null) {
            return;
        }

        $fraction = $status->getRequestsRemainingFraction();

        if ($fraction !== null && $fraction <= $this->warningThreshold) {
            ($this->logCallback)('warning', 'Rate limit capacity is low', [
                'remaining_requests' => $status->getRemainingRequests(),
                'limit_requests' => $status->getLimitRequests(),
                'remaining_fraction' => round($fraction, 3),
                'resets_at' => $status->getRequestsResetsAt()?->format(\DateTimeInterface::RFC3339),
            ]);
        }
    }

    /**
     * Parses an integer from a header value string.
     *
     * @param string|null $value The raw header value.
     *
     * @return int|null The parsed integer, or null if not parseable.
     */
    private function parseInt(?string $value): ?int {
        if ($value === null || !is_numeric(trim($value))) {
            return null;
        }

        return (int) trim($value);
    }

    /**
     * Parses OpenAI-style relative duration strings into a future DateTimeImmutable.
     *
     * Examples: "1.5s" → now + 1500ms, "200ms" → now + 200ms, "1m30s" → now + 90s
     *
     * @param string $value The duration string.
     *
     * @return \DateTimeImmutable|null The future time, or null if unparseable.
     */
    private function parseRelativeDuration(string $value): ?\DateTimeImmutable {
        $totalMs = 0;
        $matched = false;

        // Match minutes: e.g. "1m", "2m30s"
        if (preg_match('/(\d+)m/', $value, $m)) {
            $totalMs += (int) $m[1] * 60000;
            $matched = true;
        }

        // Match seconds: e.g. "1.5s", "30s"
        if (preg_match('/(\d+(?:\.\d+)?)s/', $value, $m)) {
            $totalMs += (int) ((float) $m[1] * 1000);
            $matched = true;
        }

        // Match milliseconds: e.g. "200ms"
        if (preg_match('/(\d+)ms/', $value, $m)) {
            $totalMs += (int) $m[1];
            $matched = true;
        }

        if (!$matched) {
            return null;
        }

        return (new \DateTimeImmutable())->modify("+{$totalMs} milliseconds");
    }

    /**
     * Parses a reset time from a header value.
     *
     * Supports two formats:
     * - RFC3339 datetime string (e.g. "2024-01-15T12:00:00Z")
     * - Relative duration string (e.g. "1.5s", "200ms") — offset from now
     *
     * @param string|null $value The raw header value.
     *
     * @return \DateTimeImmutable|null The parsed reset time, or null.
     */
    private function parseResetTime(?string $value): ?\DateTimeImmutable {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        // Try RFC3339 first (e.g. "2024-01-15T12:00:00Z")
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $value);

        if ($dt !== false) {
            return $dt;
        }

        // Try relative duration (e.g. "1.5s", "200ms", "1m30s")
        return $this->parseRelativeDuration($value);
    }

    /**
     * Parses rate limit headers and updates the last known status.
     *
     * @param array<string, string> $headers The response headers.
     */
    private function processHeaders(array $headers): void {
        // Normalize header names to lowercase for consistent lookup
        $h = array_change_key_case($headers, CASE_LOWER);

        $remainingRequests = $this->parseInt(
            $h['x-ratelimit-remaining-requests']
            ?? $h['anthropic-ratelimit-requests-remaining']
            ?? $h['x-ratelimit-remaining']
            ?? null
        );

        $remainingTokens = $this->parseInt(
            $h['x-ratelimit-remaining-tokens']
            ?? $h['anthropic-ratelimit-tokens-remaining']
            ?? null
        );

        $limitRequests = $this->parseInt(
            $h['x-ratelimit-limit-requests']
            ?? $h['anthropic-ratelimit-requests-limit']
            ?? null
        );

        $limitTokens = $this->parseInt(
            $h['x-ratelimit-limit-tokens']
            ?? $h['anthropic-ratelimit-tokens-limit']
            ?? null
        );

        $requestsResetsAt = $this->parseResetTime(
            $h['x-ratelimit-reset-requests'] ?? null
        );

        $tokensResetsAt = $this->parseResetTime(
            $h['x-ratelimit-reset-tokens'] ?? null
        );

        // No rate limit headers present — leave status unchanged
        if ($remainingRequests === null && $remainingTokens === null && $limitRequests === null) {
            return;
        }

        $this->lastStatus = new RateLimitStatus(
            $remainingRequests,
            $remainingTokens,
            $limitRequests,
            $limitTokens,
            $requestsResetsAt,
            $tokensResetsAt
        );

        $this->checkThreshold($this->lastStatus);
    }
}
