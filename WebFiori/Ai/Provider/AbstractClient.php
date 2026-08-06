<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider;

use WebFiori\Ai\Cache\CachedResponse;
use WebFiori\Ai\Cache\CacheConfig;
use WebFiori\Ai\Cache\CacheInterface;
use WebFiori\Ai\Cache\CacheKeyGenerator;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\RateLimitAwareHttpClient;
use WebFiori\Ai\Http\RetryableHttpClient;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\LoggerTrait;
use WebFiori\Ai\Message;
use WebFiori\Ai\RateLimitStatus;
use WebFiori\Ai\RetryConfig;
use WebFiori\Ai\Tool\ToolInterface;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Base class for AI provider implementations.
 *
 * Provides shared functionality for configuration management, HTTP client
 * handling, logging, caching, and the template for provider-specific operations.
 * Concrete providers extend this class and implement the abstract methods
 * to handle their specific API formats.
 *
 * @author Ibrahim
 */
abstract class AbstractClient implements ProviderInterface {
    /**
     * Cache implementation for storing responses.
     *
     * @var CacheInterface|null
     */
    private ?CacheInterface $cache = null;

    /**
     * Cache configuration.
     *
     * @var CacheConfig
     */
    private CacheConfig $cacheConfig;

    /**
     * Cache key generator.
     *
     * @var CacheKeyGenerator
     */
    private CacheKeyGenerator $cacheKeyGenerator;

    /**
     * Provider configuration options.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * The HTTP client used for making API requests.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $httpClient;

    /**
     * Creates a new provider instance.
     *
     * @param array<string, mixed> $config Provider configuration. Common options:
     *        - 'model': Default model to use for requests.
     *        - 'timeout': Request timeout in seconds.
     *        - 'connect_timeout': Connection timeout in seconds.
     *
     * @throws InvalidConfigException If required configuration is missing.
     */
    public function __construct(array $config = []) {
        $this->config = $config;
        $this->httpClient = new CurlHttpClient(
            $config['timeout'] ?? 120,
            $config['connect_timeout'] ?? 10
        );
        $this->cacheConfig = new CacheConfig(enabled: false);
        $this->cacheKeyGenerator = new CacheKeyGenerator();
        $this->validateConfig($config);
    }

    /**
     * Sends a chat completion request and returns the full response.
     *
     * Handles logging, request building, HTTP transport, response parsing,
     * and error mapping. Delegates provider-specific logic to abstract methods.
     *
     * If caching is enabled and the request is cacheable (based on temperature
     * settings), cached responses are returned when available.
     *
     * @param Message[] $messages An array of messages forming the conversation.
     * @param array<string, mixed> $options Additional options (e.g., temperature,
     *        max_tokens, model override).
     *
     * @return ChatResponse The AI-generated response.
     *
     * @throws \WebFiori\Ai\Exception\AuthenticationException If credentials are invalid.
     * @throws \WebFiori\Ai\Exception\RateLimitException If the rate limit is exceeded.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     * @throws \WebFiori\Ai\Exception\HttpException If a transport error occurs.
     */
    public function chat(array $messages, array $options = []): ChatResponse {
        $model = $options['model'] ?? $this->getConfig('model');
        $startTime = microtime(true);
        $autoExecute = $options['auto_execute_tools'] ?? false;
        $temperature = $options['temperature'] ?? null;

        // Check cache (skip if auto_execute_tools is enabled due to side effects)
        $cacheKey = null;
        $shouldCache = !$autoExecute && $this->cache !== null
            && $this->cacheConfig->shouldCacheChat($temperature);

        if ($shouldCache) {
            $cacheKey = $this->cacheKeyGenerator->forChat(
                $this->getName(),
                $model,
                $messages,
                $options
            );

            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                $this->logInfo('Chat cache hit', [
                    'provider' => $this->getName(),
                    'model' => $model,
                    'cache_key' => $cacheKey,
                ]);

                return $cached->getData();
            }
        }

        $this->logInfo('Chat request started', [
            'provider' => $this->getName(),
            'model' => $model,
            'message_count' => count($messages),
        ]);

        $request = $this->buildChatRequest($messages, $options);
        $httpResponse = $this->sendRequest($request);
        $this->handleErrorResponse($httpResponse);
        $response = $this->parseChatResponse($httpResponse);

        $tools = $options['tools'] ?? [];
        $maxIterations = $options['max_tool_iterations'] ?? 10;

