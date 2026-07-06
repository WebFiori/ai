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
 * has a role (system, user, assistant, tool) and content.
 *
 * @author Ibrahim
 */
class Message {
    /**
     * The message content.
     *
     * @var string
     */
    private string $content;

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
     * @param string $content The message content.
     * @param ToolCall[] $toolCalls Tool calls requested by the assistant.
     *        Only applicable for assistant messages.
     * @param ToolResult|null $toolResult The tool execution result.
     *        Only applicable for tool messages.
     */
    public function __construct(
        string $role,
        string $content,
        array $toolCalls = [],
        ?ToolResult $toolResult = null
    ) {
        $this->role = $role;
        $this->content = $content;
        $this->toolCalls = $toolCalls;
        $this->toolResult = $toolResult;
    }

    /**
     * Returns the message content.
     *
     * @return string The message text content.
     */
    public function getContent(): string {
        return $this->content;
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
     * Checks if this message contains tool calls.
     *
     * @return bool True if the message has one or more tool calls.
     */
    public function hasToolCalls(): bool {
        return count($this->toolCalls) > 0;
    }
}
