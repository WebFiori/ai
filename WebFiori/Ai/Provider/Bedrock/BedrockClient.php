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
 * AWS Bedrock provider implementation.
 *
 * Supports chat completions and streaming via AWS Bedrock Runtime API,
 * providing access to Claude, Llama, Titan, and other models hosted on AWS.
 *
 * Supports two authentication modes:
 *
 * **API Key (recommended for testing):**
 * ```php
 * $client = new BedrockClient([
 *     'api_key' => 'your-bedrock-api-key',
 *     'region'  => 'us-east-1',
 *     'model'   => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
 * ]);
 * ```
 *
 * **AWS Credentials (SigV4):**
 * ```php
 * $client = new BedrockClient([
 *     'access_key' => 'AKIA...',
 *     'secret_key' => 'wJal...',
 *     'region'     => 'us-east-1',
 *     'model'      => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
 * ]);
 * ```
 *
 * Configuration options:
 * - 'api_key' (required if not using access_key/secret_key): Bedrock API key.
 * - 'access_key' (required if not using api_key): AWS access key ID.
 * - 'secret_key' (required if not using api_key): AWS secret access key.
 * - 'region' (required): AWS region (e.g., 'us-east-1').
 * - 'model' (optional): Default model ID. Defaults to 'anthropic.claude-3-5-sonnet-20241022-v2:0'.
 * - 'max_tokens' (optional): Default max tokens. Defaults to 4096.
 *
 * @author Ibrahim
 */
class BedrockClient extends AbstractClient {
    /**
     * The AWS signer instance (only used in SigV4 mode).
     *
     * @var AwsSigner|null
     */
    private ?AwsSigner $signer;

    /**
     * Creates a new BedrockClient instance.
     *
     * @param array<string, mixed> $config Provider configuration.
     *
     * @throws InvalidConfigException If required options are missing.
     */
    public function __construct(array $config = []) {
        parent::__construct($config);

        if (!empty($config['access_key'])) {
            $this->signer = new AwsSigner(
                $config['access_key'],
                $config['secret_key'],
                $config['region'],
                'bedrock'
            );
        } else {
            $this->signer = null;
        }
    }

    /**
     * Returns the provider name.
     *
     * @return string The provider identifier.
     */
    public function getName(): string {
        return 'bedrock';
    }

    /**
     * Builds the request body based on model family.
     *
     * @param string $family Model family.
     * @param Message[] $messages Messages.
     * @param int $maxTokens Max tokens.
     * @param array $options Options.
     *
     * @return array Request body.
     */
    private function buildRequestBody(string $family, array $messages, int $maxTokens, array $options): array {
        if ($family === 'anthropic') {
            $data = $this->formatAnthropicMessages($messages);
            $body = [
                'anthropic_version' => 'bedrock-2023-05-31',
                'max_tokens' => $maxTokens,
                'messages' => $data['messages'],
            ];

            if ($data['system'] !== null) {
                $body['system'] = $data['system'];
            }

            if (isset($options['temperature'])) {
                $body['temperature'] = $options['temperature'];
            }

            if (isset($options['tools']) && count($options['tools']) > 0) {
                $body['tools'] = $this->formatAnthropicTools($options['tools']);
            }

            return $body;
        }

        // Default/Meta/Mistral format
        $prompt = $this->buildTextPrompt($messages);

        return [
            'prompt' => $prompt,
            'max_gen_len' => $maxTokens,
            'temperature' => $options['temperature'] ?? 0.7,
        ];
    }

    /**
     * Builds a text prompt from messages for non-Anthropic models.
     *
     * @param Message[] $messages The messages.
     *
     * @return string The formatted prompt.
     */
    private function buildTextPrompt(array $messages): string {
        $prompt = '';

        foreach ($messages as $message) {
            $role = $message->getRole();
            $content = $message->getContent();

            if ($role === 'system') {
                $prompt .= "[INST] <<SYS>>\n$content\n<</SYS>>\n\n";
            } elseif ($role === 'user') {
                $prompt .= "$content [/INST]\n";
            } else {
                $prompt .= "$content\n[INST] ";
            }
        }

        return $prompt;
    }

    /**
     * Formats messages for Anthropic Claude models on Bedrock.
     *
     * @param Message[] $messages The messages to format.
     *
     * @return array{system: string|null, messages: array} Formatted request components.
     */
    private function formatAnthropicMessages(array $messages): array {
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

                if (!empty($message->getContent())) {
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

            $formatted[] = [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
            ];
        }

        return ['system' => $system, 'messages' => $formatted];
    }

