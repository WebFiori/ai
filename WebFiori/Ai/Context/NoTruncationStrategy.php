<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Context;

use WebFiori\Ai\Exception\ContextOverflowException;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolInterface;

/**
 * Strategy that throws an exception when context window is exceeded.
 *
 * Use this strategy when you want explicit control over context management
 * and prefer to fail fast rather than silently truncate messages.
 *
 * @author Ibrahim
 */
class NoTruncationStrategy implements ContextWindowStrategyInterface {
    /**
     * Maximum tokens allowed for the context.
     *
     * @var int
     */
    private int $maxTokens;

    /**
     * Tokens reserved for the completion response.
     *
     * @var int
     */
    private int $reservedTokens;

    /**
     * Token estimator instance.
     *
     * @var TokenEstimator
     */
    private TokenEstimator $tokenEstimator;

    /**
     * Creates a new NoTruncationStrategy instance.
     *
     * @param int $maxTokens Maximum tokens for the entire context window.
     * @param int $reserveForCompletion Tokens to reserve for the response.
     */
    public function __construct(int $maxTokens, int $reserveForCompletion = 4096) {
        $this->maxTokens = $maxTokens;
        $this->reservedTokens = $reserveForCompletion;
        $this->tokenEstimator = new TokenEstimator();
    }

    /**
     * Returns the maximum tokens for the context window.
     *
     * @return int Maximum tokens.
     */
    public function getMaxTokens(): int {
        return $this->maxTokens;
    }

    /**
     * Returns the number of tokens reserved for completion.
     *
     * @return int Reserved tokens.
     */
    public function getReservedTokens(): int {
        return $this->reservedTokens;
    }

    /**
     * Checks if messages fit within the limit, throws if not.
     *
     * @param Message[] $messages The conversation messages.
     * @param int $maxTokens Maximum tokens allowed.
     * @param ToolInterface[] $tools Tools that also consume tokens.
     *
     * @return Message[] The original messages (unmodified).
     *
     * @throws ContextOverflowException If messages exceed the token limit.
     */
    public function truncate(array $messages, int $maxTokens, array $tools = []): array {
        $effectiveMax = $maxTokens > 0 ? $maxTokens : $this->maxTokens;
        $availableTokens = $effectiveMax - $this->reservedTokens;

        $totalTokens = $this->tokenEstimator->count($messages, $tools);

        if ($totalTokens > $availableTokens) {
            throw new ContextOverflowException(
                sprintf(
                    'Context overflow: %d tokens required but only %d available (max: %d, reserved: %d)',
                    $totalTokens,
                    $availableTokens,
                    $effectiveMax,
                    $this->reservedTokens
                ),
                $totalTokens,
                $availableTokens
            );
        }

        return $messages;
    }
}
