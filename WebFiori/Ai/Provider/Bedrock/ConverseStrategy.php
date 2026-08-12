<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Bedrock;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Usage;

/**
 * Bedrock Converse API invocation strategy.
 *
 * Uses the unified Converse API which works with all supported models
 * using a single standardized request format. AWS handles translation
 * to each model's native format automatically.
 *
 * This is the recommended strategy for most use cases.
 *
 * @author Ibrahim
 */
class ConverseStrategy implements InvocationStrategyInterface {
    /**
     * Builds the HTTP request for a chat completion using the Converse API.
     *
     * @param string $modelId The full Bedrock model ID.
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     * @param BedrockClient $client The parent client.
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildChatRequest(
        string $modelId,
        array $messages,
        array $options,
        BedrockClient $client
    ): HttpRequest {
        $body = $this->buildBody($messages, $options, $client);
        $url = $client->getBedrockEndpoint($modelId, 'converse');
        $jsonBody = json_encode($body);

        return new HttpRequest(
            'POST',
            $url,
            $client->getBedrockHeaders('POST', $url, $jsonBody),
            $jsonBody
        );
    }

    /**
     * Builds the HTTP request for a streaming chat using the Converse API.
     *
     * @param string $modelId The full Bedrock model ID.
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     * @param BedrockClient $client The parent client.
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildStreamChatRequest(
        string $modelId,
        array $messages,
        array $options,
        BedrockClient $client
    ): HttpRequest {
        $body = $this->buildBody($messages, $options, $client);
        $url = $client->getBedrockEndpoint($modelId, 'converse-stream');
        $jsonBody = json_encode($body);

        // Converse stream uses AWS Event Stream binary framing
        $headers = $client->getBedrockHeaders('POST', $url, $jsonBody);
        $headers['Accept'] = 'application/vnd.amazon.eventstream';

        return new HttpRequest(
            'POST',
            $url,
            $headers,
            $jsonBody
        );
    }

    /**
     * Executes the streaming chat request.
     *
     * @param HttpRequest $request The HTTP request to send.
     * @param BedrockClient $client The parent client.
     * @param callable $onToken Token callback.
     * @param callable|null $onComplete Completion callback.
     * @param callable|null $onError Error callback.
     */
    public function doStreamChat(
        HttpRequest $request,
        BedrockClient $client,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void {
        $accumulatedContent = '';
        $finishReason = null;
        $inputTokens = 0;
        $outputTokens = 0;
        $toolCalls = [];
        $currentToolCall = null;
        $modelId = $client->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');

        $parser = new EventStreamParser(
            function (string $eventType, array $json) use (
                $onToken,
                &$accumulatedContent,
                &$finishReason,
                &$inputTokens,
                &$outputTokens,
                &$toolCalls,
                &$currentToolCall
            ) {
                switch ($eventType) {
                    case 'messageStart':
                        break;

                    case 'contentBlockStart':
                        $block = $json['contentBlockStart']['start'] ?? [];

                        if (isset($block['toolUse'])) {
                            $currentToolCall = [
                                'id' => $block['toolUse']['toolUseId'] ?? '',
                                'name' => $block['toolUse']['name'] ?? '',
                                'arguments' => '',
                            ];
                        }

                        break;

                    case 'contentBlockDelta':
                        $delta = $json['delta'] ?? [];

                        if (isset($delta['text'])) {
                            $text = $delta['text'];
                            $accumulatedContent .= $text;
                            $onToken($text);
                        } elseif (isset($delta['toolUse']['input']) && $currentToolCall !== null) {
                            $currentToolCall['arguments'] .= $delta['toolUse']['input'];
                        }

                        break;

                    case 'contentBlockStop':
                        if ($currentToolCall !== null) {
                            $toolCalls[] = $currentToolCall;
                            $currentToolCall = null;
                        }

                        break;

                    case 'messageStop':
                        $finishReason = $this->mapStopReason($json['stopReason'] ?? null);

                        break;

                    case 'metadata':
                        $usage = $json['usage'] ?? [];
                        $inputTokens = $usage['inputTokens'] ?? $inputTokens;
                        $outputTokens = $usage['outputTokens'] ?? $outputTokens;

                        break;
                }
            }
        );

        try {
            $client->getHttpClient()->sendStreaming($request, function (string $chunk) use ($parser)
            {
                $parser->feed($chunk);
            });

            if ($onComplete !== null) {
                $parsedToolCalls = [];

                foreach ($toolCalls as $tc) {
                    $args = json_decode($tc['arguments'], true) ?? [];
                    $parsedToolCalls[] = new ToolCall($tc['id'], $tc['name'], $args);
                }

                $message = new Message('assistant', $accumulatedContent, $parsedToolCalls);
                $usage = new Usage($inputTokens, $outputTokens);
                $response = new ChatResponse($message, $modelId, $usage, $finishReason ?? 'stop');
                $onComplete($response);
            }
        } catch (StreamingException $e) {
            if ($onError !== null) {
                $onError($e);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Parses a Converse API response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from Bedrock.
     * @param string $modelId The model ID used for the request.
     *
     * @return ChatResponse The parsed chat response.
     */
    public function parseChatResponse(HttpResponse $response, string $modelId): ChatResponse {
        $data = $response->getJson();

        $content = '';
        $toolCalls = [];

        foreach ($data['output']['message']['content'] ?? [] as $block) {
            if (isset($block['text'])) {
                $content .= $block['text'];
            } elseif (isset($block['toolUse'])) {
                $toolCalls[] = new ToolCall(
                    $block['toolUse']['toolUseId'],
                    $block['toolUse']['name'],
                    $block['toolUse']['input'] ?? []
                );
            }
        }

        $message = new Message('assistant', $content, $toolCalls);

        $usage = null;

        if (isset($data['usage'])) {
            $usage = new Usage(
                $data['usage']['inputTokens'] ?? 0,
                $data['usage']['outputTokens'] ?? 0
            );
        }

        $finishReason = $this->mapStopReason($data['stopReason'] ?? null);

        return new ChatResponse($message, $modelId, $usage, $finishReason);
    }

    /**
     * Builds the Converse API request body.
     *
     * @param Message[] $messages The messages.
     * @param array $options Request options.
     * @param BedrockClient $client The parent client.
     *
     * @return array The request body.
     */
    private function buildBody(array $messages, array $options, BedrockClient $client): array {
        $maxTokens = $options['max_tokens'] ?? $client->getConfig('max_tokens', 4096);

        $body = [
            'messages' => $this->formatMessages($messages),
            'inferenceConfig' => [
                'maxTokens' => $maxTokens,
            ],
        ];

        if (isset($options['temperature'])) {
            $body['inferenceConfig']['temperature'] = $options['temperature'];
        }

        if (isset($options['top_p'])) {
            $body['inferenceConfig']['topP'] = $options['top_p'];
        }

        $system = $this->extractSystem($messages);

        if ($system !== null) {
            $body['system'] = $system;
        }

        if (isset($options['tools']) && count($options['tools']) > 0) {
            $body['toolConfig'] = $this->formatTools($options['tools']);
        }

        return $body;
    }

    /**
     * Extracts the system message from messages array.
     *
     * @param Message[] $messages The messages.
     *
     * @return array|null Formatted system message for Converse API, or null.
     */
    private function extractSystem(array $messages): ?array {
        foreach ($messages as $message) {
            if ($message->getRole() === 'system') {
                return [['text' => $message->getContent()]];
            }
        }

        return null;
    }

    /**
     * Formats messages for the Converse API format.
     *
     * @param Message[] $messages The messages to format.
     *
     * @return array Formatted messages array.
     */
    private function formatMessages(array $messages): array {
        $formatted = [];

        foreach ($messages as $message) {
            if ($message->getRole() === 'system') {
                continue;
            }

            if ($message->getToolResult() !== null) {
                $formatted[] = [
                    'role' => 'user',
                    'content' => [[
                        'toolResult' => [
                            'toolUseId' => $message->getToolResult()->getToolCallId(),
                            'content' => [['text' => $message->getToolResult()->getContent()]],
                        ],
                    ]],
                ];

                continue;
            }

            if ($message->hasToolCalls()) {
                $content = [];

                // Handle multi-modal content
                if ($message->isMultiModal()) {
                    $content = array_merge($content, $this->formatContentParts($message->getContentParts()));
                } elseif (!empty($message->getContent())) {
                    $content[] = ['text' => $message->getContent()];
                }

                foreach ($message->getToolCalls() as $toolCall) {
                    $content[] = [
                        'toolUse' => [
                            'toolUseId' => $toolCall->getId(),
                            'name' => $toolCall->getName(),
                            'input' => $toolCall->getArguments(),
                        ],
                    ];
                }

                $formatted[] = ['role' => 'assistant', 'content' => $content];

                continue;
            }

            // Handle multi-modal messages
            if ($message->isMultiModal()) {
                $formatted[] = [
                    'role' => $message->getRole(),
                    'content' => $this->formatContentParts($message->getContentParts()),
                ];

                continue;
            }

            $formatted[] = [
                'role' => $message->getRole(),
                'content' => [['text' => $message->getContent()]],
            ];
        }

        return $formatted;
    }

    /**
     * Formats ContentPart objects into Bedrock Converse API content format.
     *
     * @param \WebFiori\Ai\ContentPart[] $parts The content parts.
     *
     * @return array<int, array<string, mixed>> The formatted content array.
     */
    private function formatContentParts(array $parts): array {
        $formatted = [];

        foreach ($parts as $part) {
            switch ($part->getType()) {
                case \WebFiori\Ai\ContentPart::TYPE_TEXT:
                    $formatted[] = ['text' => $part->getData()['text']];

                    break;

                case \WebFiori\Ai\ContentPart::TYPE_IMAGE_URL:
                    // Bedrock requires base64 data, fetch the file
                    $url = $part->getData()['url'];
                    $fileData = $this->fetchFileFromUrl($url);

                    if ($fileData !== null) {
                        $formatted[] = [
                            'image' => [
                                'format' => $this->mimeToFormat($fileData['mime_type']),
                                'source' => [
                                    'bytes' => $fileData['data'],
                                ],
                            ],
                        ];
                    }

                    break;

                case \WebFiori\Ai\ContentPart::TYPE_IMAGE_BASE64:
                    $data = $part->getData();
                    $formatted[] = [
                        'image' => [
                            'format' => $this->mimeToFormat($data['mime_type']),
                            'source' => [
                                'bytes' => $data['data'],
                            ],
                        ],
                    ];

                    break;

                case \WebFiori\Ai\ContentPart::TYPE_DOCUMENT:
                    $data = $part->getData();
                    $mimeType = $data['mime_type'];

                    if (str_starts_with($mimeType, 'image/')) {
                        $formatted[] = [
                            'image' => [
                                'format' => $this->mimeToFormat($mimeType),
                                'source' => [
                                    'bytes' => $data['data'],
                                ],
                            ],
                        ];
                    } elseif ($mimeType === 'application/pdf') {
                        // Bedrock Converse API supports documents
                        $formatted[] = [
                            'document' => [
                                'format' => 'pdf',
                                'name' => 'document',
                                'source' => [
                                    'bytes' => $data['data'],
                                ],
                            ],
                        ];
                    } else {
                        // For text-based documents, convert to text
                        $decoded = base64_decode($data['data']);
                        $formatted[] = ['text' => $decoded];
                    }

                    break;

                case \WebFiori\Ai\ContentPart::TYPE_FILE_GCS:
                    // Bedrock doesn't support GCS URIs, need to fetch the file
                    $data = $part->getData();
                    // Convert gs://bucket/path to https://storage.googleapis.com/bucket/path
                    $gcsPath = substr($data['uri'], 5); // Remove 'gs://'
                    $httpsUrl = 'https://storage.googleapis.com/'.$gcsPath;
                    $fileData = $this->fetchFileFromUrl($httpsUrl);

                    if ($fileData !== null) {
                        $mimeType = $fileData['mime_type'];

                        if (str_starts_with($mimeType, 'image/')) {
                            $formatted[] = [
                                'image' => [
                                    'format' => $this->mimeToFormat($mimeType),
                                    'source' => [
                                        'bytes' => $fileData['data'],
                                    ],
                                ],
                            ];
                        } elseif ($mimeType === 'application/pdf') {
                            $formatted[] = [
                                'document' => [
                                    'format' => 'pdf',
                                    'name' => 'document',
                                    'source' => [
                                        'bytes' => $fileData['data'],
                                    ],
                                ],
                            ];
                        }
                    }

                    break;
            }
        }

        return $formatted;
    }

    /**
     * Converts MIME type to Bedrock image format.
     *
     * @param string $mimeType The MIME type.
     *
     * @return string The Bedrock format string.
     */
    private function mimeToFormat(string $mimeType): string {
        return match ($mimeType) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpeg',
        };
    }

    /**
     * Fetches a file from a URL and returns base64-encoded data.
     *
     * @param string $url The file URL.
     *
     * @return array{mime_type: string, data: string}|null The file data or null on failure.
     */
    private function fetchFileFromUrl(string $url): ?array {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'WebFiori-AI/1.0',
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            return null;
        }

        // Detect MIME type from content
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        return [
            'mime_type' => $mimeType,
            'data' => base64_encode($content),
        ];
    }

    /**
     * Formats tools for the Converse API.
     *
     * @param \WebFiori\Ai\Tool\ToolInterface[] $tools The tools to format.
     *
     * @return array Formatted tool config.
     */
    private function formatTools(array $tools): array {
        $toolList = [];

        foreach ($tools as $tool) {
            $toolList[] = [
                'toolSpec' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'inputSchema' => ['json' => $tool->getParameters()],
                ],
            ];
        }

        return ['tools' => $toolList];
    }

    /**
     * Maps Bedrock Converse stop reasons to standardized values.
     *
     * @param string|null $reason The Bedrock stop reason.
     *
     * @return string The standardized stop reason.
     */
    private function mapStopReason(?string $reason): string {
        return match ($reason) {
            'end_turn', 'stop_sequence' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            default => 'stop',
        };
    }
}