        if ($autoExecute && count($tools) > 0) {
            $iteration = 0;

            while ($response->hasToolCalls() && $iteration < $maxIterations) {
                $iteration++;
                $messages[] = $response->getMessage();

                foreach ($response->getMessage()->getToolCalls() as $toolCall) {
                    $tool = $this->findTool($tools, $toolCall->getName());
                    $result = $tool !== null ? $tool->execute($toolCall->getArguments()) : '';

                    $this->logDebug('Tool executed', [
                        'tool' => $toolCall->getName(),
                        'iteration' => $iteration,
                    ]);

                    $messages[] = new Message(
                        'tool',
                        '',
                        [],
                        new ToolResult($toolCall->getId(), $result)
                    );
                }

                $request = $this->buildChatRequest($messages, $options);
                $httpResponse = $this->sendRequest($request);
                $this->handleErrorResponse($httpResponse);
                $response = $this->parseChatResponse($httpResponse);
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->logInfo('Chat request completed', [
            'provider' => $this->getName(),
            'model' => $response->getModel(),
            'finish_reason' => $response->getFinishReason(),
            'duration_ms' => $durationMs,
            'prompt_tokens' => $response->getUsage()?->getPromptTokens(),
            'completion_tokens' => $response->getUsage()?->getCompletionTokens(),
            'total_tokens' => $response->getUsage()?->getTotalTokens(),
        ]);

        // Store in cache
        if ($shouldCache && $cacheKey !== null) {
            $this->cache->set(
                $cacheKey,
                new CachedResponse($response, 'chat'),
                $this->cacheConfig->getDefaultTtl()
            );

            $this->logDebug('Chat response cached', [
                'cache_key' => $cacheKey,
                'ttl' => $this->cacheConfig->getDefaultTtl(),
            ]);
        }

        return $response;
    }

    /**
     * Generates vector embeddings for the given text input.
     *
     * If caching is enabled, embeddings are cached since they are deterministic
     * (same input always produces the same vector).
     *
     * @param string|string[] $input A single text string or an array of strings.
     * @param array<string, mixed> $options Additional provider-specific options.
     *
     * @return EmbeddingResponse The embedding response containing vector(s).
     *
     * @throws \WebFiori\Ai\Exception\UnsupportedFeatureException If not supported.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     */
    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        $model = $options['model'] ?? $this->getConfig('embedding_model', $this->getConfig('model'));

        // Check cache
        $cacheKey = null;
        $shouldCache = $this->cache !== null && $this->cacheConfig->shouldCacheEmbedding();

        if ($shouldCache) {
            $cacheKey = $this->cacheKeyGenerator->forEmbedding(
                $this->getName(),
                $model,
                $input,
                $options
            );

            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                $this->logInfo('Embedding cache hit', [
                    'provider' => $this->getName(),
                    'model' => $model,
                    'cache_key' => $cacheKey,
                ]);

                return $cached->getData();
            }
        }

        $request = $this->buildEmbedRequest($input, $options);
        $httpResponse = $this->sendRequest($request);
        $this->handleErrorResponse($httpResponse);

        $response = $this->parseEmbedResponse($httpResponse);

        // Store in cache
        if ($shouldCache && $cacheKey !== null) {
            $this->cache->set(
                $cacheKey,
                new CachedResponse($response, 'embedding'),
                $this->cacheConfig->getEmbeddingTtl()
            );

            $this->logDebug('Embedding response cached', [
                'cache_key' => $cacheKey,
                'ttl' => $this->cacheConfig->getEmbeddingTtl(),
            ]);
        }

