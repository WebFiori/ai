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
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\SseParser;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolInterface;
use WebFiori\Ai\Usage;

/**
 * Bedrock Invoke API invocation strategy.
 *
 * Uses the raw model invocation API, sending requests in each model's
 * native format. AWS does not translate requests — you are responsible
 * for providing correctly formatted payloads per model family.
 *
 * Use this strategy when you need model-specific parameters not available
 * through the Converse API, or when targeting models that only support Invoke.
 *
 * @author Ibrahim
 */
class InvokeStrategy implements InvocationStrategyInterface {
    /**
     * Builds the HTTP request for a chat completion using the Invoke API.
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
        $maxTokens = $options['max_tokens'] ?? $client->getConfig('max_tokens', 4096);
        $body = $this->buildModelBody($modelId, $messages, $maxTokens, $options);
        $url = $client->getBedrockEndpoint($modelId, 'invoke');
        $jsonBody = json_encode($body);

        return new HttpRequest(
            'POST',
            $url,
            $client->getBedrockHeaders('POST', $url, $jsonBody),
            $jsonBody
        );
    }

    /**
     * Builds the HTTP request for a streaming chat using the Invoke API.
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
        $maxTokens = $options['max_tokens'] ?? $client->getConfig('max_tokens', 4096);
        $body = $this->buildModelBody($modelId, $messages, $maxTokens, $options);
        $url = $client->getBedrockEndpoint($modelId, 'invoke-with-response-stream');
        $jsonBody = json_encode($body);

        return new HttpRequest(
            'POST',
            $url,
            $client->getBedrockHeaders('POST', $url, $jsonBody),
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
        $modelId = $client->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');
        $finishReason = null;
        $inputTokens = 0;
        $outputTokens = 0;

        $parser = new SseParser(
            function (string $data) use (
                $onToken,
                $modelId,
                &$accumulatedContent,
                &$finishReason,
                &$inputTokens,
                &$outputTokens
            ) {
                $json = json_decode($data, true);

                if ($json === null) {
                    return;
                }

                // Bedrock Invoke uses base64-encoded event payloads
                if (isset($json['bytes'])) {
                    $chunk = json_decode(base64_decode($json['bytes']), true);
                } else {
                    $chunk = $json;
                }

                if ($chunk === null) {
                    return;
                }

                $family = $this->getModelFamily($modelId);

                if ($family === 'anthropic') {
                    $this->processAnthropicStreamChunk(
                        $chunk,
                        $onToken,
                        $accumulatedContent,
                        $finishReason,
                        $inputTokens,
                        $outputTokens
                    );
                } elseif (isset($chunk['generation'])) {
                    $text = $chunk['generation'];
                    $accumulatedContent .= $text;
                    $onToken($text);

                    if (isset($chunk['stop_reason'])) {
                        $finishReason = 'stop';
                    }
                }
            }
        );

        try {
            $client->getHttpClient()->sendStreaming($request, function (string $chunk) use ($parser)
            {
                $parser->feed($chunk);
            });

            if ($onComplete !== null) {
                $message = new Message('assistant', $accumulatedContent);
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
     * Parses a raw Invoke API response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from Bedrock.
     * @param string $modelId The model ID used for the request.
     *
     * @return ChatResponse The parsed chat response.
     */
    public function parseChatResponse(HttpResponse $response, string $modelId): ChatResponse {
        $data = $response->getJson();
        $family = $this->getModelFamily($modelId);

        if ($family === 'anthropic') {
            return $this->parseAnthropicResponse($data, $modelId);
        }

        // Llama/Mistral format
        $content = $data['generation'] ?? '';
        $message = new Message('assistant', $content);
        $finishReason = isset($data['stop_reason']) ? 'stop' : null;

        return new ChatResponse($message, $modelId, null, $finishReason);
    }

    /**
     * Builds the request body for Anthropic Claude models via Invoke.
     *
     * @param Message[] $messages The messages.
     * @param int $maxTokens Max tokens.
     * @param array $options Request options.
     *
     * @return array The request body.
     */
    private function buildAnthropicBody(array $messages, int $maxTokens, array $options): array {
        $system = null;
        $formatted = [];

        foreach ($messages as $message) {
            if ($message->getRole() === 'system') {
                $system = $message->getContent();

                continue;
            }

            if ($message->getToolResult() !== null) {
                $formatted[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $message->getToolResult()->getToolCallId(),
                        'content' => $message->getToolResult()->getContent(),
                    ]],
                ];

                continue;
            }

