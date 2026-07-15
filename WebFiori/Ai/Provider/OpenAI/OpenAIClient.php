<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\OpenAI;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\GeneratedImage;
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
 * OpenAI provider implementation.
 *
 * Supports chat completions, streaming, embeddings, and image generation
 * via the OpenAI API (or compatible endpoints).
 *
 * Configuration options:
 * - 'api_key' (required): OpenAI API key.
 * - 'model' (optional): Default model. Defaults to 'gpt-4o'.
 * - 'organization' (optional): OpenAI organization ID.
 * - 'base_url' (optional): API base URL. Defaults to 'https://api.openai.com/v1'.
 *
 * @author Ibrahim
 */
class OpenAIClient extends AbstractClient {
    /**
     * Returns the provider name.
     *
     * @return string The provider identifier.
     */
    public function getName(): string {
        return 'openai';
    }

    /**
     * Applies optional generation parameters to the request body.
     *
     * @param array<string, mixed> &$body The request body to modify.
     * @param array<string, mixed> $options The options to apply.
     */
    private function applyOptions(array &$body, array $options): void {
        $allowedOptions = [
            'temperature', 'max_tokens', 'top_p', 'frequency_penalty',
            'presence_penalty', 'stop', 'n',
        ];

        foreach ($allowedOptions as $option) {
            if (isset($options[$option])) {
                $body[$option] = $options[$option];
            }
        }

        if (isset($options['tools']) && count($options['tools']) > 0) {
            $body['tools'] = $this->formatTools($options['tools']);
        }
    }

    /**
     * Formats Message objects into the OpenAI messages format.
     *
     * @param Message[] $messages The messages to format.
     *
     * @return array<int, array<string, mixed>> The formatted messages array.
     */
    private function formatMessages(array $messages): array {
        $formatted = [];

        foreach ($messages as $message) {
            $entry = [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
            ];

            if ($message->hasToolCalls()) {
                $entry['tool_calls'] = [];

                foreach ($message->getToolCalls() as $toolCall) {
                    $entry['tool_calls'][] = [
                        'id' => $toolCall->getId(),
                        'type' => 'function',
                        'function' => [
                            'name' => $toolCall->getName(),
                            'arguments' => json_encode($toolCall->getArguments()),
                        ],
                    ];
                }
            }

            if ($message->getToolResult() !== null) {
                $entry['tool_call_id'] = $message->getToolResult()->getToolCallId();
                $entry['content'] = $message->getToolResult()->getContent();
            }

            $formatted[] = $entry;
        }

        return $formatted;
    }

    /**
     * Formats ToolInterface instances into the OpenAI tools format.
     *
     * @param ToolInterface[] $tools The tools to format.
     *
     * @return array<int, array<string, mixed>> The formatted tools array.
     */
    private function formatTools(array $tools): array {
        $formatted = [];

        foreach ($tools as $tool) {
            $formatted[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ],
            ];
        }

