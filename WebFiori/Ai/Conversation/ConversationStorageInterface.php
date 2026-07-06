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
 * Contract for conversation history storage backends.
 *
 * Implementations of this interface manage the persistence of chat
 * messages for ongoing conversations. Developers can implement this
 * interface with any storage backend (database, Redis, file system, etc.).
 *
 * @author Ibrahim
 */
interface ConversationStorageInterface {
    /**
     * Deletes a conversation and all its messages.
     *
     * @param string $conversationId The unique identifier of the conversation to delete.
     *
     * @return bool True if the conversation was deleted, false if it did not exist.
     */
    public function delete(string $conversationId): bool;

    /**
     * Checks if a conversation exists in storage.
     *
     * @param string $conversationId The unique identifier of the conversation.
     *
     * @return bool True if the conversation exists, false otherwise.
     */
    public function exists(string $conversationId): bool;

    /**
     * Returns a list of all stored conversation identifiers.
     *
     * @return string[] An array of conversation identifiers.
     */
    public function listConversations(): array;

    /**
     * Loads all messages for a conversation.
     *
     * @param string $conversationId The unique identifier of the conversation.
     *
     * @return Message[] An array of messages in chronological order,
     *         or an empty array if the conversation does not exist.
     */
    public function load(string $conversationId): array;

    /**
     * Saves messages for a conversation.
     *
     * This replaces any previously stored messages for the given conversation.
     *
     * @param string $conversationId The unique identifier of the conversation.
     * @param Message[] $messages The messages to save.
     */
    public function save(string $conversationId, array $messages): void;
}
