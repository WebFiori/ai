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

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\GeneratedImage;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\SseParser;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\OpenAIBuiltInTool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Usage;

/**
 * Formats requests and parses responses for the OpenAI Chat Completions API.
 *
 * This formatter encapsulates all OpenAI-specific serialization logic and can
 * be reused across different transport contexts — direct API calls via
 * OpenAIClient, Azure OpenAI, OpenAI-compatible endpoints (Groq, Together AI),
 * or any future transport using the OpenAI request/response format.
 *
 * @author Ibrahim
 */
class OpenAIFormatter implements ProviderFormatterInterface {
    /**
     * The OpenAI client configuration.
     *
     * @var OpenAIClientConfig
     */
    private OpenAIClientConfig $config;

    /**
     * Optional logging callback.
     *
     * @var callable|null
     */
    private $logCallback;

    /**
     * Creates a new OpenAIFormatter instance.
     *
     * @param OpenAIClientConfig $config The OpenAI client configuration.
     * @param callable|null $logCallback Optional logging callback.
     */
    public function __construct(OpenAIClientConfig $config, ?callable $logCallback = null) {
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
        $model = $options['model'] ?? $this->config->model;
        $body = [
            'model' => $model,
            'messages' => $this->formatMessages($messages, $options),
        ];

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
        $model = $options['model'] ?? $this->config->embeddingModel;
        $body = ['model' => $model, 'input' => $input];

        if (isset($options['dimensions'])) {
            $body['dimensions'] = $options['dimensions'];
        }

        return new HttpRequest('POST', $endpointUrl, $headers, json_encode($body));
    }

    /**
     * {@inheritdoc}
     */
    public function buildImageRequest(
        ImageRequest $request,
        string $endpointUrl,
        array $headers
    ): HttpRequest {
        $body = [
            'model' => $this->config->imageModel,
            'prompt' => $request->getPrompt(),
            'size' => $request->getSize(),
            'n' => $request->getCount(),
            'quality' => $request->getQuality(),
            'response_format' => $request->getFormat() === 'base64' ? 'b64_json' : 'url',
        ];

        if ($request->getStyle() !== null) {
            $body['style'] = $request->getStyle();
        }

        return new HttpRequest('POST', $endpointUrl, $headers, json_encode($body));
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
        $model = $options['model'] ?? $this->config->model;
        $body = [
            'model' => $model,
            'messages' => $this->formatMessages($messages, $options),
            'stream' => true,
        ];

        $this->applyOptions($body, $options);

        return new HttpRequest('POST', $endpointUrl, $headers, json_encode($body));
    }

