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
 * The prompt token count is the total input sent, for every provider,
 * including tokens read from or written to the provider's prompt cache.
 * The cached and cache-write counts are subsets of it:
 *
 * `uncached input = promptTokens - cachedTokens - cacheWriteTokens`
 *
 * @author Ibrahim
 */
class Usage {
    /**
     * The number of input tokens served from the provider's cache.
     *
     * @var int
     */
    private int $cachedTokens;
    /**
     * The number of input tokens written to the provider's cache by this request.
     *
     * @var int
     */
    private int $cacheWriteTokens;

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
     * If the cached and cache-write counts together exceed the prompt count,
     * they are reduced so they never do: cached tokens first, then writes.
     * The prompt count is never altered.
     *
     * @param int $promptTokens The number of tokens used by the prompt.
     *        This is the total input, including cached and cache-write tokens.
     * @param int $completionTokens The number of tokens used by the completion.
     * @param int $cachedTokens The number of prompt tokens read from the
     *        provider's cache. Default is 0.
     * @param int $cacheWriteTokens The number of prompt tokens written to the
     *        provider's cache. Default is 0.
     */
    public function __construct(
        int $promptTokens,
        int $completionTokens,
        int $cachedTokens = 0,
        int $cacheWriteTokens = 0
    ) {
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->cachedTokens = max(0, min($cachedTokens, $promptTokens));
        $this->cacheWriteTokens = max(0, min($cacheWriteTokens, $promptTokens - $this->cachedTokens));
    }

    /**
     * Reconstructs a Usage instance from its array representation.
     *
     * @param array<string, mixed> $data The serialized data with keys
     *        'prompt_tokens' and 'completion_tokens', and optionally
     *        'cached_tokens' and 'cache_write_tokens' (default 0 when absent,
     *        so data serialized before cache tracking still loads).
     *
     * @return self The reconstructed Usage instance.
     */
    public static function fromArray(array $data): self {
        return new self(
            (int) ($data['prompt_tokens'] ?? 0),
            (int) ($data['completion_tokens'] ?? 0),
            (int) ($data['cached_tokens'] ?? 0),
            (int) ($data['cache_write_tokens'] ?? 0)
        );
    }

    /**
     * Returns the number of prompt tokens served from the provider's cache.
     *
     * This is a subset of the prompt token count.
     *
     * @return int The cached token count.
     */
    public function getCachedTokens(): int {
        return $this->cachedTokens;
    }

    /**
     * Returns the number of prompt tokens written to the provider's cache.
     *
     * This is a subset of the prompt token count. It is 0 when the provider
     * does not report cache writes.
     *
     * @return int The cache-write token count.
     */
    public function getCacheWriteTokens(): int {
        return $this->cacheWriteTokens;
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
     * This is the total input, including cached and cache-write tokens.
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
            'cached_tokens' => $this->cachedTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ];
    }
}
