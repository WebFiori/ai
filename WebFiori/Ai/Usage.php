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
     * Reconstructs a Usage instance from its array representation.
     *
     * @param array<string, mixed> $data The serialized data with keys
     *        'prompt_tokens' and 'completion_tokens'.
     *
     * @return self The reconstructed Usage instance.
     */
    public static function fromArray(array $data): self {
        return new self(
            (int) ($data['prompt_tokens'] ?? 0),
            (int) ($data['completion_tokens'] ?? 0)
        );
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

    /**
     * Exports the usage data to a JSON-safe associative array.
     *
     * @return array<string, mixed> The serialized usage data.
     */
    public function toArray(): array {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
        ];
    }
}
