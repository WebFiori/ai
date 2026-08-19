<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Google;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Usage;

/**
 * Parses Google Interactions API responses into standard library types.
 *
 * The Interactions API returns a steps[] array instead of the candidates[]
 * structure used by generateContent. Steps can be:
 * - text: the model's final answer
 * - thought: internal reasoning (skipped by default)
 * - function_call: tool invocation request
 *
 * The interaction `id` is stored as the response's requestId to enable
 * stateful follow-up requests via `previous_interaction_id`.
 *
 * @author Ibrahim
 */
class InteractionsResponseParser {
    /**
     * Parses an Interactions API response body into a ChatResponse.
     *
     * @param array<string, mixed> $data The decoded JSON response body.
     * @param string $defaultModel The default model name (used if not in response).
     *
     * @return ChatResponse The parsed response.
     */
    public function parse(array $data, string $defaultModel): ChatResponse {
        $steps = $data['steps'] ?? [];
        $interactionId = $data['id'] ?? null;
        $model = $data['model'] ?? $defaultModel;

        $content = '';
        $toolCalls = [];
        $rawSteps = [];

        foreach ($steps as $step) {
            $type = $step['type'] ?? '';
            $rawSteps[] = $step;

            switch ($type) {
                case 'text':
                    // Simple text step (original spec)
                    $content .= $step['text'] ?? '';

                    break;

                case 'model_output':
                    // Real gemini-3.5-flash format: content[] array with typed parts
                    foreach ($step['content'] ?? [] as $part) {
                        if (($part['type'] ?? '') === 'text') {
                            $content .= $part['text'] ?? '';
                        } elseif (($part['type'] ?? '') === 'function_call') {
                            $toolCall = new ToolCall(
                                $part['id'] ?? uniqid('call_'),
                                $part['name'] ?? '',
                                $part['arguments'] ?? []
                            );
                            $toolCall->setRawPart($part);
                            $toolCalls[] = $toolCall;
                        }
                    }

                    break;

                case 'thought':
                    // Internal reasoning — skip from visible content
                    // but preserve in rawSteps for stateless replay
                    break;

                case 'function_call':
                    // Original spec function call step
                    $toolCall = new ToolCall(
                        $step['id'] ?? uniqid('call_'),
                        $step['name'] ?? '',
                        $step['arguments'] ?? []
                    );
                    $toolCall->setRawPart($step);
                    $toolCalls[] = $toolCall;

                    break;
            }
        }

        $message = new Message('assistant', $content, $toolCalls);

        // Store raw steps on the message so stateless follow-up turns
        // can replay the model's full output (including thoughts and function_calls)
        if (!empty($rawSteps)) {
            $message->setRawSteps($rawSteps);
        }

        $usage = $this->parseUsage($data);
        $finishReason = $this->parseFinishReason($steps, $toolCalls);

        return new ChatResponse(
            $message,
            $model,
            $usage,
            $finishReason,
            $interactionId
        );
    }

    /**
     * Determines the finish reason from the steps and tool calls.
     *
     * @param array<int, array<string, mixed>> $steps The response steps.
     * @param ToolCall[] $toolCalls Parsed tool calls.
     *
     * @return string The finish reason.
     */
    private function parseFinishReason(array $steps, array $toolCalls): string {
        if (!empty($toolCalls)) {
            return 'tool_calls';
        }

        if (empty($steps)) {
            return 'stop';
        }

        // Check if last step indicates a stop or length limit
        $lastStep = end($steps);
        $lastType = $lastStep['type'] ?? '';

        if ($lastType === 'text') {
            return 'stop';
        }

        return 'stop';
    }

    /**
     * Parses usage metadata from the response.
     *
     * @param array<string, mixed> $data The decoded response body.
     *
     * @return Usage|null The usage object, or null if not available.
     */
    private function parseUsage(array $data): ?Usage {
        $usage = $data['usage'] ?? null;

        if ($usage === null) {
            return null;
        }

        return new Usage(
            // Support both spec format (input_tokens) and real format (total_input_tokens)
            $usage['input_tokens'] ?? $usage['total_input_tokens'] ?? 0,
            $usage['output_tokens'] ?? $usage['total_output_tokens'] ?? 0
        );
    }
}
