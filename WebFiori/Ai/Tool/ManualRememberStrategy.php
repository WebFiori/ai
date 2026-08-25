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
 * A no-op remember strategy for manual memory management.
 *
 * This strategy always returns an empty array from extract(), meaning
 * no facts are automatically remembered from conversations. Use this
 * when the developer wants full control over what is stored in memory
 * by calling AgentMemory::remember() explicitly.
 *
 * @author Ibrahim
 */
class ManualRememberStrategy implements RememberStrategyInterface {
    /**
     * Always returns an empty array since memory is managed manually.
     *
     * This is a no-op implementation. No facts are extracted from the
     * conversation; the developer is expected to call remember() directly
     * on the AgentMemory instance when appropriate.
     *
     * @param Message[] $messages The conversation messages (ignored).
     * @param string $agentResponse The agent's/model's response (ignored).
     *
     * @return string[] Always returns an empty array.
     */
    public function extract(array $messages, string $agentResponse): array {
        return [];
    }
}
