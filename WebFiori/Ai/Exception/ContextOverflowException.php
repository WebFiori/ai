<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Exception;

/**
 * Exception thrown when context window capacity is exceeded.
 *
 * This exception is thrown by NoTruncationStrategy when the messages
 * and tools exceed the configured token limit.
 *
 * @author Ibrahim
 */
class ContextOverflowException extends AiException {
    /**
     * Available tokens for the request.
     *
     * @var int
     */
    private int $availableTokens;

    /**
     * Required tokens for the request.
     *
     * @var int
     */
    private int $requiredTokens;

    /**
     * Creates a new ContextOverflowException.
     *
     * @param string $message The error message.
     * @param int $requiredTokens Tokens required by the request.
     * @param int $availableTokens Tokens available in the context window.
     * @param \Throwable|null $previous Previous exception.
     */
    public function __construct(
        string $message,
        int $requiredTokens,
        int $availableTokens,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->requiredTokens = $requiredTokens;
        $this->availableTokens = $availableTokens;
    }

    /**
     * Returns the available tokens in the context window.
     *
     * @return int Available tokens.
     */
    public function getAvailableTokens(): int {
        return $this->availableTokens;
    }

    /**
     * Returns the tokens required by the request.
     *
     * @return int Required tokens.
     */
    public function getRequiredTokens(): int {
        return $this->requiredTokens;
    }

    /**
     * Returns the number of tokens over the limit.
     *
     * @return int Overflow amount.
     */
    public function getOverflowTokens(): int {
        return $this->requiredTokens - $this->availableTokens;
    }
}
