<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Anthropic;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\SseParser;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolInterface;
use WebFiori\Ai\Usage;

/**
 * Anthropic Claude provider implementation.
 *
 * Supports chat completions, streaming, and tool/function calling
 * via the Anthropic Messages API.
 *
 * Configuration options:
 * - 'api_key' (required): Anthropic API key.
 * - 'model' (optional): Default model. Defaults to 'claude-sonnet-4-20250514'.
 * - 'max_tokens' (optional): Default max tokens. Defaults to 4096.
 * - 'base_url' (optional): API base URL. Defaults to 'https://api.anthropic.com'.
 * - 'anthropic_version' (optional): API version. Defaults to '2023-06-01'.
 *
 * Note: Anthropic does NOT support embeddings or image generation.
 * Those methods will throw UnsupportedFeatureException.
 *
 * @author Ibrahim
 */
class AnthropicClient extends AbstractClient {
    /**
     * Returns the provider name.
     *
     * @return string The provider identifier.
     */
    public function getName(): string {
        return 'anthropic';
    }

    /**
     * Applies optional generation parameters to the request body.
     *
     * @param array<string, mixed> &$body The request body to modify.
     * @param array<string, mixed> $options The options to apply.
     */
    private function applyOptions(array &$body, array $options): void {
        $allowedOptions = [
            'temperature', 'top_p', 'top_k', 'stop_sequences',
        ];

        foreach ($allowedOptions as $option) {
            if (isset($options[$option])) {
                // Map 'stop' to 'stop_sequences' for consistency with OpenAI
                $key = $option === 'stop' ? 'stop_sequences' : $option;
                $body[$key] = $options[$option];
            }
        }

        // Handle 'stop' alias
        if (isset($options['stop'])) {
            $body['stop_sequences'] = is_array($options['stop']) ? $options['stop'] : [$options['stop']];
        }

        if (isset($options['tools']) && count($options['tools']) > 0) {
            $body['tools'] = $this->formatTools($options['tools']);
        }
    }

    /**
     * Extracts the system message from the messages array.
     *
     * Anthropic requires system message as a separate top-level parameter.
     *
     * @param Message[] $messages The messages to process.
     *
     * @return string|null The system message content, or null if none.
     */
    private function extractSystemMessage(array $messages): ?string {
        foreach ($messages as $message) {
            if ($message->getRole() === 'system') {
                return $message->getContent();
            }
        }

        return null;
    }

    /**
     * Formats Message objects into the Anthropic messages format.
     *
     * Filters out system messages (handled separately) and formats
     * tool calls and results according to Anthropic's schema.
     *
     * @param Message[] $messages The messages to format.
     *
     * @return array<int, array<string, mixed>> The formatted messages array.
     */
    private function formatMessages(array $messages): array {
        $formatted = [];

        foreach ($messages as $message) {
            // Skip system messages - they're handled separately
            if ($message->getRole() === 'system') {
                continue;
            }

            // Handle tool result messages
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

            // Handle assistant messages with tool calls
            if ($message->hasToolCalls()) {
                $content = [];

                // Add text content if present (handle multi-modal)
                if ($message->isMultiModal()) {
                    $content = array_merge($content, $this->formatContentParts($message->getContentParts()));
                } elseif (!empty($message->getContent())) {
                    $content[] = [
                        'type' => 'text',
                        'text' => $message->getContent(),
                    ];
                }

                // Add tool use blocks
                foreach ($message->getToolCalls() as $toolCall) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall->getId(),
                        'name' => $toolCall->getName(),
                        'input' => $toolCall->getArguments(),
                    ];
                }

                $formatted[] = [
                    'role' => 'assistant',
                    'content' => $content,
                ];

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

            // Regular user/assistant message
            $formatted[] = [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
            ];
        }

