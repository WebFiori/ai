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

use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Represents a chat message with a role, content, and optional tool interactions.
 *
 * Messages form the conversation history sent to AI providers. Each message
 * has a role (system, user, assistant, tool) and content. Content can be
 * a simple string or an array of ContentPart objects for multi-modal messages.
 *
 * Basic usage:
 * ```php
 * $message = new Message('user', 'Hello, world!');
 * ```
 *
 * Multi-modal usage:
 * ```php
 * $message = new Message('user', [
 *     ContentPart::text('What is in this image?'),
 *     ContentPart::imageUrl('https://example.com/photo.jpg'),
 * ]);
 * ```
 *
 * @author Ibrahim
 */
class Message {
    /**
     * The message content as a string (for text-only messages).
     *
     * @var string
     */
    private string $content;

    /**
     * The message content parts (for multi-modal messages).
     *
     * @var ContentPart[]
     */
    private array $contentParts;

    /**
     * Whether this message has multi-modal content.
     *
     * @var bool
     */
    private bool $isMultiModal;

    /**
     * Raw steps array from an Interactions API response.
     *
     * Used in stateless mode to replay the model's previous output in the
     * next turn. Stores the `steps[]` array as returned by the Interactions API.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $rawSteps = null;

    /**
     * The role of the message sender.
     *
     * @var string
     */
    private string $role;

    /**
     * Tool calls requested by the assistant (present only in assistant messages).
     *
     * @var ToolCall[]
     */
    private array $toolCalls;

    /**
     * Tool result associated with this message (present only in tool messages).
     *
     * @var ToolResult|null
     */
    private ?ToolResult $toolResult;

    /**
     * Creates a new message instance.
     *
     * @param string $role The role of the message sender. Valid values are
     *        'system', 'user', 'assistant', and 'tool'.
     * @param string|ContentPart[] $content The message content. Can be a string
     *        for text-only messages, or an array of ContentPart objects for
     *        multi-modal messages containing text and images.
     * @param ToolCall[] $toolCalls Tool calls requested by the assistant.
     *        Only applicable for assistant messages.
     * @param ToolResult|null $toolResult The tool execution result.
     *        Only applicable for tool messages.
     */
    public function __construct(
        string $role,
        string|array $content,
        array $toolCalls = [],
        ?ToolResult $toolResult = null
    ) {
        $this->role = $role;
        $this->toolCalls = $toolCalls;
        $this->toolResult = $toolResult;

        if (is_array($content)) {
            $this->isMultiModal = true;
            $this->contentParts = $content;
            $this->content = $this->extractTextContent($content);
        } else {
            $this->isMultiModal = false;
            $this->contentParts = [];
            $this->content = $content;
        }
    }

    /**
     * Returns the message text content.
     *
     * For text-only messages, returns the full content. For multi-modal
     * messages, returns the concatenated text from all text parts.
     *
     * @return string The message text content.
     */
    public function getContent(): string {
        return $this->content;
    }

    /**
     * Returns the content parts for multi-modal messages.
     *
     * For text-only messages, returns an array with a single text ContentPart.
     *
     * @return ContentPart[] The content parts.
     */
    public function getContentParts(): array {
        if (!$this->isMultiModal) {
            return [ContentPart::text($this->content)];
        }

        return $this->contentParts;
    }

    /**
     * Returns the raw steps from an Interactions API response.
     *
     * These are the steps as returned by the Interactions API and stored on
     * assistant messages for use in stateless multi-turn conversations.
     *
     * @return array<int, array<string, mixed>>|null The raw steps, or null if not set.
     */
    public function getRawSteps(): ?array {
        return $this->rawSteps;
    }

    /**
     * Returns the role of the message sender.
     *
     * @return string The message role ('system', 'user', 'assistant', or 'tool').
     */
    public function getRole(): string {
        return $this->role;
    }

    /**
     * Returns tool calls requested by the assistant.
     *
     * This is only populated for assistant messages where the AI requested
     * one or more tool invocations.
     *
     * @return ToolCall[] An array of tool calls, or an empty array if none.
     */
    public function getToolCalls(): array {
        return $this->toolCalls;
    }

    /**
     * Returns the tool result associated with this message.
     *
     * This is only populated for tool messages that carry the result of
     * a previously requested tool call.
     *
     * @return ToolResult|null The tool result, or null if not a tool message.
     */
    public function getToolResult(): ?ToolResult {
        return $this->toolResult;
    }

    /**
     * Checks if this message contains any image content.
     *
     * @return bool True if the message has at least one image part.
     */
    public function hasImages(): bool {
        if (!$this->isMultiModal) {
            return false;
        }

        foreach ($this->contentParts as $part) {
            if ($part->isImage()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if this message contains tool calls.
     *
     * @return bool True if the message has one or more tool calls.
     */
    public function hasToolCalls(): bool {
        return count($this->toolCalls) > 0;
    }

    /**
     * Checks if this message has multi-modal content (text + images).
     *
     * @return bool True if the message contains ContentPart objects.
     */
    public function isMultiModal(): bool {
        return $this->isMultiModal;
    }

    /**
     * Sets the raw steps from an Interactions API response.
     *
     * Used when constructing assistant messages from an Interactions API response
     * to preserve all steps (text, thoughts, function calls) for replay in the
     * next turn of a stateless conversation.
     *
     * @param array<int, array<string, mixed>> $steps The raw steps array.
     */
    public function setRawSteps(array $steps): void {
        $this->rawSteps = $steps;
    }

    /**
     * Extracts and concatenates text content from content parts.
     *
     * @param ContentPart[] $parts The content parts.
     *
     * @return string The concatenated text content.
     */
    private function extractTextContent(array $parts): string {
        $texts = [];

        foreach ($parts as $part) {
            if ($part->isText()) {
                $texts[] = $part->getText();
            }
        }

        return implode("\n", $texts);
    }
}
