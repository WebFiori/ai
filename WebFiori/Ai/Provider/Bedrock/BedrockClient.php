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

use WebFiori\Ai\Auth\AwsCredentialChain;
use WebFiori\Ai\Auth\AwsSigner;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;

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
 * **Invocation API selection:**
 * ```php
 * use WebFiori\Ai\Provider\Bedrock\ApiMethod;
 *
 * $client = new BedrockClient([
 *     'api_key'    => 'your-key',
 *     'region'     => 'us-east-1',
 *     'api_method' => ApiMethod::CONVERSE,   // default — recommended
 *     // 'api_method' => ApiMethod::INVOKE,  // raw model format
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
 * - 'api_method' (optional): Invocation API to use. One of the {@see ApiMethod} constants.
 *   Defaults to {@see ApiMethod::CONVERSE}.
 *
 * @author Ibrahim
 */
class BedrockClient extends AbstractClient {
    /**
     * The AWS SigV4 signer (only used in SigV4 mode).
     *
     * @var AwsSigner|null
     */
    private ?AwsSigner $signer = null;
    /**
     * The active invocation strategy.
     *
     * @var InvocationStrategyInterface
     */
    private InvocationStrategyInterface $strategy;

    /**
     * Creates a new BedrockClient instance.
     *
     * @param BedrockClientConfig $config Provider configuration.
     *
     * @throws InvalidConfigException If required options are missing.
     */
    public function __construct(BedrockClientConfig $config) {
        parent::__construct($config);

        if ($config->accessKey !== null) {
            // Explicit credentials — use directly
            $this->signer = new AwsSigner(
                $config->accessKey,
                $config->secretKey,
                $config->region,
                'bedrock',
                $config->sessionToken
            );
        } elseif ($config->apiKey === null) {
            // No explicit credentials — try credential chain
            $chain = new AwsCredentialChain();
            $creds = $chain->resolve();

            if ($creds !== null) {
                $this->signer = new AwsSigner(
                    $creds['access_key'],
                    $creds['secret_key'],
                    $config->region,
                    'bedrock',
                    $creds['session_token']
                );
            }
        }

        $this->strategy = $this->createStrategy($config->apiMethod);
    }

    /**
     * Returns the Bedrock Runtime endpoint URL for the given model and operation.
     *
     * This method is public so invocation strategies can build their request URLs.
     *
     * @param string $modelId The Bedrock model ID.
     * @param string $operation The API operation
     *        (e.g., 'invoke', 'converse', 'invoke-with-response-stream', 'converse-stream').
     *
     * @return string The full endpoint URL.
     */
    public function getBedrockEndpoint(string $modelId, string $operation): string {
        $region = $this->getConfig('region');

        return sprintf(
            'https://bedrock-runtime.%s.amazonaws.com/model/%s/%s',
            $region,
            $modelId,
            $operation
        );
    }

    /**
     * Returns signed or authenticated headers for a Bedrock request.
     *
     * Uses Bearer token when 'api_key' is configured, otherwise signs
     * using AWS Signature Version 4.
     *
     * This method is public so invocation strategies can obtain request headers.
     *
     * @param string $method HTTP method.
     * @param string $url Request URL.
     * @param string $body Request body.
     *
     * @return array<string, string> Request headers.
     */
    public function getBedrockHeaders(string $method, string $url, string $body, string $accept = 'application/json'): array {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $accept,
        ];

        if ($this->signer === null) {
            $headers['Authorization'] = 'Bearer '.$this->getConfig('api_key');

            return $headers;
        }

        return $this->signer->sign($method, $url, $headers, $body);
    }

    /**
     * Returns the HTTP client. Exposed for strategy use.
     *
     * @return HttpClientInterface The HTTP client instance.
     */
    public function getHttpClient(): HttpClientInterface {
        return parent::getHttpClient();
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
     * Performs a health check using the ListFoundationModels endpoint.
     *
     * @param int $timeout Timeout in seconds for the health check.
     *
     * @return HealthCheckResult The health check result.
     */
    public function healthCheck(int $timeout = 5): HealthCheckResult {
        $startTime = microtime(true);
        $checkMethod = 'models_list';
        $region = $this->getConfig('region');
        $url = "https://bedrock.{$region}.amazonaws.com/foundation-models";

        try {
            $headers = $this->getBedrockHeaders('GET', $url, '');
            $headers['Accept'] = 'application/json';

            $request = new HttpRequest('GET', $url, $headers, '');

            $httpClient = new CurlHttpClient($timeout, $timeout);
            $response = $httpClient->send($request);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return HealthCheckResult::success($latencyMs, $checkMethod);
            }

            $responseBody = $response->getJson();
            $error = $responseBody['message'] ?? 'HTTP '.$response->getStatusCode();

            return HealthCheckResult::failure($error, $latencyMs, $checkMethod);
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return HealthCheckResult::failure($e->getMessage(), $latencyMs, $checkMethod);
        }
    }

    /**
     * Creates the invocation strategy for the given API method.
     *
     * @param string $apiMethod One of the {@see ApiMethod} constants.
     *
     * @return InvocationStrategyInterface The strategy instance.
     *
     * @throws InvalidConfigException If the API method is not recognized.
     */
    private function createStrategy(string $apiMethod): InvocationStrategyInterface {
        return match ($apiMethod) {
            ApiMethod::CONVERSE => new ConverseStrategy(),
            ApiMethod::INVOKE => new InvokeStrategy(),
            ApiMethod::RESPONSES => new ResponsesStrategy(),
            default => throw new InvalidConfigException(
                "Unknown api_method \"$apiMethod\". Use one of the ApiMethod constants.",
                'api_method'
            ),
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
        $modelId = $options[ChatOption::MODEL] ?? $this->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');

        return $this->strategy->buildChatRequest($modelId, $messages, $options, $this);
    }

    /**
     * Builds the HTTP request for an embeddings call.
     *
     * @param string|string[] $input The text input(s) to embed.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     *
     * @throws UnsupportedFeatureException Always.
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
     * @throws UnsupportedFeatureException Always.
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
        $modelId = $options[ChatOption::MODEL] ?? $this->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');

        return $this->strategy->buildStreamChatRequest($modelId, $messages, $options, $this);
    }

    /**
     * Executes the streaming chat request via the active strategy.
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
        $this->strategy->doStreamChat($request, $this, $onToken, $onComplete, $onError);
    }

    /**
     * Inspects an HTTP response and throws the appropriate exception for errors.
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
     * Parses an HTTP response into a ChatResponse via the active strategy.
     *
     * @param HttpResponse $response The HTTP response.
     *
     * @return ChatResponse The parsed chat response.
     */
    protected function parseChatResponse(HttpResponse $response): ChatResponse {
        $modelId = $this->getConfig('model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');

        return $this->strategy->parseChatResponse($response, $modelId);
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
     * Requires either 'api_key' or both 'access_key' and 'secret_key'.
     * 'region' is required in both modes.
     *
     * @param array<string, mixed> $config The configuration.
     *
     * @throws InvalidConfigException If required options are missing.
     */
    protected function validateConfig(array $config): void {
        if (empty($config['region'])) {
            throw new InvalidConfigException(
                'The "region" configuration option is required for Bedrock provider.',
                'region'
            );
        }

        // Explicit api_key or access_key+secret_key are valid
        // No credentials = credential chain will be tried at request time (IAM roles, env vars, ~/.aws/credentials)
        $hasApiKey = !empty($config['api_key']);
        $hasExplicitKey = !empty($config['access_key']);

        if ($hasExplicitKey && empty($config['secret_key'])) {
            throw new InvalidConfigException(
                'The "secret_key" configuration option is required when "access_key" is provided.',
                'secret_key'
            );
        }

        // If none of the above, credential chain will be attempted — no error here
    }
}
