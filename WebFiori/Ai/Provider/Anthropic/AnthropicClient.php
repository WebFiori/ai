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
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Provider\Formatter\AnthropicFormatter;

/**
 * Anthropic Claude provider implementation.
 *
 * Supports chat completions, streaming, and tool/function calling
 * via the Anthropic Messages API.
 *
 * Note: Anthropic does NOT support embeddings or image generation.
 * Those methods will throw UnsupportedFeatureException.
 *
 * @author Ibrahim
 */
class AnthropicClient extends AbstractClient {
    /**
     * The Anthropic formatter instance.
     *
     * @var AnthropicFormatter
     */
    private AnthropicFormatter $formatter;

    /**
     * Creates a new AnthropicClient instance.
     *
     * @param AnthropicClientConfig $config Provider configuration.
     */
    public function __construct(AnthropicClientConfig $config) {
        parent::__construct($config);
        $this->formatter = new AnthropicFormatter($config, $this->getLogCallback());
    }

    /**
     * Returns the provider name.
     *
     * @return string The provider identifier.
     */
    public function getName(): string {
        return 'anthropic';
    }

    /**
     * Performs a health check by making a minimal completion request.
     *
     * @param int $timeout Timeout in seconds for the health check.
     *
     * @return HealthCheckResult The health check result.
     */
    public function healthCheck(int $timeout = 5): HealthCheckResult {
        $startTime = microtime(true);
        $checkMethod = 'minimal_completion';

        try {
            $requestBody = json_encode([
                'model' => $this->getConfig('model', 'claude-sonnet-4-20250514'),
                'max_tokens' => 1,
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ]);

            $request = new HttpRequest(
                'POST',
                $this->getEndpoint('/v1/messages'),
                $this->getHeaders(),
                $requestBody
            );

            $httpClient = new CurlHttpClient($timeout, $timeout);
            $response = $httpClient->send($request);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return HealthCheckResult::success($latencyMs, $checkMethod);
            }

            $body = $response->getJson();
            $error = $body['error']['message'] ?? 'HTTP '.$response->getStatusCode();

            return HealthCheckResult::failure($error, $latencyMs, $checkMethod);
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return HealthCheckResult::failure($e->getMessage(), $latencyMs, $checkMethod);
        }
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
     * {@inheritdoc}
     */
    protected function buildChatRequest(array $messages, array $options): HttpRequest {
        return $this->formatter->buildChatRequest(
            $messages,
            $options,
            $this->getEndpoint('/v1/messages'),
            $this->getHeaders()
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function buildEmbedRequest(string|array $input, array $options): HttpRequest {
        return $this->formatter->buildEmbedRequest($input, $options, '', []);
    }

    /**
     * {@inheritdoc}
     */
    protected function buildImageRequest(ImageRequest $request): HttpRequest {
        return $this->formatter->buildImageRequest($request, '', []);
    }

    /**
     * {@inheritdoc}
     */
    protected function buildStreamChatRequest(array $messages, array $options): HttpRequest {
        return $this->formatter->buildStreamChatRequest(
            $messages,
            $options,
            $this->getEndpoint('/v1/messages'),
            $this->getHeaders()
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function doStreamChat(
        HttpRequest $request,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void {
        $this->formatter->executeStreamChat(
            $request,
            $this->getHttpClient(),
            $onToken,
            $onComplete,
            $onError
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function handleErrorResponse(HttpResponse $response): void {
        $this->formatter->handleErrorResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    protected function parseChatResponse(HttpResponse $response): ChatResponse {
        return $this->formatter->parseChatResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
        return $this->formatter->parseEmbedResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    protected function parseImageResponse(HttpResponse $response): ImageResponse {
        return $this->formatter->parseImageResponse($response);
    }

    /**
     * {@inheritdoc}
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
