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
 * Represents token usage information from an AI provider response.
 *
 * Tracks how many tokens were consumed by the prompt (input) and the
 * completion (output) for cost tracking and context window management.
 *
 * @author Ibrahim
 */
class Usage {
    /**
     * The number of tokens used by the AI-generated completion.
     *
     * @var int
     */
    private int $completionTokens;

    /**
     * The number of tokens used by the input prompt/messages.
     *
     * @var int
     */
    private int $promptTokens;

    /**
     * Creates a new Usage instance.
     *
     * @param int $promptTokens The number of tokens used by the prompt.
     * @param int $completionTokens The number of tokens used by the completion.
     */
    public function __construct(int $promptTokens, int $completionTokens) {
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
    }

    /**
     * Returns the number of tokens used by the AI-generated completion.
     *
     * @return int The completion token count.
     */
    public function getCompletionTokens(): int {
        return $this->completionTokens;
    }

    /**
     * Returns the number of tokens used by the input prompt/messages.
     *
     * @return int The prompt token count.
     */
    public function getPromptTokens(): int {
        return $this->promptTokens;
    }

    /**
     * Returns the total number of tokens used (prompt + completion).
     *
     * @return int The total token count.
     */
    public function getTotalTokens(): int {
        return $this->promptTokens + $this->completionTokens;
    }
}