    /**
     * Executes a streaming chat request using SSE parsing.
     *
     * @param HttpRequest $request The streaming HTTP request.
     * @param \WebFiori\Ai\Http\HttpClientInterface $httpClient The HTTP client.
     * @param callable $onToken Token callback.
     * @param callable|null $onComplete Completion callback.
     * @param callable|null $onError Error callback.
     */
    public function executeStreamChat(
        HttpRequest $request,
        \WebFiori\Ai\Http\HttpClientInterface $httpClient,
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

                if (isset($delta['content']) && $delta['content'] !== '') {
                    $token = $delta['content'];
                    $accumulatedContent .= $token;
                    $onToken($token);
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
            $httpClient->sendStreaming($request, function (string $chunk) use ($parser)
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
     * {@inheritdoc}
     */
    public function handleErrorResponse(HttpResponse $response): void {
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
     * {@inheritdoc}
     */
    public function parseChatResponse(HttpResponse $response): ChatResponse {
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
     * {@inheritdoc}
     */
    public function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
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
     * {@inheritdoc}
     */
    public function parseImageResponse(HttpResponse $response): ImageResponse {
        $data = $response->getJson();
        $images = [];

        foreach ($data['data'] ?? [] as $item) {
            $images[] = new GeneratedImage(
                $item['url'] ?? null,
                $item['b64_json'] ?? null,
                $item['revised_prompt'] ?? null
            );
        }

        return new ImageResponse($images, $this->config->imageModel);
    }

    /**
     * Applies optional generation parameters to the request body.
     */
    private function applyOptions(array &$body, array $options): void {
        foreach (['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'n'] as $option) {
            if (isset($options[$option])) {
                $body[$option] = $options[$option];
            }
        }

        if (isset($options['tools']) && count($options['tools']) > 0) {
            $body['tools'] = $this->formatTools($options['tools']);
        }

        if (isset($options['built_in_tools']) && count($options['built_in_tools']) > 0) {
            $builtIn = $this->formatBuiltInTools($options['built_in_tools']);
            $body['tools'] = array_merge($body['tools'] ?? [], $builtIn);
        }

        if (isset($options['json_schema'])) {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => $options['json_schema']];
        } elseif (!empty($options['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }
    }

    /**
     * Formats built-in tools into the OpenAI format.
     */
    private function formatBuiltInTools(array $builtInTools): array {
        $formatted = [];

        foreach ($builtInTools as $tool) {
            if (!($tool instanceof OpenAIBuiltInTool)) {
                throw new UnsupportedFeatureException(
                    'built_in_tools:'.get_class($tool),
                    'OpenAIClient'
                );
            }

            $formatted[] = ['type' => $tool->getValue()];
        }

        return $formatted;
    }

    /**
     * Formats ContentPart objects into the OpenAI content array format.
     */
    private function formatContentParts(array $parts, string $imageDetail): array {
        $formatted = [];

        foreach ($parts as $part) {
            switch ($part->getType()) {
                case ContentPart::TYPE_TEXT:
                    $formatted[] = ['type' => 'text', 'text' => $part->getData()['text']];

                    break;

                case ContentPart::TYPE_IMAGE_URL:
                    $formatted[] = ['type' => 'image_url', 'image_url' => ['url' => $part->getData()['url'], 'detail' => $imageDetail]];

                    break;

                case ContentPart::TYPE_IMAGE_BASE64:
                    $data = $part->getData();
                    $dataUrl = 'data:'.$data['mime_type'].';base64,'.$data['data'];
                    $formatted[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => $imageDetail]];

                    break;

                case ContentPart::TYPE_DOCUMENT:
                    $data = $part->getData();
                    $mimeType = $data['mime_type'];

                    if (str_starts_with($mimeType, 'image/')) {
                        $dataUrl = 'data:'.$mimeType.';base64,'.$data['data'];
                        $formatted[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => $imageDetail]];
                    } else {
                        $dataUrl = 'data:'.$mimeType.';base64,'.$data['data'];
                        $formatted[] = ['type' => 'file', 'file' => ['file_data' => $dataUrl]];
                    }

                    break;

                case ContentPart::TYPE_FILE_GCS:
                    $data = $part->getData();
                    $this->log('warning', 'OpenAI does not natively support GCS URIs. The file must be publicly accessible.');
                    $httpsUrl = 'https://storage.googleapis.com/'.substr($data['uri'], 5);
                    $mimeType = $data['mime_type'];

                    if (str_starts_with($mimeType, 'image/')) {
                        $formatted[] = ['type' => 'image_url', 'image_url' => ['url' => $httpsUrl, 'detail' => $imageDetail]];
                    } else {
                        $formatted[] = ['type' => 'file', 'file' => ['url' => $httpsUrl]];
                    }

                    break;
            }
        }

        return $formatted;
    }

    /**
     * Formats Message objects into the OpenAI messages format.
     */
    private function formatMessages(array $messages, array $options = []): array {
        $formatted = [];
        $imageDetail = $options['detail'] ?? 'auto';

        foreach ($messages as $message) {
            $entry = ['role' => $message->getRole()];

            if ($message->isMultiModal()) {
                $entry['content'] = $this->formatContentParts($message->getContentParts(), $imageDetail);
            } else {
                $entry['content'] = $message->getContent();
            }

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
     * Formats tools into the OpenAI function-calling format.
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
     * Logs a message via the configured callback.
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logCallback !== null) {
            ($this->logCallback)($level, "[OpenAIFormatter] {$message}", $context);
        }
    }
}
