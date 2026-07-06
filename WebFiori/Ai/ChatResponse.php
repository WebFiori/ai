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
 * Represents a chat completion response from an AI provider.
 *
 * Contains the AI-generated message, token usage information,
 * the model that generated the response, and the reason generation stopped.
 *
 * @author Ibrahim
 */
class ChatResponse {
    /**
     * The reason the AI stopped generating.
     *
     * Common values: 'stop' (natural end), 'length' (max tokens reached),
     * 'tool_calls' (tool invocation requested).
     *
     * @var string|null
     */
    private ?string $finishReason;

    /**
     * The AI-generated response message.
     *
     * @var Message
     */
    private Message $message;

    /**
     * The model identifier that generated this response.
     *
     * @var string
     */
    private string $model;

    /**
     * Token usage information for this response.
     *
     * @var Usage|null
     */
    private ?Usage $usage;

    /**
     * Creates a new ChatResponse instance.
     *
     * @param Message $message The AI-generated message.
     * @param string $model The model identifier that generated this response.
     * @param Usage|null $usage Token usage information, or null if not available.
     * @param string|null $finishReason The reason generation stopped.
     */
    public function __construct(
        Message $message,
        string $model,
        ?Usage $usage = null,
        ?string $finishReason = null
    ) {
        $this->message = $message;
        $this->model = $model;
        $this->usage = $usage;
        $this->finishReason = $finishReason;
    }

    /**
     * Returns the reason the AI stopped generating.
     *
     * @return string|null The finish reason, or null if not available.
     *         Common values: 'stop', 'length', 'tool_calls'.
     */
    public function getFinishReason(): ?string {
        return $this->finishReason;
    }

    /**
     * Returns the AI-generated response message.
     *
     * @return Message The response message with role 'assistant'.
     */
    public function getMessage(): Message {
        return $this->message;
    }

    /**
     * Returns the model identifier that generated this response.
     *
     * @return string The model name (e.g., 'gpt-4o', 'gemini-1.5-pro').
     */
    public function getModel(): string {
        return $this->model;
    }

    /**
     * Returns the token usage information for this response.
     *
     * @return Usage|null The usage data, or null if not reported by the provider.
     */
    public function getUsage(): ?Usage {
        return $this->usage;
    }

    /**
     * Checks if the response contains tool calls that need to be executed.
     *
     * @return bool True if the AI requested one or more tool invocations.
     */
    public function hasToolCalls(): bool {
        return $this->message->hasToolCalls();
    }
}
