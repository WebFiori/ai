<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Formatter;

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\SseParser;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\AnthropicBuiltInTool;
use WebFiori\Ai\Tool\BuiltInToolInterface;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolInterface;
use WebFiori\Ai\Usage;

/**
 * Formats requests and parses responses for the Anthropic Messages API.
 *
 * This formatter encapsulates all Anthropic-specific serialization logic
 * and can be reused across different transport contexts — direct API calls
 * via AnthropicClient and Vertex AI Model Garden via GoogleClient.
 *
 * @author Ibrahim
 */
class AnthropicFormatter implements ProviderFormatterInterface {
    /**
     * The Anthropic client configuration.
     *
     * @var AnthropicClientConfig
     */
    private AnthropicClientConfig $config;

    /**
     * Optional logging callback.
     *
     * @var callable|null
     */
    private $logCallback;

    /**
     * Creates a new AnthropicFormatter instance.
     *
     * @param AnthropicClientConfig $config The Anthropic client configuration.
     * @param callable|null $logCallback Optional logging callback.
     */
    public function __construct(AnthropicClientConfig $config, ?callable $logCallback = null) {
        $this->config = $config;
        $this->logCallback = $logCallback;
    }

    /**
     * {@inheritdoc}
     */
    public function buildChatRequest(
        array $messages,
        array $options,
        string $endpointUrl,
        array $headers
    ): HttpRequest {
        $model = $options[ChatOption::MODEL] ?? $this->config->model;
        $maxTokens = $options[ChatOption::MAX_TOKENS] ?? $this->config->maxTokens;

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $this->formatMessages($messages),
        ];

        $systemMessage = $this->extractSystemMessage($messages);

        if ($systemMessage !== null) {
            $body['system'] = $systemMessage;
        }

        $this->applyOptions($body, $options);

