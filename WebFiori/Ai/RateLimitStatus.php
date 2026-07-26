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

/**
 * Represents the current rate limit status reported by an AI provider.
 *
 * Populated from response headers after each API call. Values reflect
 * the state at the time of the last response — not real-time.
 *
 * ### Typical usage
 * ```php
 * $response = $provider->chat($messages);
 * $status = $provider->getRateLimitStatus();
 *
 * if ($status !== null) {
 *     echo $status->getRemainingRequests(); // e.g. 47
 *     echo $status->getResetsAt()?->format('H:i:s');
 * }
 * ```
 *
 * @author Ibrahim
 */
class RateLimitStatus {
    /**
     * Maximum requests allowed per window.
     *
     * @var int|null
     */
    private ?int $limitRequests;

    /**
     * Maximum tokens allowed per window.
     *
     * @var int|null
     */
    private ?int $limitTokens;

    /**
     * Remaining requests in the current window.
     *
     * @var int|null
     */
    private ?int $remainingRequests;

    /**
     * Remaining tokens in the current window.
     *
     * @var int|null
     */
    private ?int $remainingTokens;

    /**
     * When the request limit resets.
     *
     * @var \DateTimeImmutable|null
     */
    private ?\DateTimeImmutable $requestsResetsAt;

    /**
     * When the token limit resets.
     *
     * @var \DateTimeImmutable|null
     */
    private ?\DateTimeImmutable $tokensResetsAt;

    /**
     * Creates a new RateLimitStatus instance.
     *
     * @param int|null $remainingRequests Remaining requests in current window.
     * @param int|null $remainingTokens Remaining tokens in current window.
     * @param int|null $limitRequests Maximum requests per window.
     * @param int|null $limitTokens Maximum tokens per window.
     * @param \DateTimeImmutable|null $requestsResetsAt When request limit resets.
     * @param \DateTimeImmutable|null $tokensResetsAt When token limit resets.
     */
    public function __construct(
        ?int $remainingRequests = null,
        ?int $remainingTokens = null,
        ?int $limitRequests = null,
        ?int $limitTokens = null,
        ?\DateTimeImmutable $requestsResetsAt = null,
        ?\DateTimeImmutable $tokensResetsAt = null
    ) {
        $this->remainingRequests = $remainingRequests;
        $this->remainingTokens = $remainingTokens;
        $this->limitRequests = $limitRequests;
        $this->limitTokens = $limitTokens;
        $this->requestsResetsAt = $requestsResetsAt;
        $this->tokensResetsAt = $tokensResetsAt;
    }

    /**
     * Returns the maximum requests allowed per window.
     *
     * @return int|null The request limit, or null if not reported by the provider.
     */
    public function getLimitRequests(): ?int {
        return $this->limitRequests;
    }

    /**
     * Returns the maximum tokens allowed per window.
     *
     * @return int|null The token limit, or null if not reported by the provider.
     */
    public function getLimitTokens(): ?int {
        return $this->limitTokens;
    }

    /**
     * Returns the remaining requests in the current window.
     *
     * @return int|null Remaining requests, or null if not reported.
     */
    public function getRemainingRequests(): ?int {
        return $this->remainingRequests;
    }

    /**
     * Returns the remaining tokens in the current window.
     *
     * @return int|null Remaining tokens, or null if not reported.
     */
    public function getRemainingTokens(): ?int {
        return $this->remainingTokens;
    }

    /**
     * Returns the fraction of request capacity remaining (0.0 to 1.0).
     *
     * Returns null if limit or remaining is not available.
     *
     * @return float|null Fraction of requests remaining, or null if unavailable.
     */
    public function getRequestsRemainingFraction(): ?float {
        if ($this->limitRequests === null || $this->limitRequests === 0 || $this->remainingRequests === null) {
            return null;
        }

        return $this->remainingRequests / $this->limitRequests;
    }

    /**
     * Returns when the request rate limit resets.
     *
     * @return \DateTimeImmutable|null The reset time, or null if not reported.
     */
    public function getRequestsResetsAt(): ?\DateTimeImmutable {
        return $this->requestsResetsAt;
    }

    /**
     * Returns when the token rate limit resets.
     *
     * @return \DateTimeImmutable|null The reset time, or null if not reported.
     */
    public function getTokensResetsAt(): ?\DateTimeImmutable {
        return $this->tokensResetsAt;
    }

    /**
     * Returns whether either limit (requests or tokens) is fully exhausted.
     *
     * @return bool True if remaining requests or tokens is zero.
     */
    public function isExhausted(): bool {
        return ($this->remainingRequests !== null && $this->remainingRequests === 0)
            || ($this->remainingTokens !== null && $this->remainingTokens === 0);
    }
}