        return $response;
    }

    /**
     * Enables rate limit header tracking on this provider.
     *
     * Wraps the current HTTP client in a RateLimitAwareHttpClient decorator.
     * After each API call, rate limit headers are parsed and stored. Use
     * {@see getRateLimitStatus()} to read the current status.
     *
     * A warning is logged when remaining capacity drops below the threshold.
     *
     * ```php
     * $provider->enableRateLimitTracking(warningThreshold: 0.1); // warn at <10%
     * $response = $provider->chat($messages);
     * $status = $provider->getRateLimitStatus();
     * echo $status?->getRemainingRequests();
     * ```
     *
     * @param float $warningThreshold Fraction of remaining capacity (0.0–1.0) below
     *        which a warning is logged. Default is 0.1 (10%).
     */
    public function enableRateLimitTracking(float $warningThreshold = 0.1): void {
        $inner = $this->httpClient instanceof RateLimitAwareHttpClient
            ? $this->httpClient->getInner()
            : $this->httpClient;

        $this->httpClient = new RateLimitAwareHttpClient($inner, $warningThreshold, $this->getLogCallback());
    }

    /**
     * Generates an image from a text prompt.
     *
     * @param ImageRequest $request The image generation request.
     *
     * @return ImageResponse The response containing generated image(s).
     *
     * @throws \WebFiori\Ai\Exception\UnsupportedFeatureException If not supported.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     */
    public function generateImage(ImageRequest $request): ImageResponse {
        $httpRequest = $this->buildImageRequest($request);
        $httpResponse = $this->sendRequest($httpRequest);
        $this->handleErrorResponse($httpResponse);

        return $this->parseImageResponse($httpResponse);
    }

    /**
     * Returns a configuration value by key.
     *
     * @param string $key The configuration key.
     * @param mixed $default The default value if the key is not set.
     *
     * @return mixed The configuration value or the default.
     */
    public function getConfig(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    /**
     * Returns the HTTP client used for making API requests.
     *
     * @return HttpClientInterface The HTTP client instance.
     */
    public function getHttpClient(): HttpClientInterface {
        return $this->httpClient;
    }

    /**
     * Returns the last known rate limit status from the most recent API response.
     *
     * Returns null if rate limit tracking is not enabled, no requests have been
     * made yet, or the provider does not include rate limit headers in responses.
     *
     * @return RateLimitStatus|null The current rate limit status.
     */
    public function getRateLimitStatus(): ?RateLimitStatus {
        if ($this->httpClient instanceof RateLimitAwareHttpClient) {
            return $this->httpClient->getLastStatus();
        }

        return null;
    }

    /**
     * Sets the HTTP client used for making API requests.
     *
     * @param HttpClientInterface $client The HTTP client to use.
     */
    public function setHttpClient(HttpClientInterface $client): void {
        $this->httpClient = $client;
    }

    /**
     * Sets the cache implementation for storing responses.
     *
     * When a cache is set, responses from chat() and embed() calls will be
     * stored and retrieved based on the cache configuration.
     *
     * ```php
     * $provider->setCache(new InMemoryCache());
     * $provider->setCacheConfig(new CacheConfig(defaultTtl: 3600));
     * ```
     *
     * @param CacheInterface|null $cache The cache implementation, or null to disable.
     */
    public function setCache(?CacheInterface $cache): void {
        $this->cache = $cache;

        if ($cache !== null && !$this->cacheConfig->isEnabled()) {
            $this->cacheConfig = new CacheConfig(enabled: true);
        }
    }

    /**
     * Sets the cache configuration.
     *
     * Controls TTL values and which requests should be cached based on
     * parameters like temperature.
     *
     * ```php
     * $provider->setCacheConfig(new CacheConfig(
     *     enabled: true,
     *     defaultTtl: 3600,
     *     embeddingTtl: 86400,
     *     skipCacheAboveTemperature: 0.0, // Only cache temperature=0
     * ));
     * ```
     *
     * @param CacheConfig $config The cache configuration.
     */
    public function setCacheConfig(CacheConfig $config): void {
        $this->cacheConfig = $config;
    }

    /**
     * Returns the current cache implementation.
     *
     * @return CacheInterface|null The cache, or null if not set.
     */
    public function getCache(): ?CacheInterface {
        return $this->cache;
    }

    /**
     * Returns the current cache configuration.
     *
     * @return CacheConfig The cache configuration.
     */
    public function getCacheConfig(): CacheConfig {
        return $this->cacheConfig;
    }

    /**
     * Configures automatic retry with exponential backoff for failed requests.
     *
     * Wraps the current HTTP client in a RetryableHttpClient decorator. Must
     * be called after setHttpClient() if a custom client is used.
     *
     * ```php
     * $provider->setRetryConfig(new RetryConfig(
     *     maxRetries: 3,
     *     initialDelayMs: 1000,
     *     backoffMultiplier: 2.0,
     * ));
     * ```
     *
     * @param RetryConfig $config Retry configuration.
     */
    public function setRetryConfig(RetryConfig $config): void {
        $inner = $this->httpClient instanceof RetryableHttpClient
            ? $this->httpClient->getInner()
            : $this->httpClient;

        $this->httpClient = new RetryableHttpClient($inner, $config, $this->getLogCallback());
    }

    /**
     * Sends a chat completion request with streaming response.
     *
     * @param Message[] $messages An array of messages forming the conversation.
     * @param callable $onToken Callback invoked for each token received.
     *        Signature: function(string $token): void
     * @param callable|null $onComplete Optional callback when streaming completes.
     *        Signature: function(ChatResponse $response): void
     * @param callable|null $onError Optional callback on stream error.
     *        Signature: function(\WebFiori\Ai\Exception\StreamingException $e): void
     * @param array<string, mixed> $options Additional provider-specific options.
     *
     * @throws \WebFiori\Ai\Exception\AuthenticationException If credentials are invalid.
     * @throws \WebFiori\Ai\Exception\RateLimitException If the rate limit is exceeded.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     */
    public function streamChat(
        array $messages,
        callable $onToken,
        ?callable $onComplete = null,
        ?callable $onError = null,
        array $options = []
    ): void {
        $model = $options['model'] ?? $this->getConfig('model');

        $this->logInfo('Stream chat request started', [
            'provider' => $this->getName(),
            'model' => $model,
            'message_count' => count($messages),
        ]);

        $request = $this->buildStreamChatRequest($messages, $options);
        $this->doStreamChat($request, $onToken, $onComplete, $onError);
    }

    /**
     * Finds a tool by name from an array of tools.
     *
     * @param ToolInterface[] $tools The available tools.
     * @param string $name The tool name to find.
     *
     * @return ToolInterface|null The matching tool, or null if not found.
     */
    private function findTool(array $tools, string $name): ?ToolInterface {
        foreach ($tools as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Builds the HTTP request for a chat completion call.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    abstract protected function buildChatRequest(array $messages, array $options): HttpRequest;

    /**
     * Builds the HTTP request for an embeddings call.
     *
     * @param string|string[] $input The text input(s) to embed.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    abstract protected function buildEmbedRequest(string|array $input, array $options): HttpRequest;

    /**
     * Builds the HTTP request for an image generation call.
     *
     * @param ImageRequest $request The image generation request.
     *
     * @return HttpRequest The HTTP request to send.
     */
    abstract protected function buildImageRequest(ImageRequest $request): HttpRequest;

    /**
     * Builds the HTTP request for a streaming chat completion call.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    abstract protected function buildStreamChatRequest(array $messages, array $options): HttpRequest;

    /**
     * Executes the streaming chat request.
     *
     * @param HttpRequest $request The HTTP request to send.
     * @param callable $onToken Token callback.
     * @param callable|null $onComplete Completion callback.
     * @param callable|null $onError Error callback.
     */
    abstract protected function doStreamChat(
        HttpRequest $request,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void;

    /**
     * Inspects an HTTP response and throws the appropriate exception for errors.
     *
     * @param HttpResponse $response The HTTP response to check.
     *
     * @throws \WebFiori\Ai\Exception\AuthenticationException If status is 401 or 403.
     * @throws \WebFiori\Ai\Exception\RateLimitException If status is 429.
     * @throws \WebFiori\Ai\Exception\ProviderException If status indicates a server error.
     */
    abstract protected function handleErrorResponse(HttpResponse $response): void;
    use LoggerTrait;

    /**
     * Parses an HTTP response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return ChatResponse The parsed chat response.
     */
    abstract protected function parseChatResponse(HttpResponse $response): ChatResponse;

    /**
     * Parses an HTTP response into an EmbeddingResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return EmbeddingResponse The parsed embedding response.
     */
    abstract protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse;

    /**
     * Parses an HTTP response into an ImageResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return ImageResponse The parsed image response.
     */
    abstract protected function parseImageResponse(HttpResponse $response): ImageResponse;

    /**
     * Sends an HTTP request using the configured HTTP client.
     *
     * @param HttpRequest $request The request to send.
     *
     * @return HttpResponse The response from the server.
     *
     * @throws \WebFiori\Ai\Exception\HttpException If a transport error occurs.
     */
    protected function sendRequest(HttpRequest $request): HttpResponse {
        $this->logDebug('HTTP request', [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
        ]);

        $response = $this->httpClient->send($request);

        $this->logDebug('HTTP response', [
            'status_code' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * Validates provider configuration.
     *
     * Subclasses should override this to check for required options.
     *
     * @param array<string, mixed> $config The configuration to validate.
     *
     * @throws InvalidConfigException If configuration is invalid.
     */
    protected function validateConfig(array $config): void {
        // Default: no validation. Subclasses override.
    }
}