        return new HttpRequest('POST', $endpointUrl, $headers, json_encode($body));
    }

    /**
     * {@inheritdoc}
     */
    public function buildEmbedRequest(
        string|array $input,
        array $options,
        string $endpointUrl,
        array $headers
    ): HttpRequest {
        throw new UnsupportedFeatureException(
            'Anthropic does not support embeddings. Consider using OpenAI or Google for embeddings.',
            'embeddings',
            'anthropic'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function buildImageRequest(
        ImageRequest $request,
        string $endpointUrl,
        array $headers
    ): HttpRequest {
        throw new UnsupportedFeatureException(
            'Anthropic does not support image generation. Consider using OpenAI (DALL-E) or Google (Imagen).',
            'image_generation',
            'anthropic'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function buildStreamChatRequest(
        array $messages,
        array $options,
        string $endpointUrl,
        array $headers
    ): HttpRequest {
        $model = $options[ChatOption::MODEL] ?? $this->config->model;
        $maxTokens = $options[ChatOption::MAX_TOKENS] ?? $this->config->maxTokens;

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        $systemMessage = $this->extractSystemMessage($messages);

        if ($systemMessage !== null) {
            $body['system'] = $systemMessage;
        }

        $this->applyOptions($body, $options);

        return new HttpRequest('POST', $endpointUrl, $headers, json_encode($body));
    }

    /**
     * Executes a streaming chat request using SSE parsing.
     *
     * This is not part of ProviderFormatterInterface since streaming requires
     * access to the HTTP client. It is called directly by clients using this formatter.
     *
     * @param HttpRequest $request The streaming HTTP request.
     * @param HttpClientInterface $httpClient The HTTP client.
     * @param callable $onToken Token callback.
     * @param callable|null $onComplete Completion callback.
     * @param callable|null $onError Error callback.
     */
    public function executeStreamChat(
        HttpRequest $request,
        HttpClientInterface $httpClient,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void {
        $accumulatedContent = '';
        $model = $this->config->model;
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
            $httpClient->sendStreaming($request, function (string $chunk) use ($parser)
            {
                $parser->feed($chunk);
            });

            if ($onComplete !== null) {
                $message = new Message(Role::ASSISTANT, $accumulatedContent);

                if (!empty($toolCalls)) {
                    $parsedToolCalls = [];

                    foreach ($toolCalls as $tc) {
                        $args = json_decode($tc['arguments'], true) ?? [];
                        $parsedToolCalls[] = new ToolCall($tc['id'], $tc['name'], $args);
                    }

                    $message = new Message(Role::ASSISTANT, $accumulatedContent, $parsedToolCalls);
                }

                $usage = new Usage($inputTokens, $outputTokens);
                $response = new ChatResponse($message, $model, $usage, $finishReason ?? 'stop');
                $onComplete($response);
            }
        } catch (StreamingException $e) {
            $this->log('error', 'Streaming error', ['error' => $e->getMessage()]);

            if ($onError !== null) {
                $onError($e);
            } else {
                throw $e;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handleErrorResponse(HttpResponse $response): void {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $data = $response->getJson();
        $errorMessage = $data['error']['message'] ?? $response->getBody();
        $errorType = $data['error']['type'] ?? 'unknown_error';

        if ($status === 401) {
            throw new AuthenticationException(
                "Anthropic authentication failed: {$errorMessage}",
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
                "Anthropic rate limit exceeded: {$errorMessage}",
                $retryAfter
            );
        }

        if ($status === 529) {
            throw new ProviderException(
                "Anthropic API overloaded: {$errorMessage}",
                $status,
                $errorType
            );
        }

        throw new ProviderException(
            "Anthropic API error: {$errorMessage}",
            $status,
            $errorType
        );
    }

    /**
     * {@inheritdoc}
     */
    public function parseChatResponse(HttpResponse $response): ChatResponse {
        $data = $response->getJson();

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

        $message = new Message(Role::ASSISTANT, $content, $toolCalls);

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
            $data['model'] ?? $this->config->model,
            $usage,
            $stopReason
        );
    }

    /**
     * {@inheritdoc}
     */
    public function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
        throw new UnsupportedFeatureException(
            'Anthropic does not support embeddings.',
            'embeddings',
            'anthropic'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function parseImageResponse(HttpResponse $response): ImageResponse {
        throw new UnsupportedFeatureException(
            'Anthropic does not support image generation.',
            'image_generation',
            'anthropic'
        );
    }

    /**
     * Applies optional generation parameters to the request body.
     *
     * @param array<string, mixed> &$body The request body to modify.
     * @param array<string, mixed> $options The options to apply.
     */
    private function applyOptions(array &$body, array $options): void {
        foreach (['temperature', 'top_p', 'top_k'] as $option) {
            if (isset($options[$option])) {
                $body[$option] = $options[$option];
            }
        }

        if (isset($options[ChatOption::STOP])) {
            $body['stop_sequences'] = is_array($options[ChatOption::STOP]) ? $options[ChatOption::STOP] : [$options[ChatOption::STOP]];
        }

        if (isset($options[ChatOption::TOOLS]) && count($options[ChatOption::TOOLS]) > 0) {
            $body['tools'] = $this->formatTools($options[ChatOption::TOOLS]);
        }

        if (isset($options['built_in_tools']) && count($options['built_in_tools']) > 0) {
            $builtIn = $this->formatBuiltInTools($options['built_in_tools']);
            $body['tools'] = array_merge($body['tools'] ?? [], $builtIn);
        }

        if (isset($options[ChatOption::JSON_SCHEMA])) {
            $schemaJson = json_encode($options[ChatOption::JSON_SCHEMA], JSON_PRETTY_PRINT);
            $instruction = "Respond with valid JSON only. No explanation, no markdown. Use this schema:\n{$schemaJson}";
            $body['system'] = isset($body['system'])
                ? $body['system']."\n\n".$instruction
                : $instruction;
        } elseif (!empty($options[ChatOption::JSON_MODE])) {
            $instruction = 'Respond with valid JSON only. No explanation, no markdown fences.';
            $body['system'] = isset($body['system'])
                ? $body['system']."\n\n".$instruction
                : $instruction;
        }
    }

    /**
     * Extracts the system message from the messages array.
     *
     * @param Message[] $messages The messages to process.
     *
     * @return string|null The system message content, or null if none.
     */
    private function extractSystemMessage(array $messages): ?string {
        foreach ($messages as $message) {
            if ($message->getRole() === Role::SYSTEM->value) {
                return $message->getContent();
            }
        }

        return null;
    }

    /**
     * Fetches a file from a URL and returns base64-encoded data.
     *
     * @param string $url The file URL.
     *
     * @return array{mime_type: string, data: string}|null The file data or null on failure.
     *
     * @codeCoverageIgnore Requires live HTTP — tested via integration tests
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
            $this->log('warning', 'Failed to fetch file from URL', ['url' => $url]);

            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        return [
            'mime_type' => $mimeType,
            'data' => base64_encode($content),
        ];
    }

    /**
     * Formats built-in tool identifiers into the Anthropic tools format.
     *
     * @param BuiltInToolInterface[] $builtInTools The built-in tools.
     *
     * @return array<int, array<string, mixed>> The formatted tools array.
     */
    private function formatBuiltInTools(array $builtInTools): array {
        $typeMap = [
            'computer' => 'computer_20241022',
            'bash' => 'bash_20241022',
            'text_editor' => 'text_editor_20241022',
        ];

        $formatted = [];

        foreach ($builtInTools as $tool) {
            if (!($tool instanceof AnthropicBuiltInTool)) {
                throw new UnsupportedFeatureException(
                    'built_in_tools:'.get_class($tool),
                    'AnthropicClient'
                );
            }

            $type = $typeMap[$tool->getValue()] ?? null;

            if ($type === null) {
                throw new UnsupportedFeatureException(
                    'built_in_tools:'.$tool->getValue(),
                    'AnthropicClient'
                );
            }

            $entry = ['type' => $type, 'name' => $tool->getValue()];

            if ($tool === AnthropicBuiltInTool::COMPUTER) {
                $entry['display_width_px'] = 1024;
                $entry['display_height_px'] = 768;
                $entry['display_number'] = 1;
            }

            $formatted[] = $entry;
        }

        return $formatted;
    }

    /**
     * Formats ContentPart objects into Anthropic content array format.
     *
     * @param ContentPart[] $parts The content parts.
     *
     * @return array<int, array<string, mixed>> The formatted content array.
     */
    private function formatContentParts(array $parts): array {
        $formatted = [];

        foreach ($parts as $part) {
            switch ($part->getType()) {
                case ContentPart::TYPE_TEXT:
                    $formatted[] = ['type' => 'text', 'text' => $part->getData()['text']];

                    break;

                case ContentPart::TYPE_IMAGE_URL:
                    $fileData = $this->fetchFileFromUrl($part->getData()['url']);

                    if ($fileData !== null) {
                        $formatted[] = [
                            'type' => 'image',
                            'source' => ['type' => 'base64', 'media_type' => $fileData['mime_type'], 'data' => $fileData['data']],
                        ];
                    }

                    break;

                case ContentPart::TYPE_IMAGE_BASE64:
                    $data = $part->getData();
                    $formatted[] = [
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'media_type' => $data['mime_type'], 'data' => $data['data']],
                    ];

                    break;

                case ContentPart::TYPE_DOCUMENT:
                    $data = $part->getData();
                    $mimeType = $data['mime_type'];

                    if (str_starts_with($mimeType, 'image/')) {
                        $formatted[] = [
                            'type' => 'image',
                            'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $data['data']],
                        ];
                    } elseif ($mimeType === 'application/pdf') {
                        $formatted[] = [
                            'type' => 'document',
                            'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $data['data']],
                        ];
                    } else {
                        $formatted[] = ['type' => 'text', 'text' => base64_decode($data['data'])];
                    }

                    break;

                case ContentPart::TYPE_FILE_GCS:
                    $data = $part->getData();
                    $httpsUrl = 'https://storage.googleapis.com/'.substr($data['uri'], 5);
                    $fileData = $this->fetchFileFromUrl($httpsUrl);

                    if ($fileData !== null) {
                        $mimeType = $fileData['mime_type'];

                        if (str_starts_with($mimeType, 'image/')) {
                            $formatted[] = [
                                'type' => 'image',
                                'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $fileData['data']],
                            ];
                        } elseif ($mimeType === 'application/pdf') {
                            $formatted[] = [
                                'type' => 'document',
                                'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $fileData['data']],
                            ];
                        }
                    }

                    break;
            }
        }

        return $formatted;
    }

    /**
     * Formats Message objects into the Anthropic messages format.
     *
     * @param Message[] $messages The messages to format.
     *
     * @return array<int, array<string, mixed>> The formatted messages array.
     */
    private function formatMessages(array $messages): array {
        $formatted = [];

        foreach ($messages as $message) {
            if ($message->getRole() === Role::SYSTEM->value) {
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

                if ($message->isMultiModal()) {
                    $content = array_merge($content, $this->formatContentParts($message->getContentParts()));
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

            if ($message->isMultiModal()) {
                $formatted[] = [
                    'role' => $message->getRole(),
                    'content' => $this->formatContentParts($message->getContentParts()),
                ];

                continue;
            }

            $formatted[] = ['role' => $message->getRole(), 'content' => $message->getContent()];
        }

        return $formatted;
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
     * Logs a message via the configured callback.
     *
     * @param string $level Log level.
     * @param string $message Log message.
     * @param array<string, mixed> $context Additional context.
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logCallback !== null) {
            ($this->logCallback)($level, "[AnthropicFormatter] {$message}", $context);
        }
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
}
