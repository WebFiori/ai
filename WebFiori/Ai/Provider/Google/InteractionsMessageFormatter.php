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

use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Formats Message[] arrays into the Google Interactions API input format.
 *
 * The Interactions API (used by gemini-3.x+) uses a different message
 * structure than the legacy generateContent API:
 *
 * - User messages → {type: 'user_input', content: [...]}
 * - System messages → {type: 'system', text: '...'}
 * - Assistant messages → raw steps array from previous response
 * - Tool results → {type: 'function_result', name: '...', call_id: '...', result: [...]}
 *
 * @author Ibrahim
 */
class InteractionsMessageFormatter {
    /**
     * Extracts system messages from the messages array.
     *
     * Returns the concatenated text of all system messages, or null if none.
     *
     * @param Message[] $messages The conversation messages.
     *
     * @return string|null The system instruction text, or null if not present.
     */
    public function extractSystemInstruction(array $messages): ?string {
        $parts = [];

        foreach ($messages as $message) {
            if ($message->getRole() === 'system') {
                $parts[] = $message->getContent();
            }
        }

        return empty($parts) ? null : implode("\n", $parts);
    }

    /**
     * Formats a Message[] array into the Interactions API input array.
     *
     * System messages are excluded (handled separately via extractSystemInstruction).
     * Assistant messages with raw steps are expanded directly.
     *
     * @param Message[] $messages The conversation messages.
     *
     * @return array<int, array<string, mixed>> The formatted input array.
     */
    public function format(array $messages): array {
        $input = [];

        foreach ($messages as $message) {
            $role = $message->getRole();

            switch ($role) {
                case 'system':
                    // Handled separately
                    break;

                case 'user':
                    $input[] = $this->formatUserMessage($message);

                    break;

                case 'assistant':
                    // In stateless mode, assistant messages carry the raw steps
                    // from the previous Interactions API response so the model
                    // can see its own prior output
                    $rawSteps = $message->getRawSteps();

                    if ($rawSteps !== null) {
                        foreach ($rawSteps as $step) {
                            $input[] = $step;
                        }
                    } elseif ($message->getContent() !== '') {
                        // Plain assistant message — wrap as a model text step
                        $input[] = [
                            'type' => 'model_turn',
                            'content' => [
                                ['type' => 'text', 'text' => $message->getContent()],
                            ],
                        ];
                    }

                    break;

                case 'tool':
                case 'tool_result':
                    $result = $message->getToolResult();

                    if ($result !== null) {
                        $input[] = $this->formatToolResult($result);
                    }

                    break;
            }
        }

        return $input;
    }

    /**
     * Formats a content part into the Interactions API content item.
     *
     * @param ContentPart $part The content part.
     *
     * @return array<string, mixed> The formatted content item.
     */
    private function formatContentPart(ContentPart $part): array {
        switch ($part->getType()) {
            case ContentPart::TYPE_IMAGE_BASE64:
                $data = $part->getData();

                return [
                    'type' => 'image',
                    'image' => [
                        'image_bytes' => $data['data'] ?? '',
                        'mime_type' => $part->getMimeType() ?? 'image/jpeg',
                    ],
                ];

            case ContentPart::TYPE_IMAGE_URL:
                $data = $part->getData();

                return [
                    'type' => 'image_url',
                    'image_url' => $data['url'] ?? '',
                ];

            case ContentPart::TYPE_DOCUMENT:
            case ContentPart::TYPE_FILE_GCS:
                $data = $part->getData();

                return [
                    'type' => 'file',
                    'file' => [
                        'file_bytes' => $data['data'] ?? '',
                        'mime_type' => $part->getMimeType() ?? 'application/octet-stream',
                    ],
                ];

            default:
                return [
                    'type' => 'text',
                    'text' => $part->getText() ?? '',
                ];
        }
    }

    /**
     * Formats a tool result message into the Interactions API function_result format.
     *
     * @param ToolResult $result The tool result.
     *
     * @return array<string, mixed> The formatted function_result input item.
     */
    private function formatToolResult(ToolResult $result): array {
        return [
            'type' => 'function_result',
            'name' => $result->getName(),
            'call_id' => $result->getToolCallId(),
            'result' => [
                ['type' => 'text', 'text' => $result->getContent()],
            ],
        ];
    }

    /**
     * Formats a user message into the Interactions API user_input format.
     *
     * @param Message $message The user message.
     *
     * @return array<string, mixed> The formatted user_input item.
     */
    private function formatUserMessage(Message $message): array {
        $content = [];

        if ($message->isMultiModal()) {
            foreach ($message->getContentParts() as $part) {
                $content[] = $this->formatContentPart($part);
            }
        } else {
            $content[] = ['type' => 'text', 'text' => $message->getContent()];
        }

        return [
            'type' => 'user_input',
            'content' => $content,
        ];
    }
}
