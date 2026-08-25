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

use WebFiori\Ai\Audit\AuditTrait;
use WebFiori\Ai\Cache\CacheConfig;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Cache\CachedResponse;
use WebFiori\Ai\Cache\CacheInterface;
use WebFiori\Ai\Cache\CacheKeyGenerator;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Context\ContextWindowStrategyInterface;
use WebFiori\Ai\Context\TokenEstimator;
use WebFiori\Ai\CostEstimate;
use WebFiori\Ai\CostResult;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\ContextOverflowException;
use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
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
use WebFiori\Ai\MetricsTrait;
use WebFiori\Ai\ModelAliases;
use WebFiori\Ai\NullStatusEmitter;
use WebFiori\Ai\PricingConfig;
use WebFiori\Ai\RateLimitStatus;
use WebFiori\Ai\Redaction\RedactionConfig;
use WebFiori\Ai\Redaction\RedactionService;
use WebFiori\Ai\RetryConfig;
use WebFiori\Ai\Role;
use WebFiori\Ai\Status;
use WebFiori\Ai\StatusEmitterInterface;
use WebFiori\Ai\Temperature\ChatContext;
use WebFiori\Ai\Temperature\TemperatureStrategyInterface;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolInterface;
use WebFiori\Ai\Tool\ToolResponse;
use WebFiori\Ai\Tool\ToolResult;
use WebFiori\Ai\Usage;

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
     * Provider configuration options (array form for backward compatibility).
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Provider configuration object.
     *
     * @var ClientConfig
     */
    private ClientConfig $configObject;

    /**
     * Context window management strategy.
     *
     * @var ContextWindowStrategyInterface|null
     */
    private ?ContextWindowStrategyInterface $contextStrategy = null;

    /**
     * Temperature strategy for automatic temperature selection.
     *
     * @var TemperatureStrategyInterface|null
     */
    private ?TemperatureStrategyInterface $temperatureStrategy = null;

    /**
     * The HTTP client used for making API requests.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $httpClient;

    /**
     * Model alias registry.
     *
     * @var ModelAliases|null
     */
    private ?ModelAliases $modelAliases = null;

    /**
     * Pricing configuration for cost calculation.
     *
     * @var PricingConfig|null
     */
    private ?PricingConfig $pricing = null;

    /**
     * Status emitter for real-time progress tracking.
     *
     * @var StatusEmitterInterface
     */
    private StatusEmitterInterface $statusEmitter;

    /**
     * Token estimator for counting tokens.
     *
     * @var TokenEstimator
     */
    private TokenEstimator $tokenEstimator;

    /**
     * Creates a new provider instance.
     *
     * @param ClientConfig $config Provider configuration object.
     *
     * @throws InvalidConfigException If required configuration is missing.
     */
    public function __construct(ClientConfig $config) {
        $this->configObject = $config;
        $this->config = $config->toArray();
        $this->httpClient = new CurlHttpClient(
            $config->timeout,
            $config->connectTimeout
        );
        $this->cacheConfig = new CacheConfig(enabled: false);
        $this->cacheKeyGenerator = new CacheKeyGenerator();
        $this->tokenEstimator = new TokenEstimator();
        $this->statusEmitter = new NullStatusEmitter();
        $this->initAuditTrait();
        $this->validateConfig($this->config);
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
     * If a context window strategy is set, messages are automatically truncated
     * to fit within the configured token limit.
     *
     * @param Message[] $messages An array of messages forming the conversation.
     * @param array<string, mixed> $options Additional options (e.g., temperature,
     *        max_tokens, model override).
     *
     * @return ChatResponse The AI-generated response.
     *
     * @throws AuthenticationException If credentials are invalid.
     * @throws RateLimitException If the rate limit is exceeded.
     * @throws ProviderException If the provider returns an error.
     * @throws HttpException If a transport error occurs.
     * @throws ContextOverflowException If using NoTruncationStrategy
     *         and context exceeds the limit.
     */
    public function chat(array $messages, array $options = []): ChatResponse {
        $model = $options[ChatOption::MODEL] ?? $this->getConfig('model');

        // Resolve model alias if registry is set
        if ($this->modelAliases !== null && $model !== null) {
            $model = $this->modelAliases->resolve($model, $this->getName());
            $options[ChatOption::MODEL] = $model;
        }

        $startTime = microtime(true);
        $autoExecute = $options[ChatOption::AUTO_EXECUTE_TOOLS] ?? false;
        $temperature = $options[ChatOption::TEMPERATURE] ?? null;

        // Apply temperature strategy if set and temperature not explicitly provided
        if ($temperature === null && $this->temperatureStrategy !== null) {
            $chatContext = new ChatContext($messages, $options);
            $temperature = $this->temperatureStrategy->temperature($chatContext);
            $options[ChatOption::TEMPERATURE] = $temperature;
        }

        $tools = $options[ChatOption::TOOLS] ?? [];
        $requestId = $options[ChatOption::REQUEST_ID] ?? uniqid('req_', true);

        $this->statusEmitter->emit(Status::PREPARING, [
            'model' => $model,
            'message_count' => count($messages),
            'tool_count' => count($tools),
        ]);

        // Apply context window strategy if set
        if ($this->contextStrategy !== null) {
            $this->statusEmitter->emit(Status::TRUNCATING_CONTEXT, []);
        }
        $messages = $this->applyContextStrategy($messages, $tools);

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

                $this->emitMetric('cache.hit', array_merge(
                    $this->buildBaseMetricData($requestId, $this->getName(), $model),
                    ['key' => $cacheKey]
                ));

                $this->statusEmitter->emit(Status::CACHE_HIT, ['key' => $cacheKey]);

                return $cached->getData();
            }

            $this->emitMetric('cache.miss', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $model),
                ['key' => $cacheKey]
            ));

            $this->statusEmitter->emit(Status::CACHE_MISS, []);
        }

        $this->logInfo('Chat request started', [
            'provider' => $this->getName(),
            'model' => $model,
            'message_count' => count($messages),
        ]);

        $this->emitMetric('request.sent', array_merge(
            $this->buildBaseMetricData($requestId, $this->getName(), $model),
            ['endpoint' => 'chat', 'method' => 'POST']
        ));

        try {
            $this->statusEmitter->emit(Status::SENDING_REQUEST, [
                'model' => $model,
                'iteration' => 0,
            ]);

            $request = $this->buildChatRequest($messages, $options);
            $httpResponse = $this->sendRequest($request);
            $this->handleErrorResponse($httpResponse);
            $response = $this->parseChatResponse($httpResponse);

            $maxIterations = $options[ChatOption::MAX_TOOL_ITERATIONS] ?? 10;

            if ($autoExecute && count($tools) > 0) {
                $iteration = 0;
                $parallelTools = $options[ChatOption::PARALLEL_TOOL_EXECUTION] ?? true;
                $formattedCount = count($messages); // Track how many messages are already formatted

                while ($response->hasToolCalls() && $iteration < $maxIterations) {
                    $iteration++;
                    $messages[] = $response->getMessage();

                    $toolCalls = $response->getMessage()->getToolCalls();
                    $toolResults = $this->executeTools($tools, $toolCalls, $parallelTools);

                    foreach ($toolResults as $toolCallId => $result) {
                        $this->logDebug('Tool executed', [
                            'tool' => $result['name'],
                            'iteration' => $iteration,
                            'duration_ms' => $result['duration_ms'],
                        ]);

                        $messages[] = new Message(
                            Role::TOOL,
                            '',
                            [],
                            new ToolResult($toolCallId, $result['output'], $result['name'], $result['parts'])
                        );
                    }

                    // Build next request: pass all messages but hint provider about
                    // how many were already formatted so it can optimize
                    $newMessages = array_slice($messages, $formattedCount);
                    $request = $this->buildIncrementalChatRequest($request, $messages, $newMessages, $options);
                    $formattedCount = count($messages);

                    $this->statusEmitter->emit(Status::SENDING_REQUEST, [
                        'model' => $model,
                        'iteration' => $iteration,
                    ]);

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

            $this->emitMetric('request.completed', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $response->getModel()),
                [
                    'status_code' => $httpResponse->getStatusCode(),
                    'latency_ms' => $durationMs,
                    'prompt_tokens' => $response->getUsage()?->getPromptTokens(),
                    'completion_tokens' => $response->getUsage()?->getCompletionTokens(),
                    'total_tokens' => $response->getUsage()?->getTotalTokens(),
                ]
            ));

            // Attach request ID to response — preserve provider-assigned ID
            // (e.g., interaction_id from Google Interactions API) if present
            $response = new ChatResponse(
                $response->getMessage(),
                $response->getModel(),
                $response->getUsage(),
                $response->getFinishReason(),
                $response->getRequestId() ?? $requestId
            );

            // Calculate and attach cost if pricing is configured
            $cost = $this->calculateCost($response->getModel(), $response->getUsage());

            if ($cost !== null) {
                $response->setCost($cost);
            }

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

            // Emit audit entry
            $auditEntry = array_merge(
                $this->buildBaseAuditEntry(
                    $requestId,
                    'chat',
                    $this->getName(),
                    $response->getModel(),
                    $options['audit_context'] ?? []
                ),
                [
                    'status' => 'success',
                    'duration_ms' => $durationMs,
                    'tokens' => [
                        'prompt' => $response->getUsage()?->getPromptTokens(),
                        'completion' => $response->getUsage()?->getCompletionTokens(),
                        'total' => $response->getUsage()?->getTotalTokens(),
                    ],
                    'error' => null,
                ]
            );

            if ($this->auditConfig->isIncludeMessages()) {
                $auditEntry['messages'] = $this->serializeMessagesForAudit($messages);
            }

            if ($this->auditConfig->isIncludeResponse()) {
                $auditEntry['response'] = $response->getMessage()->getContent();
            }

            $this->emitAudit($auditEntry);

            $this->statusEmitter->emit(Status::COMPLETED, [
                'model' => $response->getModel(),
                'duration_ms' => $durationMs,
                'total_tokens' => $response->getUsage()?->getTotalTokens(),
                'cost' => $response->getCost()?->getTotal(),
                'currency' => $response->getCost()?->getCurrency(),
            ]);

            return $response;
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->emitMetric('request.failed', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $model),
                [
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'latency_ms' => $durationMs,
                ]
            ));

            $this->emitAudit(array_merge(
                $this->buildBaseAuditEntry($requestId, 'chat', $this->getName(), $model, $options['audit_context'] ?? []),
                [
                    'status' => 'error',
                    'duration_ms' => $durationMs,
                    'tokens' => ['prompt' => null, 'completion' => null, 'total' => null],
                    'error' => ['type' => get_class($e), 'message' => $e->getMessage()],
                ]
            ));

            $this->statusEmitter->emit(Status::ERROR, [
                'error' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            throw $e;
        }
    }

    /**
     * Estimates the token count for messages and optionally tools.
     *
     * Uses character-ratio estimation (~4 characters ≈ 1 token).
     * Accuracy is typically within 5-10% of actual token count.
     *
     * ```php
     * $tokens = $provider->countTokens($messages);
     * $tokens = $provider->countTokens($messages, $tools);
     * ```
     *
     * @param Message[] $messages The messages to count.
     * @param ToolInterface[] $tools Optional tools to include in count.
     *
     * @return int Estimated token count.
     */
    public function countTokens(array $messages, array $tools = []): int {
        return $this->tokenEstimator->count($messages, $tools);
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
     * @throws UnsupportedFeatureException If not supported.
     * @throws ProviderException If the provider returns an error.
     */
    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        $model = $options[ChatOption::MODEL] ?? $this->getConfig('embedding_model', $this->getConfig('model'));
        $requestId = $options[ChatOption::REQUEST_ID] ?? uniqid('req_', true);
        $startTime = microtime(true);

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

                $this->emitMetric('cache.hit', array_merge(
                    $this->buildBaseMetricData($requestId, $this->getName(), $model),
                    ['key' => $cacheKey]
                ));

                return $cached->getData();
            }

            $this->emitMetric('cache.miss', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $model),
                ['key' => $cacheKey]
            ));
        }

        $this->emitMetric('request.sent', array_merge(
            $this->buildBaseMetricData($requestId, $this->getName(), $model),
            ['endpoint' => 'embeddings', 'method' => 'POST']
        ));

        try {
            $request = $this->buildEmbedRequest($input, $options);
            $httpResponse = $this->sendRequest($request);
            $this->handleErrorResponse($httpResponse);

            $response = $this->parseEmbedResponse($httpResponse);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->emitMetric('request.completed', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $response->getModel()),
                [
                    'status_code' => $httpResponse->getStatusCode(),
                    'latency_ms' => $durationMs,
                    'prompt_tokens' => $response->getUsage()?->getPromptTokens(),
                    'completion_tokens' => null,
                    'total_tokens' => $response->getUsage()?->getPromptTokens(),
                ]
            ));

            // Attach request ID to response
            $response = new EmbeddingResponse(
                $response->getVectors(),
                $response->getModel(),
                $response->getUsage(),
                $requestId
            );

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

            $this->emitAudit(array_merge(
                $this->buildBaseAuditEntry($requestId, 'embed', $this->getName(), $response->getModel(), $options['audit_context'] ?? []),
                [
                    'status' => 'success',
                    'duration_ms' => $durationMs,
                    'tokens' => [
                        'prompt' => $response->getUsage()?->getPromptTokens(),
                        'completion' => null,
                        'total' => $response->getUsage()?->getPromptTokens(),
                    ],
                    'error' => null,
                ]
            ));

            return $response;
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->emitMetric('request.failed', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $model),
                [
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'latency_ms' => $durationMs,
                ]
            ));

            $this->emitAudit(array_merge(
                $this->buildBaseAuditEntry($requestId, 'embed', $this->getName(), $model, $options['audit_context'] ?? []),
                [
                    'status' => 'error',
                    'duration_ms' => $durationMs,
                    'tokens' => ['prompt' => null, 'completion' => null, 'total' => null],
                    'error' => ['type' => get_class($e), 'message' => $e->getMessage()],
                ]
            ));

            throw $e;
        }
    }

    /**
     * Enables HTTP connection reuse for improved performance.
     *
     * When enabled, TCP connections are kept alive and reused across multiple
     * requests to the same host. This significantly improves performance in
     * scenarios with sequential requests, such as tool calling loops.
     *
     * Connection reuse avoids the overhead of:
     * - TCP handshake (~1 RTT)
     * - TLS handshake (~2 RTT)
     * - TCP slow start
     *
     * ```php
     * $client->enableConnectionReuse();
     * $response = $client->chat($messages, [
     *     'tools' => $tools,
     *     'auto_execute_tools' => true,
     * ]);
     * ```
     *
     * @param bool $enable True to enable connection reuse, false to disable.
     *
     * @return self Returns the instance for method chaining.
     */
    public function enableConnectionReuse(bool $enable = true): self {
        // Get the innermost CurlHttpClient
        $client = $this->httpClient;

        while ($client instanceof RateLimitAwareHttpClient || $client instanceof RetryableHttpClient) {
            $client = $client->getInner();
        }

        if ($client instanceof CurlHttpClient) {
            $client->enableConnectionReuse($enable);
        }

        return $this;
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
     * Estimates the cost of a chat request before sending it.
     *
     * Returns a cost range (min to max) based on the prompt token count
     * and the configured max_tokens. Requires a PricingConfig to be set.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Options (may contain 'model', 'max_tokens').
     *
     * @return CostEstimate|null The estimate, or null if pricing is not configured
     *         or no pricing is defined for the resolved model.
     */
    public function estimateCost(array $messages, array $options = []): ?CostEstimate {
        if ($this->pricing === null) {
            return null;
        }

        $model = $options[ChatOption::MODEL] ?? $this->getConfig('model', '');

        // Resolve alias
        if ($this->modelAliases !== null && $model !== '') {
            $model = $this->modelAliases->resolve($model, $this->getName());
        }

        if (!$this->pricing->hasModel($model)) {
            return null;
        }

        $inputPrice = $this->pricing->getInputPrice($model);
        $outputPrice = $this->pricing->getOutputPrice($model);
        $maxTokens = $options[ChatOption::MAX_TOKENS] ?? 1024;

        // Estimate prompt tokens using the token estimator
        $promptTokens = $this->tokenEstimator->countMessages($messages);

        // Min cost: prompt only (assume 1 token response)
        $minCost = ($promptTokens / 1_000_000) * $inputPrice
                 + (1 / 1_000_000) * $outputPrice;

        // Max cost: prompt + max_tokens response
        $maxCost = ($promptTokens / 1_000_000) * $inputPrice
                 + ($maxTokens / 1_000_000) * $outputPrice;

        return new CostEstimate(
            $promptTokens,
            $maxTokens,
            $minCost,
            $maxCost,
            $model,
            $this->pricing->getCurrency()
        );
    }

    /**
     * Generates an image from a text prompt.
     *
     * @param ImageRequest $request The image generation request.
     *
     * @return ImageResponse The response containing generated image(s).
     *
     * @throws UnsupportedFeatureException If not supported.
     * @throws ProviderException If the provider returns an error.
     */
    public function generateImage(ImageRequest $request): ImageResponse {
        $requestId = uniqid('req_', true);
        $startTime = microtime(true);
        $model = $this->getConfig('image_model', $this->getConfig('model'));

        $this->emitMetric('request.sent', array_merge(
            $this->buildBaseMetricData($requestId, $this->getName(), $model),
            ['endpoint' => 'images', 'method' => 'POST']
        ));

        try {
            $httpRequest = $this->buildImageRequest($request);
            $httpResponse = $this->sendRequest($httpRequest);
            $this->handleErrorResponse($httpResponse);

            $response = $this->parseImageResponse($httpResponse);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->emitMetric('request.completed', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $response->getModel()),
                [
                    'status_code' => $httpResponse->getStatusCode(),
                    'latency_ms' => $durationMs,
                    'prompt_tokens' => null,
                    'completion_tokens' => null,
                    'total_tokens' => null,
                ]
            ));

            $imageResponse = new ImageResponse($response->getImages(), $response->getModel(), $requestId);

            $this->emitAudit(array_merge(
                $this->buildBaseAuditEntry($requestId, 'generateImage', $this->getName(), $response->getModel()),
                [
                    'status' => 'success',
                    'duration_ms' => $durationMs,
                    'tokens' => ['prompt' => null, 'completion' => null, 'total' => null],
                    'error' => null,
                ]
            ));

            return $imageResponse;
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->emitMetric('request.failed', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $model),
                [
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'latency_ms' => $durationMs,
                ]
            ));

            $this->emitAudit(array_merge(
                $this->buildBaseAuditEntry($requestId, 'generateImage', $this->getName(), $model),
                [
                    'status' => 'error',
                    'duration_ms' => $durationMs,
                    'tokens' => ['prompt' => null, 'completion' => null, 'total' => null],
                    'error' => ['type' => get_class($e), 'message' => $e->getMessage()],
                ]
            ));

            throw $e;
        }
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
     * Returns the current context window strategy.
     *
     * @return ContextWindowStrategyInterface|null The strategy, or null if not set.
     */
    public function getContextWindowStrategy(): ?ContextWindowStrategyInterface {
        return $this->contextStrategy;
    }

    /**
     * Returns the temperature strategy used for automatic temperature selection.
     *
     * @return TemperatureStrategyInterface|null The strategy, or null if none set.
     */
    public function getTemperatureStrategy(): ?TemperatureStrategyInterface {
        return $this->temperatureStrategy;
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
     * Returns the model alias registry.
     *
     * @return ModelAliases|null The alias registry, or null if not set.
     */
    public function getModelAliases(): ?ModelAliases {
        return $this->modelAliases;
    }

    /**
     * Returns the pricing configuration.
     *
     * @return PricingConfig|null The pricing config, or null if not set.
     */
    public function getPricing(): ?PricingConfig {
        return $this->pricing;
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
     * Returns the estimated remaining tokens available for completion.
     *
     * Requires a context window strategy to be set.
     *
     * @param Message[] $messages The messages to count.
     * @param ToolInterface[] $tools Optional tools to include in count.
     *
     * @return int|null Remaining tokens, or null if no strategy is set.
     */
    public function getRemainingTokens(array $messages, array $tools = []): ?int {
        if ($this->contextStrategy === null) {
            return null;
        }

        $used = $this->tokenEstimator->count($messages, $tools);
        $reserved = $this->contextStrategy->getReservedTokens();

        // Get max tokens from strategy if it has a getter
        if (method_exists($this->contextStrategy, 'getMaxTokens')) {
            $max = $this->contextStrategy->getMaxTokens();

            return max(0, $max - $reserved - $used);
        }

        return null;
    }

    /**
     * Returns the current status emitter.
     *
     * @return StatusEmitterInterface The current emitter.
     */
    public function getStatusEmitter(): StatusEmitterInterface {
        return $this->statusEmitter;
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
     * Sets the context window management strategy.
     *
     * When set, messages are automatically truncated to fit within the
     * configured token limit before being sent to the AI provider.
     *
     * ```php
     * $provider->setContextWindowStrategy(new SlidingWindowStrategy(
     *     maxTokens: 128000,
     *     reserveForCompletion: 4096,
     *     preserveSystemMessage: true,
     * ));
     * ```
     *
     * @param ContextWindowStrategyInterface|null $strategy The strategy, or null to disable.
     */
    public function setContextWindowStrategy(?ContextWindowStrategyInterface $strategy): void {
        $this->contextStrategy = $strategy;
    }

    /**
     * Sets the temperature strategy for automatic temperature selection.
     *
     * When set, the strategy determines temperature automatically for chat
     * requests that do not explicitly specify a temperature in options.
     *
     * @param TemperatureStrategyInterface|null $strategy The strategy to use, or null to disable.
     */
    public function setTemperatureStrategy(?TemperatureStrategyInterface $strategy): void {
        $this->temperatureStrategy = $strategy;
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
     * Sets the model alias registry for this provider.
     *
     * When set, logical alias names (e.g., 'fast', 'smart') passed via
     * the 'model' option are resolved to provider-specific model IDs
     * before the request is sent.
     *
     * @param ModelAliases|null $aliases The alias registry,
     *        or null to disable alias resolution.
     */
    public function setModelAliases(?ModelAliases $aliases): void {
        $this->modelAliases = $aliases;
    }

    /**
     * Sets the pricing configuration for cost calculation.
     *
     * When set, each ChatResponse will include a CostResult calculated from
     * the actual token usage and the configured prices.
     *
     * @param PricingConfig|null $pricing The pricing config,
     *        or null to disable cost calculation.
     */
    public function setPricing(?PricingConfig $pricing): void {
        $this->pricing = $pricing;
    }

    /**
     * Configures PII redaction for logs and metrics.
     *
     * When set, sensitive data is redacted before reaching log and metrics
     * callbacks. API keys and Bearer tokens are always redacted regardless
     * of configuration.
     *
     * ```php
     * $provider->setRedactionConfig(new RedactionConfig(
     *     redactRequestBodies: true,
     *     redactResponseBodies: false,
     *     disabledRules: ['phone'],
     *     customRules: [
     *         new RedactionRule('ssn', '/\b\d{3}-\d{2}-\d{4}\b/', '[SSN]'),
     *     ],
     * ));
     * ```
     *
     * @param RedactionConfig|null $config The redaction config, or null to disable.
     */
    public function setRedactionConfig(?RedactionConfig $config): void {
        $service = $config !== null ? new RedactionService($config) : null;
        $this->setLogRedactionService($service);
        $this->setMetricsRedactionService($service);
        $this->setAuditRedactionService($service);
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
     * Sets the status emitter for real-time progress tracking.
     *
     * The emitter receives events during chat() calls such as tool executions,
     * cache hits, and request lifecycle events. Use this to show progress
     * indicators in your frontend via SSE, WebSocket, or logging.
     *
     * ```php
     * // SSE streaming
     * $client->setStatusEmitter(new SSEStatusEmitter());
     *
     * // Custom callback
     * $client->setStatusEmitter(new CallbackStatusEmitter(function($status, $ctx) {
     *     echo "[{$status}] " . json_encode($ctx) . "\n";
     * }));
     * ```
     *
     * @param StatusEmitterInterface $emitter The emitter to use.
     *
     * @return self Returns the instance for method chaining.
     */
    public function setStatusEmitter(StatusEmitterInterface $emitter): self {
        $this->statusEmitter = $emitter;

        return $this;
    }

    /**
     * Sends a chat completion request with streaming response.
     *
     * If a context window strategy is set, messages are automatically truncated
     * to fit within the configured token limit.
     *
     * @param Message[] $messages An array of messages forming the conversation.
     * @param callable $onToken Callback invoked for each token received.
     *        Signature: function(string $token): void
     * @param callable|null $onComplete Optional callback when streaming completes.
     *        Signature: function(ChatResponse $response): void
     * @param callable|null $onError Optional callback on stream error.
     *        Signature: function(StreamingException $e): void
     * @param array<string, mixed> $options Additional provider-specific options.
     *
     * @throws AuthenticationException If credentials are invalid.
     * @throws RateLimitException If the rate limit is exceeded.
     * @throws ProviderException If the provider returns an error.
     * @throws ContextOverflowException If using NoTruncationStrategy
     *         and context exceeds the limit.
     */
    public function streamChat(
        array $messages,
        callable $onToken,
        ?callable $onComplete = null,
        ?callable $onError = null,
        array $options = []
    ): void {
        $model = $options[ChatOption::MODEL] ?? $this->getConfig('model');

        // Resolve model alias if registry is set
        if ($this->modelAliases !== null && $model !== null) {
            $model = $this->modelAliases->resolve($model, $this->getName());
            $options[ChatOption::MODEL] = $model;
        }

        $tools = $options[ChatOption::TOOLS] ?? [];
        $requestId = $options[ChatOption::REQUEST_ID] ?? uniqid('req_', true);
        $startTime = microtime(true);
        $tokenCount = 0;

        // Apply context window strategy if set
        $messages = $this->applyContextStrategy($messages, $tools);

        $this->logInfo('Stream chat request started', [
            'provider' => $this->getName(),
            'model' => $model,
            'message_count' => count($messages),
        ]);

        $this->emitMetric('stream.started', $this->buildBaseMetricData($requestId, $this->getName(), $model));

        // Wrap onToken to count tokens
        $wrappedOnToken = function (string $token) use ($onToken, &$tokenCount): void
        {
            $tokenCount++;
            $onToken($token);
        };

        // Wrap onComplete to emit stream.completed + audit entry
        $wrappedOnComplete = function ($response) use ($onComplete, $requestId, $startTime, &$tokenCount, $messages, $options): void
        {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->emitMetric('stream.completed', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), $response?->getModel()),
                ['duration_ms' => $durationMs, 'tokens' => $tokenCount]
            ));

            $auditEntry = array_merge(
                $this->buildBaseAuditEntry(
                    $requestId,
                    'streamChat',
                    $this->getName(),
                    $response?->getModel(),
                    $options['audit_context'] ?? []
                ),
                [
                    'status' => 'success',
                    'duration_ms' => $durationMs,
                    'tokens' => ['prompt' => null, 'completion' => $tokenCount, 'total' => $tokenCount],
                    'error' => null,
                ]
            );

            if ($this->auditConfig->isIncludeMessages()) {
                $auditEntry['messages'] = $this->serializeMessagesForAudit($messages);
            }

            if ($this->auditConfig->isIncludeResponse() && $response !== null) {
                $auditEntry['response'] = $response->getMessage()->getContent();
            }

            $this->emitAudit($auditEntry);

            if ($onComplete !== null) {
                $onComplete($response);
            }
        };

        // Wrap onError to emit stream.error + audit entry
        $wrappedOnError = function ($e) use ($onError, $requestId, $startTime, $model, $options): void
        {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->emitMetric('stream.error', array_merge(
                $this->buildBaseMetricData($requestId, $this->getName(), null),
                ['error' => $e->getMessage()]
            ));

            $this->emitAudit(array_merge(
                $this->buildBaseAuditEntry($requestId, 'streamChat', $this->getName(), $model, $options['audit_context'] ?? []),
                [
                    'status' => 'error',
                    'duration_ms' => $durationMs,
                    'tokens' => ['prompt' => null, 'completion' => null, 'total' => null],
                    'error' => ['type' => get_class($e), 'message' => $e->getMessage()],
                ]
            ));

            if ($onError !== null) {
                $onError($e);
            }
        };

        $request = $this->buildStreamChatRequest($messages, $options);
        $this->doStreamChat($request, $wrappedOnToken, $wrappedOnComplete, $wrappedOnError);
    }

    /**
     * Applies the context window strategy to truncate messages if needed.
     *
     * @param Message[] $messages The original messages.
     * @param ToolInterface[] $tools The tools being used.
     *
     * @return Message[] The potentially truncated messages.
     */
    private function applyContextStrategy(array $messages, array $tools): array {
        if ($this->contextStrategy === null) {
            return $messages;
        }

        $originalCount = count($messages);
        $originalTokens = $this->tokenEstimator->count($messages, $tools);

        $truncated = $this->contextStrategy->truncate($messages, 0, $tools);

        $newCount = count($truncated);

        if ($newCount < $originalCount) {
            $removedCount = $originalCount - $newCount;
            $newTokens = $this->tokenEstimator->count($truncated, $tools);
            $removedTokens = $originalTokens - $newTokens;

            $this->logWarning('Context window truncation applied', [
                'original_messages' => $originalCount,
                'truncated_messages' => $newCount,
                'removed_messages' => $removedCount,
                'original_tokens' => $originalTokens,
                'new_tokens' => $newTokens,
                'removed_tokens' => $removedTokens,
            ]);
        }

        return $truncated;
    }

    /**
     * Calculates the actual cost of a request from real token usage.
     *
     * @param string $model The model that generated the response.
     * @param Usage|null $usage The token usage data.
     *
     * @return CostResult|null The cost, or null if pricing is not configured.
     */
    private function calculateCost(string $model, ?Usage $usage): ?CostResult {
        if ($this->pricing === null || $usage === null) {
            return null;
        }

        if (!$this->pricing->hasModel($model)) {
            return null;
        }

        $inputPrice = $this->pricing->getInputPrice($model);
        $outputPrice = $this->pricing->getOutputPrice($model);

        $inputCost = ($usage->getPromptTokens() / 1_000_000) * $inputPrice;
        $outputCost = ($usage->getCompletionTokens() / 1_000_000) * $outputPrice;

        return new CostResult(
            $inputCost,
            $outputCost,
            $model,
            $this->pricing->getCurrency()
        );
    }

    /**
     * Executes multiple tool calls.
     *
     * Currently executes tools sequentially. The `parallel` option is reserved
     * for future implementation with async I/O support.
     *
     * @param ToolInterface[] $tools The available tools.
     * @param ToolCall[] $toolCalls The tool calls to execute.
     * @param bool $parallel Reserved for future parallel execution support.
     *
     * @return array<string, array{name: string, output: string, duration_ms: int}> Results keyed by tool call ID.
     */
    private function executeTools(array $tools, array $toolCalls, bool $parallel): array {
        $results = [];
        $overallStart = microtime(true);

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($tools, $toolCall->getName());

            $this->statusEmitter->emit(Status::TOOL_CALLING, [
                'tool' => $toolCall->getName(),
                'arguments' => $toolCall->getArguments(),
            ]);

            $this->statusEmitter->emit(Status::TOOL_EXECUTING, [
                'tool' => $toolCall->getName(),
            ]);

            $start = microtime(true);
            $output = $tool !== null ? $tool->execute($toolCall->getArguments()) : '';
            $duration = (int) ((microtime(true) - $start) * 1000);

            $this->statusEmitter->emit(Status::TOOL_COMPLETED, [
                'tool' => $toolCall->getName(),
                'duration_ms' => $duration,
            ]);

            // Capture ToolResponse parts for multimodal results
            $parts = [];

            if ($output instanceof ToolResponse && $output->isMultimodal()) {
                $parts = $output->getParts();
            }

            $results[$toolCall->getId()] = [
                'name' => $toolCall->getName(),
                'output' => (string) $output,
                'parts' => $parts,
                'duration_ms' => $duration,
            ];
        }

        // Log total tool execution time if multiple tools
        if (count($toolCalls) > 1) {
            $totalDuration = (int) ((microtime(true) - $overallStart) * 1000);
            $this->logDebug('Multiple tools executed', [
                'tool_count' => count($toolCalls),
                'total_duration_ms' => $totalDuration,
            ]);
        }

        return $results;
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
    use AuditTrait;

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
     * Builds an incremental chat request by appending new messages to an existing request.
     *
     * Default implementation rebuilds from scratch using all messages.
     * Providers can override for optimized incremental building that avoids
     * re-formatting existing messages using only newMessages.
     *
     * @param HttpRequest $previousRequest The previous HTTP request.
     * @param Message[] $allMessages All messages in the conversation.
     * @param Message[] $newMessages Only the new messages added since last request.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The updated HTTP request.
     */
    protected function buildIncrementalChatRequest(
        HttpRequest $previousRequest,
        array $allMessages,
        array $newMessages,
        array $options
    ): HttpRequest {
        // Default: full rebuild using all messages
        return $this->buildChatRequest($allMessages, $options);
    }

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
     * Formats new messages for incremental request building.
     *
     * Override in providers to format messages in their specific format.
     *
     * @param Message[] $messages The new messages to format.
     *
     * @return array<int, array<string, mixed>> Formatted messages.
     */
    protected function formatMessagesForIncremental(array $messages): array {
        return [];
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
    abstract protected function handleErrorResponse(HttpResponse $response): void;
    use LoggerTrait;
    use MetricsTrait;

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
     * @throws HttpException If a transport error occurs.
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
