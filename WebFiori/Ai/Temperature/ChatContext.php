<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Temperature;

use WebFiori\Ai\Message;

/**
 * Value object representing the chat context for temperature determination.
 *
 * Encapsulates the conversation messages and request options that a
 * temperature strategy can inspect to determine the appropriate
 * temperature setting.
 *
 * @author Ibrahim
 */
class ChatContext {
    /**
     * The conversation messages.
     *
     * @var Message[]
     */
    private readonly array $messages;

    /**
     * The request options.
     *
     * @var array
     */
    private readonly array $options;

    /**
     * Creates a new ChatContext instance.
     *
     * @param Message[] $messages The conversation messages.
     * @param array $options The request options (e.g., tools, json_mode).
     */
    public function __construct(array $messages, array $options = []) {
        $this->messages = $messages;
        $this->options = $options;
    }

    /**
     * Returns the conversation messages.
     *
     * @return Message[] The messages.
     */
    public function getMessages(): array {
        return $this->messages;
    }

    /**
     * Returns the request options.
     *
     * @return array The options.
     */
    public function getOptions(): array {
        return $this->options;
    }
}