        return $formatted;
    }

    /**
     * Returns the full endpoint URL for a given path.
     *
     * @param string $path The API path (e.g., '/chat/completions').
     *
     * @return string The full URL.
     */
    private function getEndpoint(string $path): string {
        $baseUrl = $this->getConfig('base_url', 'https://api.openai.com/v1');

        return rtrim($baseUrl, '/').$path;
    }

    /**
     * Returns the HTTP headers for OpenAI API requests.
     *
     * @return array<string, string> The headers array.
     */
    private function getHeaders(): array {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->getConfig('api_key'),
        ];

        $org = $this->getConfig('organization');

        if ($org !== null) {
            $headers['OpenAI-Organization'] = $org;
        }

        return $headers;
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
        $model = $options['model'] ?? $this->getConfig('model', 'gpt-4o');
        $body = [
            'model' => $model,
            'messages' => $this->formatMessages($messages),
        ];

        $this->applyOptions($body, $options);

        return new HttpRequest(
            'POST',
            $this->getEndpoint('/chat/completions'),
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
     */
    protected function buildEmbedRequest(string|array $input, array $options): HttpRequest {
        $model = $options['model'] ?? $this->getConfig('embedding_model', 'text-embedding-3-small');
        $body = [
            'model' => $model,
            'input' => $input,
        ];

        if (isset($options['dimensions'])) {
            $body['dimensions'] = $options['dimensions'];
        }

        return new HttpRequest(
            'POST',
            $this->getEndpoint('/embeddings'),
            $this->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Builds the HTTP request for an image generation call.
     *
     * @param ImageRequest $request The image generation request.
     *
     * @return HttpRequest The HTTP request to send.
     */
    protected function buildImageRequest(ImageRequest $request): HttpRequest {
        $body = [
            'model' => $this->getConfig('image_model', 'dall-e-3'),
            'prompt' => $request->getPrompt(),
            'size' => $request->getSize(),
            'n' => $request->getCount(),
            'quality' => $request->getQuality(),
            'response_format' => $request->getFormat() === 'base64' ? 'b64_json' : 'url',
        ];

        if ($request->getStyle() !== null) {
            $body['style'] = $request->getStyle();
        }

        return new HttpRequest(
            'POST',
            $this->getEndpoint('/images/generations'),
            $this->getHeaders(),
            json_encode($body)
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
        $model = $options['model'] ?? $this->getConfig('model', 'gpt-4o');
        $body = [
            'model' => $model,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        $this->applyOptions($body, $options);

        return new HttpRequest(
            'POST',
            $this->getEndpoint('/chat/completions'),
            $this->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Executes the streaming chat request using the SSE parser.
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
        $model = '';
        $finishReason = null;

        $parser = new SseParser(
            function (string $data) use ($onToken, &$accumulatedContent, &$model, &$finishReason)
            {
                $json = json_decode($data, true);

                if ($json === null) {
                    return;
                }

                if (isset($json['model'])) {
                    $model = $json['model'];
                }

                $choices = $json['choices'] ?? [];

                if (empty($choices)) {
                    return;
                }

                $delta = $choices[0]['delta'] ?? [];
                $finishReason = $choices[0]['finish_reason'] ?? $finishReason;

                if (isset($delta['content'])) {
                    $token = $delta['content'];

                    if ($token !== '') {
                        $accumulatedContent .= $token;
                        $onToken($token);
                    }
                }
            },
            function () use ($onComplete, &$accumulatedContent, &$model, &$finishReason)
            {
                if ($onComplete !== null) {
                    $message = new Message('assistant', $accumulatedContent);
                    $response = new ChatResponse($message, $model, null, $finishReason);
                    $onComplete($response);
                }
            }
        );

        try {
            $this->getHttpClient()->sendStreaming($request, function (string $chunk) use ($parser)
            {
                $parser->feed($chunk);
            });
        } catch (StreamingException $e) {
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
     * @throws AuthenticationException If status is 401 or 403.
     * @throws RateLimitException If status is 429.
     * @throws ProviderException If status indicates a server error.
     */
    protected function handleErrorResponse(HttpResponse $response): void {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = json_decode($response->getBody(), true);
        $errorMessage = $body['error']['message'] ?? 'Unknown error';
        $errorCode = $body['error']['code'] ?? null;

        if ($status === 401 || $status === 403) {
            throw new AuthenticationException($errorMessage, $status);
        }

        if ($status === 429) {
            $retryAfter = $response->getHeader('Retry-After');

            throw new RateLimitException(
                $errorMessage,
                $retryAfter !== null ? (int) $retryAfter : null
            );
        }

        throw new ProviderException($errorMessage, $status, $errorCode);
    }

    /**
     * Parses an HTTP response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from OpenAI.
     *
     * @return ChatResponse The parsed chat response.
     */
    protected function parseChatResponse(HttpResponse $response): ChatResponse {
        $data = $response->getJson();
        $choice = $data['choices'][0] ?? [];
        $messageData = $choice['message'] ?? [];

        $toolCalls = [];

        if (isset($messageData['tool_calls'])) {
            foreach ($messageData['tool_calls'] as $tc) {
                $toolCalls[] = new ToolCall(
                    $tc['id'],
                    $tc['function']['name'],
                    json_decode($tc['function']['arguments'] ?? '{}', true) ?? []
                );
            }
        }

        $message = new Message(
            $messageData['role'] ?? 'assistant',
            $messageData['content'] ?? '',
            $toolCalls
        );

        $usage = null;

        if (isset($data['usage'])) {
            $usage = new Usage(
                $data['usage']['prompt_tokens'] ?? 0,
                $data['usage']['completion_tokens'] ?? 0
            );
        }

        return new ChatResponse(
            $message,
            $data['model'] ?? '',
            $usage,
            $choice['finish_reason'] ?? null
        );
    }

    /**
     * Parses an HTTP response into an EmbeddingResponse.
     *
     * @param HttpResponse $response The HTTP response from OpenAI.
     *
     * @return EmbeddingResponse The parsed embedding response.
     */
    protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
        $data = $response->getJson();
        $vectors = [];

        foreach ($data['data'] ?? [] as $item) {
            $vectors[] = $item['embedding'];
        }

        $usage = null;

        if (isset($data['usage'])) {
            $usage = new Usage($data['usage']['prompt_tokens'] ?? 0, 0);
        }

        return new EmbeddingResponse($vectors, $data['model'] ?? '', $usage);
    }

    /**
     * Parses an HTTP response into an ImageResponse.
     *
     * @param HttpResponse $response The HTTP response from OpenAI.
     *
     * @return ImageResponse The parsed image response.
     */
    protected function parseImageResponse(HttpResponse $response): ImageResponse {
        $data = $response->getJson();
        $images = [];

        foreach ($data['data'] ?? [] as $item) {
            $images[] = new GeneratedImage(
                $item['url'] ?? null,
                $item['b64_json'] ?? null,
                $item['revised_prompt'] ?? null
            );
        }

        return new ImageResponse($images, $this->getConfig('image_model', 'dall-e-3'));
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
                'The "api_key" configuration option is required for OpenAI provider.',
                'api_key'
            );
        }
    }
}
