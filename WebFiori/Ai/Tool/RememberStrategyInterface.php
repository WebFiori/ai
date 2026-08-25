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

use WebFiori\Ai\Message;

/**
 * Contract for strategies that decide what facts to remember from conversations.
 *
 * Implementations analyze conversation exchanges and determine whether any
 * corrections, facts, or important information should be stored in long-term
 * memory for future recall.
 *
 * @author Ibrahim
 */
interface RememberStrategyInterface {
    /**
     * Extract facts worth remembering from a conversation exchange.
     *
     * @param Message[] $messages The conversation messages.
     * @param string $agentResponse The agent's/model's response.
     *
     * @return string[] Facts to store (empty array if nothing to remember).
     */
    public function extract(array $messages, string $agentResponse): array;
}
