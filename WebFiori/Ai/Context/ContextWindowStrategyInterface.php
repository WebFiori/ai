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
use WebFiori\Ai\Tool\ToolInterface;

/**
 * Contract for context window management strategies.
 *
 * Implementations decide how to handle messages that exceed the model's
 * context window limit. Common strategies include removing oldest messages,
 * throwing exceptions, or summarizing old content.
 *
 * @author Ibrahim
 */
interface ContextWindowStrategyInterface {
    /**
     * Truncates messages to fit within the token limit.
     *
     * @param Message[] $messages The conversation messages.
     * @param int $maxTokens Maximum tokens allowed for input.
     * @param ToolInterface[] $tools Tools that also consume tokens.
     *
     * @return Message[] The truncated messages that fit within the limit.
     *
     * @throws \WebFiori\Ai\Exception\ContextOverflowException If strategy
     *         does not support truncation and limit is exceeded.
     */
    public function truncate(array $messages, int $maxTokens, array $tools = []): array;

    /**
     * Returns the number of tokens reserved for the completion response.
     *
     * @return int Tokens reserved for output.
     */
    public function getReservedTokens(): int;
}
