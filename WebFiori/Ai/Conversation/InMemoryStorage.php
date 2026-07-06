<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Conversation;

use WebFiori\Ai\Message;

/**
 * In-memory conversation storage for testing and short-lived conversations.
 *
 * Stores messages in a PHP array. Data is lost when the process ends.
 * Use this for unit tests or single-request scenarios. For production,
 * implement {@see ConversationStorageInterface} with a persistent backend.
 *
 * @author Ibrahim
 */
class InMemoryStorage implements ConversationStorageInterface {
    /**
     * Stored conversations indexed by ID.
     *
     * @var array<string, Message[]>
     */
    private array $conversations = [];

    /**
     * Deletes a conversation and all its messages.
     *
     * @param string $conversationId The unique identifier of the conversation.
     *
     * @return bool True if the conversation was deleted, false if it did not exist.
     */
    public function delete(string $conversationId): bool {
        if (!isset($this->conversations[$conversationId])) {
            return false;
        }

        unset($this->conversations[$conversationId]);

        return true;
    }

    /**
     * Checks if a conversation exists in storage.
     *
     * @param string $conversationId The unique identifier of the conversation.
     *
     * @return bool True if the conversation exists, false otherwise.
     */
    public function exists(string $conversationId): bool {
        return isset($this->conversations[$conversationId]);
    }

    /**
     * Returns a list of all stored conversation identifiers.
     *
     * @return string[] An array of conversation identifiers.
     */
    public function listConversations(): array {
        return array_keys($this->conversations);
    }

    /**
     * Loads all messages for a conversation.
     *
     * @param string $conversationId The unique identifier of the conversation.
     *
     * @return Message[] An array of messages in chronological order,
     *         or an empty array if the conversation does not exist.
     */
    public function load(string $conversationId): array {
        return $this->conversations[$conversationId] ?? [];
    }

    /**
     * Saves messages for a conversation.
     *
     * This replaces any previously stored messages for the given conversation.
     *
     * @param string $conversationId The unique identifier of the conversation.
     * @param Message[] $messages The messages to save.
     */
    public function save(string $conversationId, array $messages): void {
        $this->conversations[$conversationId] = $messages;
    }
}