        return $formatted;
    }

    /**
     * Formats ContentPart objects into Anthropic content array format.
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
                    $formatted[] = [
                        'type' => 'text',
                        'text' => $part->getData()['text'],
                    ];

                    break;

                case \WebFiori\Ai\ContentPart::TYPE_IMAGE_URL:
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

                case \WebFiori\Ai\ContentPart::TYPE_IMAGE_BASE64:
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

                case \WebFiori\Ai\ContentPart::TYPE_DOCUMENT:
                    $data = $part->getData();
                    $mimeType = $data['mime_type'];

                    // Claude supports images and PDFs
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
                        // Claude 3.5 supports PDFs via document type
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

                case \WebFiori\Ai\ContentPart::TYPE_FILE_GCS:
                    // Anthropic doesn't support GCS URIs, need to fetch the file
                    $this->logWarning('Anthropic does not support GCS URIs directly. Attempting to fetch via HTTPS.');
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
            $this->logWarning('Failed to fetch file from URL', ['url' => $url]);

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
     * Formats ToolInterface instances into the Anthropic tools format.
     *
     * @param ToolInterface[] $tools The tools to format.
     *
     * @return array<int, array<string, mixed>> The formatted tools array.
     */
    private function formatTools(array $tools): array {
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
     * Returns the full endpoint URL for a given path.
     *
     * @param string $path The API path (e.g., '/v1/messages').
     *
     * @return string The full URL.
     */
    private function getEndpoint(string $path): string {
        $baseUrl = $this->getConfig('base_url', 'https://api.anthropic.com');

        return rtrim($baseUrl, '/').$path;
    }

    /**
     * Returns the HTTP headers for Anthropic API requests.
     *
     * @return array<string, string> The headers array.
     */
    private function getHeaders(): array {
        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->getConfig('api_key'),
            'anthropic-version' => $this->getConfig('anthropic_version', '2023-06-01'),
        ];
    }

    /**
     * Maps Anthropic stop reasons to standardized values.
     *
     * @param string $reason The Anthropic stop reason.
     *
     * @return string The standardized stop reason.
     */
    private function mapStopReason(string $reason): string {
        return match ($reason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            'tool_use' => 'tool_calls',
            default => $reason,
        };
    }

    /**
     * Builds the HTTP request for a chat completion call.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    protected function buildChatRequest(array $messages, array $options): HttpRequest {
        $model = $options['model'] ?? $this->getConfig('model', 'claude-sonnet-4-20250514');
        $maxTokens = $options['max_tokens'] ?? $this->getConfig('max_tokens', 4096);

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $this->formatMessages($messages),
        ];

        // Add system message if present
        $systemMessage = $this->extractSystemMessage($messages);

        if ($systemMessage !== null) {
            $body['system'] = $systemMessage;
        }

        $this->applyOptions($body, $options);

        return new HttpRequest(
            'POST',
            $this->getEndpoint('/v1/messages'),
            $this->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Builds the HTTP request for an embeddings call.
     *
     * @param string|string[] $input The text input(s) to embed.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     *
     * @throws UnsupportedFeatureException Always, as Anthropic doesn't support embeddings.
     */
    protected function buildEmbedRequest(string|array $input, array $options): HttpRequest {
        throw new UnsupportedFeatureException(
            'Anthropic does not support embeddings. Consider using OpenAI or Google for embeddings.',
            'embeddings',
            $this->getName()
        );
    }

    /**
     * Builds the HTTP request for an image generation call.
     *
     * @param ImageRequest $request The image generation request.
     *
     * @return HttpRequest The HTTP request to send.
     *
     * @throws UnsupportedFeatureException Always, as Anthropic doesn't support image generation.
     */
    protected function buildImageRequest(ImageRequest $request): HttpRequest {
        throw new UnsupportedFeatureException(
            'Anthropic does not support image generation. Consider using OpenAI (DALL-E) or Google (Imagen).',
            'image_generation',
            $this->getName()
        );
    }

    /**
     * Builds the HTTP request for a streaming chat completion call.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    protected function buildStreamChatRequest(array $messages, array $options): HttpRequest {
        $model = $options['model'] ?? $this->getConfig('model', 'claude-sonnet-4-20250514');
        $maxTokens = $options['max_tokens'] ?? $this->getConfig('max_tokens', 4096);

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        // Add system message if present
        $systemMessage = $this->extractSystemMessage($messages);

        if ($systemMessage !== null) {
            $body['system'] = $systemMessage;
        }

        $this->applyOptions($body, $options);

        return new HttpRequest(
            'POST',
            $this->getEndpoint('/v1/messages'),
            $this->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Executes the streaming chat request using the SSE parser.
     *
     * Anthropic uses a different SSE format with event types:
     * - message_start: Initial message metadata
     * - content_block_start: Start of a content block
     * - content_block_delta: Incremental content
     * - content_block_stop: End of a content block
     * - message_delta: Final message metadata (stop reason, usage)
     * - message_stop: End of message
     *
     * @param HttpRequest $request The HTTP request to send.
     * @param callable $onToken Token callback.
     * @param callable|null $onComplete Completion callback.
     * @param callable|null $onError Error callback.
     */
    protected function doStreamChat(
        HttpRequest $request,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void {
        $accumulatedContent = '';
        $model = $this->getConfig('model', 'claude-sonnet-4-20250514');
        $finishReason = null;
        $inputTokens = 0;
        $outputTokens = 0;
        $toolCalls = [];
        $currentToolCall = null;

        $parser = new SseParser(
            function (string $data) use (
                $onToken,
                &$accumulatedContent,
                &$finishReason,
                &$inputTokens,
                &$outputTokens,
                &$model,
                &$toolCalls,
                &$currentToolCall
            ) {
                if ($data === '' || $data === '[DONE]') {
                    return;
                }

                $json = json_decode($data, true);

                if ($json === null) {
                    return;
                }

                $eventType = $json['type'] ?? '';

                switch ($eventType) {
                    case 'message_start':
                        if (isset($json['message']['model'])) {
                            $model = $json['message']['model'];
                        }

                        if (isset($json['message']['usage']['input_tokens'])) {
                            $inputTokens = $json['message']['usage']['input_tokens'];
                        }

                        break;

                    case 'content_block_start':
                        if (isset($json['content_block']['type']) && $json['content_block']['type'] === 'tool_use') {
                            $currentToolCall = [
                                'id' => $json['content_block']['id'] ?? '',
                                'name' => $json['content_block']['name'] ?? '',
                                'arguments' => '',
                            ];
                        }

                        break;

                    case 'content_block_delta':
                        $delta = $json['delta'] ?? [];

                        if (isset($delta['type'])) {
                            if ($delta['type'] === 'text_delta' && isset($delta['text'])) {
                                $text = $delta['text'];
                                $accumulatedContent .= $text;
                                $onToken($text);
                            } elseif ($delta['type'] === 'input_json_delta' && isset($delta['partial_json'])) {
                                if ($currentToolCall !== null) {
                                    $currentToolCall['arguments'] .= $delta['partial_json'];
                                }
                            }
                        }

                        break;

                    case 'content_block_stop':
                        if ($currentToolCall !== null) {
                            $toolCalls[] = $currentToolCall;
                            $currentToolCall = null;
                        }

                        break;

                    case 'message_delta':
                        if (isset($json['delta']['stop_reason'])) {
                            $finishReason = $this->mapStopReason($json['delta']['stop_reason']);
                        }

                        if (isset($json['usage']['output_tokens'])) {
                            $outputTokens = $json['usage']['output_tokens'];
                        }

                        break;
                }
            }
        );

        try {
            $this->getHttpClient()->sendStreaming($request, function (string $chunk) use ($parser)
            {
                $parser->feed($chunk);
            });

            if ($onComplete !== null) {
                $message = new Message('assistant', $accumulatedContent);

                // Add tool calls to message if present
                if (!empty($toolCalls)) {
                    $parsedToolCalls = [];

                    foreach ($toolCalls as $tc) {
                        $args = json_decode($tc['arguments'], true) ?? [];
                        $parsedToolCalls[] = new ToolCall($tc['id'], $tc['name'], $args);
                    }

                    $message = new Message('assistant', $accumulatedContent, $parsedToolCalls);
                }

                $usage = new Usage($inputTokens, $outputTokens);
                $response = new ChatResponse($message, $model, $finishReason ?? 'stop', $usage);
                $onComplete($response);
            }
        } catch (StreamingException $e) {
            $this->logError('Streaming error', ['error' => $e->getMessage()]);

            if ($onError !== null) {
                $onError($e);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Inspects an HTTP response and throws the appropriate exception for errors.
     *
     * @param HttpResponse $response The HTTP response to check.
     *
     * @throws AuthenticationException If status is 401.
     * @throws RateLimitException If status is 429.
     * @throws ProviderException If status indicates a server error.
     */
    protected function handleErrorResponse(HttpResponse $response): void {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $data = $response->getJson();
        $errorMessage = $data['error']['message'] ?? $response->getBody();
        $errorType = $data['error']['type'] ?? 'unknown_error';

        if ($status === 401) {
            throw new AuthenticationException(
                "Anthropic authentication failed: $errorMessage",
                $status
            );
        }

        if ($status === 429) {
            $retryAfter = null;
            $headers = $response->getHeaders();

            if (isset($headers['retry-after'])) {
                $retryAfter = (int) $headers['retry-after'];
            }

            throw new RateLimitException(
                "Anthropic rate limit exceeded: $errorMessage",
                $retryAfter
            );
        }

        if ($status === 529) {
            throw new ProviderException(
                "Anthropic API overloaded: $errorMessage",
                $status,
                $errorType
            );
        }

        throw new ProviderException(
            "Anthropic API error: $errorMessage",
            $status,
            $errorType
        );
    }

    /**
     * Parses an HTTP response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from Anthropic.
     *
     * @return ChatResponse The parsed chat response.
     */
    protected function parseChatResponse(HttpResponse $response): ChatResponse {
        $data = $response->getJson();

        $content = '';
        $toolCalls = [];

        // Parse content blocks
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

        $stopReason = $this->mapStopReason($data['stop_reason'] ?? 'end_turn');

        return new ChatResponse(
            $message,
            $data['model'] ?? $this->getConfig('model', 'claude-sonnet-4-20250514'),
            $usage,
            $stopReason
        );
    }

    /**
     * Parses an HTTP response into an EmbeddingResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return EmbeddingResponse The parsed embedding response.
     *
     * @throws UnsupportedFeatureException Always, as Anthropic doesn't support embeddings.
     */
    protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
        throw new UnsupportedFeatureException(
            'Anthropic does not support embeddings.',
            'embeddings',
            $this->getName()
        );
    }

    /**
     * Parses an HTTP response into an ImageResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return ImageResponse The parsed image response.
     *
     * @throws UnsupportedFeatureException Always, as Anthropic doesn't support image generation.
     */
    protected function parseImageResponse(HttpResponse $response): ImageResponse {
        throw new UnsupportedFeatureException(
            'Anthropic does not support image generation.',
            'image_generation',
            $this->getName()
        );
    }

    /**
     * Validates that required configuration options are present.
     *
     * @param array<string, mixed> $config The configuration to validate.
     *
     * @throws InvalidConfigException If required options are missing.
     */
    protected function validateConfig(array $config): void {
        if (empty($config['api_key'])) {
            throw new InvalidConfigException(
                'The "api_key" configuration option is required for Anthropic provider.',
                'api_key'
            );
        }
    }
}