            if ($message->hasToolCalls()) {
                $content = [];

                // Handle multi-modal content
                if ($message->isMultiModal()) {
                    $content = array_merge($content, $this->formatAnthropicContentParts($message->getContentParts()));
                } elseif (!empty($message->getContent())) {
                    $content[] = ['type' => 'text', 'text' => $message->getContent()];
                }

                foreach ($message->getToolCalls() as $toolCall) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall->getId(),
                        'name' => $toolCall->getName(),
                        'input' => $toolCall->getArguments(),
                    ];
                }

                $formatted[] = ['role' => 'assistant', 'content' => $content];

                continue;
            }

            // Handle multi-modal messages
            if ($message->isMultiModal()) {
                $formatted[] = [
                    'role' => $message->getRole(),
                    'content' => $this->formatAnthropicContentParts($message->getContentParts()),
                ];

                continue;
            }

            $formatted[] = [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
            ];
        }

        $body = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens' => $maxTokens,
            'messages' => $formatted,
        ];

        if ($system !== null) {
            $body['system'] = $system;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        if (isset($options['tools']) && count($options['tools']) > 0) {
            $body['tools'] = $this->formatAnthropicTools($options['tools']);
        }

        return $body;
    }

    /**
     * Builds the request body for Llama/Mistral models via Invoke.
     *
     * Llama/Mistral models use text-only prompt format and do not support
     * multi-modal content. If multi-modal messages are passed, only the
     * text content will be used.
     *
     * @param Message[] $messages The messages.
     * @param int $maxTokens Max tokens.
     * @param array $options Request options.
     *
     * @return array The request body.
     */
    private function buildLlamaBody(array $messages, int $maxTokens, array $options): array {
        $prompt = '';

        foreach ($messages as $message) {
            $role = $message->getRole();
            $content = $message->getContent(); // getText only, images ignored

            if ($role === 'system') {
                $prompt .= "[INST] <<SYS>>\n$content\n<</SYS>>\n\n";
            } elseif ($role === 'user') {
                $prompt .= "$content [/INST]\n";
            } else {
                $prompt .= "$content\n[INST] ";
            }
        }

        return [
            'prompt' => $prompt,
            'max_gen_len' => $maxTokens,
            'temperature' => $options['temperature'] ?? 0.7,
        ];
    }

    /**
     * Builds the native model body based on the model family.
     *
     * @param string $modelId The model ID.
     * @param Message[] $messages The messages.
     * @param int $maxTokens Max tokens.
     * @param array $options Request options.
     *
     * @return array The formatted request body.
     */
    private function buildModelBody(string $modelId, array $messages, int $maxTokens, array $options): array {
        $family = $this->getModelFamily($modelId);

        if ($family === 'anthropic') {
            return $this->buildAnthropicBody($messages, $maxTokens, $options);
        }

        return $this->buildLlamaBody($messages, $maxTokens, $options);
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
     * Formats ContentPart objects into Anthropic content array format.
     *
     * @param ContentPart[] $parts The content parts.
     *
     * @return array<int, array<string, mixed>> The formatted content array.
     */
    private function formatAnthropicContentParts(array $parts): array {
        $formatted = [];

        foreach ($parts as $part) {
            switch ($part->getType()) {
                case ContentPart::TYPE_TEXT:
                    $formatted[] = [
                        'type' => 'text',
                        'text' => $part->getData()['text'],
                    ];

                    break;

                case ContentPart::TYPE_IMAGE_URL:
                    // Anthropic requires base64 data, fetch the file
                    $url = $part->getData()['url'];
                    $fileData = $this->fetchFileFromUrl($url);

                    if ($fileData !== null) {
                        $formatted[] = [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $fileData['mime_type'],
                                'data' => $fileData['data'],
                            ],
                        ];
                    }

                    break;

                case ContentPart::TYPE_IMAGE_BASE64:
                    $data = $part->getData();
                    $formatted[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $data['mime_type'],
                            'data' => $data['data'],
                        ],
                    ];

                    break;

                case ContentPart::TYPE_DOCUMENT:
                    $data = $part->getData();
                    $mimeType = $data['mime_type'];

                    if (str_starts_with($mimeType, 'image/')) {
                        $formatted[] = [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $data['data'],
                            ],
                        ];
                    } elseif ($mimeType === 'application/pdf') {
                        // Claude via Invoke supports PDFs
                        $formatted[] = [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $data['data'],
                            ],
                        ];
                    } else {
                        // For text-based documents, convert to text
                        $decoded = base64_decode($data['data']);
                        $formatted[] = [
                            'type' => 'text',
                            'text' => $decoded,
                        ];
                    }

                    break;

                case ContentPart::TYPE_FILE_GCS:
                    // Anthropic doesn't support GCS URIs, need to fetch the file
                    $data = $part->getData();
                    // Convert gs://bucket/path to https://storage.googleapis.com/bucket/path
                    $gcsPath = substr($data['uri'], 5); // Remove 'gs://'
                    $httpsUrl = 'https://storage.googleapis.com/'.$gcsPath;
                    $fileData = $this->fetchFileFromUrl($httpsUrl);

                    if ($fileData !== null) {
                        $mimeType = $fileData['mime_type'];

                        if (str_starts_with($mimeType, 'image/')) {
                            $formatted[] = [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => $mimeType,
                                    'data' => $fileData['data'],
                                ],
                            ];
                        } elseif ($mimeType === 'application/pdf') {
                            $formatted[] = [
                                'type' => 'document',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => $mimeType,
                                    'data' => $fileData['data'],
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
     * Formats tools for Anthropic's native format.
     *
     * @param ToolInterface[] $tools The tools.
     *
     * @return array The formatted tools.
     */
    private function formatAnthropicTools(array $tools): array {
        $formatted = [];

        foreach ($tools as $tool) {
            $formatted[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'input_schema' => $tool->getParameters(),
            ];
        }

        return $formatted;
    }

    /**
     * Detects the model family from the model ID prefix.
     *
     * @param string $modelId The full model ID.
     *
     * @return string The model family identifier.
     */
    private function getModelFamily(string $modelId): string {
        return match (true) {
            str_starts_with($modelId, 'anthropic.') => 'anthropic',
            str_starts_with($modelId, 'meta.') => 'meta',
            str_starts_with($modelId, 'amazon.') => 'amazon',
            str_starts_with($modelId, 'cohere.') => 'cohere',
            str_starts_with($modelId, 'mistral.') => 'mistral',
            default => 'unknown',
        };
    }

    /**
     * Parses an Anthropic-format response body.
     *
     * @param array $data The response data.
     * @param string $modelId The model ID.
     *
     * @return ChatResponse The parsed response.
     */
    private function parseAnthropicResponse(array $data, string $modelId): ChatResponse {
        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    $block['id'],
                    $block['name'],
                    $block['input'] ?? []
                );
            }
        }

        $message = new Message('assistant', $content, $toolCalls);

        $usage = null;

        if (isset($data['usage'])) {
            $usage = new Usage(
                $data['usage']['input_tokens'] ?? 0,
                $data['usage']['output_tokens'] ?? 0
            );
        }

        $finishReason = match ($data['stop_reason'] ?? 'end_turn') {
            'end_turn', 'stop_sequence' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            default => 'stop',
        };

        return new ChatResponse($message, $modelId, $usage, $finishReason);
    }

    /**
     * Processes an Anthropic-format stream chunk from Invoke.
     *
     * @param array $chunk The decoded chunk.
     * @param callable $onToken Token callback.
     * @param string &$content Accumulated content reference.
     * @param string|null &$finishReason Finish reason reference.
     * @param int &$inputTokens Input token count reference.
     * @param int &$outputTokens Output token count reference.
     */
    private function processAnthropicStreamChunk(
        array $chunk,
        callable $onToken,
        string &$content,
        ?string &$finishReason,
        int &$inputTokens,
        int &$outputTokens
    ): void {
        switch ($chunk['type'] ?? '') {
            case 'message_start':
                $inputTokens = $chunk['message']['usage']['input_tokens'] ?? $inputTokens;

                break;

            case 'content_block_delta':
                $delta = $chunk['delta'] ?? [];

                if (isset($delta['type']) && $delta['type'] === 'text_delta' && isset($delta['text'])) {
                    $text = $delta['text'];
                    $content .= $text;
                    $onToken($text);
                }

                break;

            case 'message_delta':
                if (isset($chunk['delta']['stop_reason'])) {
                    $finishReason = match ($chunk['delta']['stop_reason']) {
                        'end_turn' => 'stop',
                        'max_tokens' => 'length',
                        'tool_use' => 'tool_calls',
                        default => 'stop',
                    };
                }

                $outputTokens = $chunk['usage']['output_tokens'] ?? $outputTokens;

                break;
        }
    }
}
