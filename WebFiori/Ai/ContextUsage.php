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

use WebFiori\Ai\Provider\AbstractClient;

/**
 * An immutable snapshot of context window usage for a set of messages.
 *
 * Produced by {@see AbstractClient::getContextUsage()}.
 * Useful for pre-send warnings ("you've used 6k of 8k tokens") and UI gauges.
 *
 * The token ceiling ($maxTokens) may be unknown, in which case the derived
 * values (available, remaining, percentage) are null and isOverBudget() is
 * false. The used token count is always available.
 *
 * ### Typical usage
 * ```php
 * $usage = $client->getContextUsage($messages, $tools, maxTokens: 8000);
 *
 * echo $usage->getUsedTokens();      // 6200
 * echo $usage->getRemainingTokens(); // 0  (6000 available - 6200 used, floored)
 * echo $usage->getUsedPercentage();  // 77.5
 * $usage->isOverBudget();            // true
 * $usage->isEstimated();             // true
 * ```
 *
 * @author Ibrahim
 */
class ContextUsage {
    /**
     * Whether the used token count came from estimation (true) or from a
     * provider-reported response usage (false).
     *
     * @var bool
     */
    private bool $estimated;

    /**
     * The context window ceiling in tokens, or null if unknown.
     *
     * @var int|null
     */
    private ?int $maxTokens;

    /**
     * Tokens reserved for the completion (output).
     *
     * @var int
     */
    private int $reservedTokens;

    /**
     * The number of tokens consumed by the input (estimated or actual).
     *
     * @var int
     */
    private int $usedTokens;

    /**
     * Creates a new ContextUsage snapshot.
     *
     * @param int $usedTokens Tokens consumed by the input.
     * @param int|null $maxTokens Context window ceiling, or null if unknown.
     * @param int $reservedTokens Tokens reserved for the completion.
     * @param bool $estimated True if used tokens are estimated, false if actual.
     */
    public function __construct(
        int $usedTokens,
        ?int $maxTokens = null,
        int $reservedTokens = 0,
        bool $estimated = true,
    ) {
        $this->usedTokens = max(0, $usedTokens);
        $this->maxTokens = $maxTokens;
        $this->reservedTokens = max(0, $reservedTokens);
        $this->estimated = $estimated;
    }

    /**
     * Returns the tokens available for input (max minus reserved).
     *
     * @return int|null The available tokens, or null if the ceiling is unknown.
     */
    public function getAvailableTokens(): ?int {
        if ($this->maxTokens === null) {
            return null;
        }

        return max(0, $this->maxTokens - $this->reservedTokens);
    }

    /**
     * Returns the context window ceiling in tokens.
     *
     * @return int|null The ceiling, or null if unknown.
     */
    public function getMaxTokens(): ?int {
        return $this->maxTokens;
    }

    /**
     * Returns the tokens remaining for input.
     *
     * @return int|null The remaining tokens (never negative), or null if the
     *                  ceiling is unknown.
     */
    public function getRemainingTokens(): ?int {
        $available = $this->getAvailableTokens();

        if ($available === null) {
            return null;
        }

        return max(0, $available - $this->usedTokens);
    }

    /**
     * Returns the tokens reserved for the completion.
     *
     * @return int The reserved tokens.
     */
    public function getReservedTokens(): int {
        return $this->reservedTokens;
    }

    /**
     * Returns the percentage of the context window consumed.
     *
     * Computed against the total ceiling ($maxTokens), not the available
     * budget, so it reads intuitively as "how full is the window".
     *
     * @return float|null The percentage (0.0–100.0+), or null if the ceiling
     *                    is unknown.
     */
    public function getUsedPercentage(): ?float {
        if ($this->maxTokens === null || $this->maxTokens === 0) {
            return null;
        }

        return ($this->usedTokens / $this->maxTokens) * 100;
    }

    /**
     * Returns the number of tokens consumed by the input.
     *
     * @return int The used tokens (estimated or actual).
     */
    public function getUsedTokens(): int {
        return $this->usedTokens;
    }

    /**
     * Whether the used token count is estimated rather than provider-reported.
     *
     * @return bool True if estimated, false if from actual response usage.
     */
    public function isEstimated(): bool {
        return $this->estimated;
    }

    /**
     * Whether the input exceeds the available budget (max minus reserved).
     *
     * @return bool True if over budget; false if the ceiling is unknown.
     */
    public function isOverBudget(): bool {
        $available = $this->getAvailableTokens();

        if ($available === null) {
            return false;
        }

        return $this->usedTokens > $available;
    }

    /**
     * Exports the snapshot as an associative array.
     *
     * @return array{used: int, max: int|null, reserved: int, available: int|null, remaining: int|null, percentage: float|null, estimated: bool, over_budget: bool}
     */
    public function toArray(): array {
        return [
            'used' => $this->usedTokens,
            'max' => $this->maxTokens,
            'reserved' => $this->reservedTokens,
            'available' => $this->getAvailableTokens(),
            'remaining' => $this->getRemainingTokens(),
            'percentage' => $this->getUsedPercentage(),
            'estimated' => $this->estimated,
            'over_budget' => $this->isOverBudget(),
        ];
    }
}
