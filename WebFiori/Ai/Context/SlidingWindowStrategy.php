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

use WebFiori\Ai\Message;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\ToolInterface;

/**
 * Sliding window strategy that removes oldest messages when context is exceeded.
 *
 * This strategy preserves the system message (if configured) and removes
 * the oldest non-system messages until the conversation fits within the
 * token limit.
 *
 * @author Ibrahim
 */
class SlidingWindowStrategy implements ContextWindowStrategyInterface {
    /**
     * Maximum tokens allowed for the context.
     *
     * @var int
     */
    private int $maxTokens;

    /**
     * Whether to preserve the system message during truncation.
     *
     * @var bool
     */
    private bool $preserveSystemMessage;

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
     * Creates a new SlidingWindowStrategy instance.
     *
     * @param int $maxTokens Maximum tokens for the entire context window.
     * @param int $reserveForCompletion Tokens to reserve for the response.
     * @param bool $preserveSystemMessage Whether to never truncate the system message.
     */
    public function __construct(
        int $maxTokens,
        int $reserveForCompletion = 4096,
        bool $preserveSystemMessage = true
    ) {
        $this->maxTokens = $maxTokens;
        $this->reservedTokens = $reserveForCompletion;
        $this->preserveSystemMessage = $preserveSystemMessage;
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
     * Returns whether the system message is preserved during truncation.
     *
     * @return bool True if system message is preserved.
     */
    public function isPreserveSystemMessage(): bool {
        return $this->preserveSystemMessage;
    }

    /**
     * Truncates messages to fit within the token limit.
     *
     * Removes oldest non-system messages first. If preserveSystemMessage
     * is true, the system message is never removed.
     *
     * @param Message[] $messages The conversation messages.
     * @param int $maxTokens Maximum tokens allowed (overrides constructor value if provided).
     * @param ToolInterface[] $tools Tools that also consume tokens.
     *
     * @return Message[] The truncated messages.
     */
    public function truncate(array $messages, int $maxTokens, array $tools = []): array {
        $effectiveMax = $maxTokens > 0 ? $maxTokens : $this->maxTokens;
        $availableTokens = $effectiveMax - $this->reservedTokens;

        // Calculate tool tokens (these are fixed, can't be truncated)
        $toolTokens = $this->tokenEstimator->countTools($tools);
        $availableTokens -= $toolTokens;

        if ($availableTokens <= 0) {
            // Tools alone exceed the limit, return empty or just system
            return $this->preserveSystemMessage ? $this->extractSystemMessage($messages) : [];
        }

        // Check if already within limit
        $currentTokens = $this->tokenEstimator->countMessages($messages);

        if ($currentTokens <= $availableTokens) {
            return $messages;
        }

        // Need to truncate
        return $this->doTruncate($messages, $availableTokens);
    }

    /**
     * Performs the actual truncation.
     *
     * @param Message[] $messages The messages to truncate.
     * @param int $availableTokens Available tokens for messages.
     *
     * @return Message[] Truncated messages.
     */
    private function doTruncate(array $messages, int $availableTokens): array {
        $systemMessage = null;
        $systemTokens = 0;
        $otherMessages = [];

        // Separate system message from others
        foreach ($messages as $message) {
            if ($message->getRole() === Role::SYSTEM->value && $this->preserveSystemMessage) {
                $systemMessage = $message;
                $systemTokens = $this->tokenEstimator->countMessage($message);
            } else {
                $otherMessages[] = $message;
            }
        }

        // Reserve tokens for system message
        $availableForOthers = $availableTokens - $systemTokens;

        if ($availableForOthers <= 0) {
            // Only room for system message
            return $systemMessage !== null ? [$systemMessage] : [];
        }

        // Remove oldest messages until we fit
        // Work backwards from most recent to keep newer messages
        $keptMessages = [];
        $usedTokens = 0;

        for ($i = count($otherMessages) - 1; $i >= 0; $i--) {
            $msgTokens = $this->tokenEstimator->countMessage($otherMessages[$i]);

            if ($usedTokens + $msgTokens <= $availableForOthers) {
                array_unshift($keptMessages, $otherMessages[$i]);
                $usedTokens += $msgTokens;
            }
        }

        // Prepend system message if present
        if ($systemMessage !== null) {
            array_unshift($keptMessages, $systemMessage);
        }

        return $keptMessages;
    }

    /**
     * Extracts only the system message from messages array.
     *
     * @param Message[] $messages The messages.
     *
     * @return Message[] Array containing only the system message, or empty.
     */
    private function extractSystemMessage(array $messages): array {
        foreach ($messages as $message) {
            if ($message->getRole() === Role::SYSTEM->value) {
                return [$message];
            }
        }

        return [];
    }
}