    /**
     * Formats tools for Anthropic Claude models.
     *
     * @param ToolInterface[] $tools The tools to format.
     *
     * @return array The formatted tools array.
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
     * Returns the Bedrock endpoint URL for the given model and operation.
     *
     * @param string $modelId The model ID.
     * @param bool $streaming Whether this is a streaming request.
     *
     * @return string The full endpoint URL.
     */
    private function getEndpoint(string $modelId, bool $streaming = false): string {
        $region = $this->getConfig('region');
        $operation = $streaming ? 'invoke-with-response-stream' : 'invoke';

        return sprintf(
            'https://bedrock-runtime.%s.amazonaws.com/model/%s/%s',
            $region,
            $modelId,
            $operation
        );
    }

    /**
     * Detects the model family from the model ID.
     *
     * @param string $modelId The full model ID.
     *
     * @return string The model family ('anthropic', 'meta', 'amazon', 'cohere', 'mistral').
     */
    private function getModelFamily(string $modelId): string {
        if (str_starts_with($modelId, 'anthropic.')) {
            return 'anthropic';
        }

        if (str_starts_with($modelId, 'meta.')) {
            return 'meta';
        }

        if (str_starts_with($modelId, 'amazon.')) {
            return 'amazon';
        }

        if (str_starts_with($modelId, 'cohere.')) {
            return 'cohere';
        }

        if (str_starts_with($modelId, 'mistral.')) {
            return 'mistral';
        }

        return 'unknown';
    }

    /**
     * Returns headers for a Bedrock request.
     *
     * Uses Bearer token when 'api_key' is configured, otherwise signs
     * the request using AWS Signature Version 4.
     *
     * @param string $method HTTP method.
     * @param string $url Request URL.
     * @param string $body Request body.
     *
     * @return array<string, string> Request headers.
     */
    private function getSignedHeaders(string $method, string $url, string $body): array {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->signer === null) {
            // API key mode — simple Bearer token
            $headers['Authorization'] = 'Bearer '.$this->getConfig('api_key');

            return $headers;
        }

