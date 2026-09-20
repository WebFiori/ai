<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool;

/**
 * The structured outcome of a {@see DomainBoundaries} evaluation.
 *
 * Carries not just whether a question was blocked, but *why* — the reason, the
 * matching strategy, the specific pattern/keyword that fired, and (for the
 * semantic strategy) the similarity score. This metadata is what makes guardrail
 * decisions traceable, so operators can measure block rates and tune thresholds.
 *
 * @author Ibrahim
 */
class BoundaryDecision {
    /**
     * Reason: an adjacent-allowed keyword overrode a block match.
     */
    public const REASON_ADJACENT_OVERRIDE = 'adjacent_override';

    /**
     * Reason: the semantic similarity score fell below the configured threshold.
     */
    public const REASON_BELOW_THRESHOLD = 'below_threshold';

    /**
     * Reason: a block pattern (or keyword absence) marked the question out of scope.
     */
    public const REASON_BLOCKED = 'blocked';

    /**
     * Reason: the question is within the agent's domain.
     */
    public const REASON_IN_SCOPE = 'in_scope';

    /**
     * Reason: enforcement was suppressed by shadow (log-only) mode.
     */
    public const REASON_SHADOW = 'shadow';

    /**
     * Whether the question was blocked (redirected).
     *
     * @var bool
     */
    private bool $blocked;

    /**
     * The block pattern or keyword that fired, if any.
     *
     * @var string|null
     */
    private ?string $matchedPattern;

    /**
     * The reason code for the decision.
     *
     * @var string
     */
    private string $reason;

    /**
     * The redirect message when blocked, or null.
     *
     * @var string|null
     */
    private ?string $redirect;

    /**
     * The similarity score for the semantic strategy, or null.
     *
     * @var float|null
     */
    private ?float $score;

    /**
     * Whether the decision was recorded in shadow (log-only) mode.
     *
     * @var bool
     */
    private bool $shadow;

    /**
     * The matching strategy that produced this decision.
     *
     * @var string
     */
    private string $strategy;

    /**
     * Creates a new BoundaryDecision.
     *
     * @param bool $blocked Whether the question was blocked.
     * @param string $reason The reason code (see REASON_* constants).
     * @param string $strategy The matching strategy used.
     * @param string|null $redirect The redirect message when blocked.
     * @param string|null $matchedPattern The pattern/keyword that fired, if any.
     * @param float|null $score The similarity score (semantic strategy), if any.
     * @param bool $shadow Whether enforcement was suppressed by shadow mode.
     */
    public function __construct(
        bool $blocked,
        string $reason,
        string $strategy,
        ?string $redirect = null,
        ?string $matchedPattern = null,
        ?float $score = null,
        bool $shadow = false,
    ) {
        $this->blocked = $blocked;
        $this->reason = $reason;
        $this->strategy = $strategy;
        $this->redirect = $redirect;
        $this->matchedPattern = $matchedPattern;
        $this->score = $score;
        $this->shadow = $shadow;
    }

    /**
     * Returns the pattern or keyword that fired, if any.
     *
     * @return string|null The matched pattern, or null.
     */
    public function getMatchedPattern(): ?string {
        return $this->matchedPattern;
    }

    /**
     * Returns the reason code for the decision.
     *
     * @return string One of the REASON_* constants.
     */
    public function getReason(): string {
        return $this->reason;
    }

    /**
     * Returns the redirect message when blocked.
     *
     * @return string|null The redirect message, or null if not blocked.
     */
    public function getRedirect(): ?string {
        return $this->redirect;
    }

    /**
     * Returns the semantic similarity score, if applicable.
     *
     * @return float|null The score, or null for non-semantic strategies.
     */
    public function getScore(): ?float {
        return $this->score;
    }

    /**
     * Returns the matching strategy that produced this decision.
     *
     * @return string The strategy.
     */
    public function getStrategy(): string {
        return $this->strategy;
    }

    /**
     * Returns whether the question was blocked (redirected).
     *
     * In shadow mode this reflects what *would* have happened; the effective
     * redirect is still suppressed. Use {@see isShadow()} to distinguish.
     *
     * @return bool True if the question was (or would have been) blocked.
     */
    public function isBlocked(): bool {
        return $this->blocked;
    }

    /**
     * Returns whether the decision was recorded in shadow (log-only) mode.
     *
     * @return bool True if enforcement was suppressed.
     */
    public function isShadow(): bool {
        return $this->shadow;
    }

    /**
     * Exports the decision as a flat array suitable for metric payloads.
     *
     * @return array<string, mixed> The decision data.
     */
    public function toArray(): array {
        return [
            'blocked' => $this->blocked,
            'reason' => $this->reason,
            'strategy' => $this->strategy,
            'matched_pattern' => $this->matchedPattern,
            'score' => $this->score,
            'shadow' => $this->shadow,
        ];
    }
}
