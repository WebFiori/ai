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

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * Manages a conversation with an AI provider, including history persistence.
 *
 * Automatically loads history, appends user messages, sends to the provider,
 * saves the response, and maintains the conversation state across calls.
 *
 * @author Ibrahim
 */
class Conversation {
    /**
     * The conversation identifier.
     *
     * @var string
     */
    private string $id;

    /**
     * Maximum number of messages to keep in history.
     * Null means unlimited.
     *
     * @var int|null
     */
    private ?int $maxHistory;

    /**
     * The AI provider to use for generating responses.
     *
     * @var ProviderInterface
     */
    private ProviderInterface $provider;

    /**
     * The storage backend for persisting conversation history.
     *
     * @var ConversationStorageInterface
     */
    private ConversationStorageInterface $storage;

    /**
     * The system message for this conversation.
     *
     * @var string|null
     */
    private ?string $systemMessage;

    /**
     * Creates a new Conversation instance.
     *
     * @param ProviderInterface $provider The AI provider for generating responses.
     * @param ConversationStorageInterface $storage The storage backend.
     * @param string|null $id The conversation ID. If null, a unique ID is generated.
     */
    public function __construct(
        ProviderInterface $provider,
        ConversationStorageInterface $storage,
        ?string $id = null
    ) {
        $this->provider = $provider;
        $this->storage = $storage;
        $this->id = $id ?? uniqid('conv_');
        $this->systemMessage = null;
        $this->maxHistory = null;
    }

    /**
     * Returns the conversation history from storage.
     *
     * @return Message[] The stored messages for this conversation.
     */
    public function getHistory(): array {
        return $this->storage->load($this->id);
    }

    /**
     * Returns the conversation identifier.
     *
     * @return string The conversation ID.
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Returns the maximum history length.
     *
     * @return int|null The max number of messages, or null for unlimited.
     */
    public function getMaxHistory(): ?int {
        return $this->maxHistory;
    }

    /**
     * Returns the AI provider.
     *
     * @return ProviderInterface The provider instance.
     */
    public function getProvider(): ProviderInterface {
        return $this->provider;
    }

    /**
     * Returns the storage backend.
     *
     * @return ConversationStorageInterface The storage instance.
     */
    public function getStorage(): ConversationStorageInterface {
        return $this->storage;
    }

    /**
     * Returns the system message for this conversation.
     *
     * @return string|null The system message, or null if not set.
     */
    public function getSystemMessage(): ?string {
        return $this->systemMessage;
    }

    /**
     * Sends a user message and returns the AI response.
     *
     * This method:
     * 1. Loads existing conversation history from storage
     * 2. Appends the user message
     * 3. Prepends the system message (if configured)
     * 4. Sends to the AI provider
     * 5. Appends the AI response to history
     * 6. Saves updated history to storage
     * 7. Returns the response
     *
     * @param string $content The user message content.
     * @param array<string, mixed> $options Additional options passed to the provider.
     *
     * @return ChatResponse The AI-generated response.
     *
     * @throws \WebFiori\Ai\Exception\AuthenticationException If credentials are invalid.
     * @throws \WebFiori\Ai\Exception\RateLimitException If the rate limit is exceeded.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     */
    public function send(string $content, array $options = []): ChatResponse {
        $history = $this->storage->load($this->id);

        // Add user message to history
        $userMessage = new Message('user', $content);
        $history[] = $userMessage;

        // Build messages for the provider (system + history)
        $messages = $this->buildMessages($history);

        // Send to provider
        $response = $this->provider->chat($messages, $options);

        // Add assistant response to history
        $history[] = $response->getMessage();

        // Apply max history limit
        $history = $this->trimHistory($history);

        // Save updated history
        $this->storage->save($this->id, $history);

        return $response;
    }

    /**
     * Sets the conversation identifier.
     *
     * @param string $id The conversation ID.
     *
     * @return self Returns the instance for method chaining.
     */
    public function setId(string $id): self {
        $this->id = $id;

        return $this;
    }

    /**
     * Sets the maximum number of messages to keep in history.
     *
     * When the limit is exceeded, the oldest messages are removed (excluding
     * the system message which is always prepended fresh).
     *
     * @param int|null $max The maximum number of messages, or null for unlimited.
     *
     * @return self Returns the instance for method chaining.
     */
    public function setMaxHistory(?int $max): self {
        $this->maxHistory = $max;

        return $this;
    }

    /**
     * Sets the system message for this conversation.
     *
     * The system message is prepended to every request sent to the provider
     * but is not stored in the conversation history.
     *
     * @param string|null $message The system message, or null to remove it.
     *
     * @return self Returns the instance for method chaining.
     */
    public function setSystemMessage(?string $message): self {
        $this->systemMessage = $message;

        return $this;
    }

    /**
     * Builds the full message array for the provider, including system message.
     *
     * @param Message[] $history The conversation history.
     *
     * @return Message[] Messages ready to send to the provider.
     */
    private function buildMessages(array $history): array {
        $messages = [];

        if ($this->systemMessage !== null) {
            $messages[] = new Message('system', $this->systemMessage);
        }

        return array_merge($messages, $history);
    }

    /**
     * Trims history to the configured maximum length.
     *
     * @param Message[] $history The conversation history to trim.
     *
     * @return Message[] The trimmed history.
     */
    private function trimHistory(array $history): array {
        if ($this->maxHistory === null || count($history) <= $this->maxHistory) {
            return $history;
        }

        return array_slice($history, -$this->maxHistory);
    }
}