        // SigV4 mode
        return $this->signer->sign($method, $url, $headers, $body);
    }

    /**
     * Parses an Anthropic-format response from Bedrock.
     *
     * @param array $data Response data.
     * @param string $modelId Model ID.
     *
     * @return ChatResponse Parsed response.
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
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            default => 'stop',
        };

        return new ChatResponse($message, $modelId, $usage, $finishReason);
    }

    /**
     * Processes an Anthropic-format stream chunk.
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
                if (isset($chunk['message']['usage']['input_tokens'])) {
                    $inputTokens = $chunk['message']['usage']['input_tokens'];
                }

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
                        default => $chunk['delta']['stop_reason'],
                    };
                }

                if (isset($chunk['usage']['output_tokens'])) {
                    $outputTokens = $chunk['usage']['output_tokens'];
                }

                break;
        }
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
        $modelId = $options['model'] ?? $this->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');
        $maxTokens = $options['max_tokens'] ?? $this->getConfig('max_tokens', 4096);
        $family = $this->getModelFamily($modelId);

        $body = $this->buildRequestBody($family, $messages, $maxTokens, $options);
        $url = $this->getEndpoint($modelId, false);
        $jsonBody = json_encode($body);

        return new HttpRequest(
            'POST',
            $url,
            $this->getSignedHeaders('POST', $url, $jsonBody),
            $jsonBody
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
     * @throws UnsupportedFeatureException If embeddings not supported.
     */
    protected function buildEmbedRequest(string|array $input, array $options): HttpRequest {
        throw new UnsupportedFeatureException(
            'Bedrock embeddings support is not yet implemented.',
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
     * @throws UnsupportedFeatureException If image generation not supported.
     */
    protected function buildImageRequest(ImageRequest $request): HttpRequest {
        throw new UnsupportedFeatureException(
            'Bedrock image generation support is not yet implemented.',
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
        $modelId = $options['model'] ?? $this->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');
        $maxTokens = $options['max_tokens'] ?? $this->getConfig('max_tokens', 4096);
        $family = $this->getModelFamily($modelId);

        $body = $this->buildRequestBody($family, $messages, $maxTokens, $options);
        $url = $this->getEndpoint($modelId, true);
        $jsonBody = json_encode($body);

        return new HttpRequest(
            'POST',
            $url,
            $this->getSignedHeaders('POST', $url, $jsonBody),
            $jsonBody
        );
    }

    /**
     * Executes the streaming chat request.
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
        $model = $this->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');
        $finishReason = null;
        $inputTokens = 0;
        $outputTokens = 0;

        $parser = new SseParser(
            function (string $data) use (
                $onToken,
                &$accumulatedContent,
                &$finishReason,
                &$inputTokens,
                &$outputTokens
            ) {
                $json = json_decode($data, true);

                if ($json === null) {
                    return;
                }

                // Bedrock uses event-based format with base64 encoded chunks
                if (isset($json['bytes'])) {
                    $chunk = json_decode(base64_decode($json['bytes']), true);

                    if (isset($chunk['type'])) {
                        $this->processAnthropicStreamChunk(
                            $chunk,
                            $onToken,
                            $accumulatedContent,
                            $finishReason,
                            $inputTokens,
                            $outputTokens
                        );
                    } elseif (isset($chunk['generation'])) {
                        // Llama/Mistral format
                        $text = $chunk['generation'];
                        $accumulatedContent .= $text;
                        $onToken($text);

                        if (isset($chunk['stop_reason'])) {
                            $finishReason = 'stop';
                        }
                    }
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
                $usage = new Usage($inputTokens, $outputTokens);
                $response = new ChatResponse($message, $model, $usage, $finishReason ?? 'stop');
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
     * Handles error responses from Bedrock.
     *
     * @param HttpResponse $response The HTTP response to check.
     *
     * @throws AuthenticationException If auth failed.
     * @throws RateLimitException If rate limited.
     * @throws ProviderException For other errors.
     */
    protected function handleErrorResponse(HttpResponse $response): void {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $data = $response->getJson();
        $message = $data['message'] ?? $response->getBody();

        if ($status === 401 || $status === 403) {
            throw new AuthenticationException("Bedrock authentication failed: $message", $status);
        }

        if ($status === 429) {
            throw new RateLimitException("Bedrock rate limit exceeded: $message");
        }

        throw new ProviderException("Bedrock API error: $message", $status);
    }

    /**
     * Parses a chat response from Bedrock.
     *
     * @param HttpResponse $response The HTTP response.
     *
     * @return ChatResponse The parsed response.
     */
    protected function parseChatResponse(HttpResponse $response): ChatResponse {
        $data = $response->getJson();
        $modelId = $this->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');
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
     * Parses an embeddings response.
     *
     * @param HttpResponse $response The HTTP response.
     *
     * @return EmbeddingResponse The parsed response.
     *
     * @throws UnsupportedFeatureException Always.
     */
    protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
        throw new UnsupportedFeatureException(
            'Bedrock embeddings support is not yet implemented.',
            'embeddings',
            $this->getName()
        );
    }

    /**
     * Parses an image response.
     *
     * @param HttpResponse $response The HTTP response.
     *
     * @return ImageResponse The parsed response.
     *
     * @throws UnsupportedFeatureException Always.
     */
    protected function parseImageResponse(HttpResponse $response): ImageResponse {
        throw new UnsupportedFeatureException(
            'Bedrock image generation support is not yet implemented.',
            'image_generation',
            $this->getName()
        );
    }

    /**
     * Validates configuration.
     *
     * Requires either 'api_key' (API key mode) or both 'access_key' and
     * 'secret_key' (SigV4 mode). 'region' is required in both modes.
     *
     * @param array<string, mixed> $config The configuration.
     *
     * @throws InvalidConfigException If required options missing.
     */
    protected function validateConfig(array $config): void {
        if (empty($config['region'])) {
            throw new InvalidConfigException(
                'The "region" configuration option is required for Bedrock provider.',
                'region'
            );
        }

        $hasApiKey = !empty($config['api_key']);
        $hasSigV4 = !empty($config['access_key']) && !empty($config['secret_key']);

        if (!$hasApiKey && !$hasSigV4) {
            throw new InvalidConfigException(
                'Bedrock provider requires either "api_key" or both "access_key" and "secret_key".',
                'api_key'
            );
        }

        if (!$hasApiKey && empty($config['secret_key'])) {
            throw new InvalidConfigException(
                'The "secret_key" configuration option is required when using AWS credentials.',
                'secret_key'
            );
        }
    }
}
