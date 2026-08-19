<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Fallback;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * A provider that wraps multiple providers with automatic failover.
 *
 * FallbackProvider implements ProviderInterface so it can be used anywhere
 * a single provider is expected. It routes requests to one of its underlying
 * providers based on the configured strategy and handles failures by
 * automatically falling back to the next available provider.
 *
 * Example usage:
 * ```php
 * $provider = new FallbackProvider([
 *     new OpenAIClient($openaiConfig),
 *     new AnthropicClient($anthropicConfig),
 *     new GoogleClient($googleConfig),
 * ], new FallbackConfig(
 *     strategy: FallbackStrategy::SEQUENTIAL,
 *     maxAttempts: 3,
 * ));
 *
 * // Use like any other provider
 * $response = $provider->chat($messages);
 * ```
 *
 * @author Ibrahim
 */
class FallbackProvider implements ProviderInterface {
    /**
     * Circuit breakers for each provider.
     *
     * @var array<int, CircuitBreaker>
     */
    private array $circuitBreakers = [];

    /**
     * The fallback configuration.
     *
     * @var FallbackConfig
     */
    private FallbackConfig $config;

    /**
     * Index of the last provider used (for round-robin).
     *
     * @var int
     */
    private int $lastProviderIndex = -1;

    /**
     * The name of the provider that served the last request.
     *
     * @var string|null
     */
    private ?string $lastUsedProvider = null;

    /**
     * Logging callback.
     *
     * @var callable|null
     */
    private $logCallback;

    /**
     * The list of underlying providers.
     *
     * @var ProviderInterface[]
     */
    private array $providers;

    /**
     * Creates a new FallbackProvider instance.
     *
     * @param ProviderInterface[] $providers The list of providers to use.
     *        Must contain at least one provider.
     * @param FallbackConfig|null $config The fallback configuration.
     *        Default configuration is used if null.
     *
     * @throws \InvalidArgumentException If no providers are given.
     */
    public function __construct(array $providers, ?FallbackConfig $config = null) {
        if (count($providers) === 0) {
            throw new \InvalidArgumentException('FallbackProvider requires at least one provider.');
        }

        $this->providers = array_values($providers);
        $this->config = $config ?? new FallbackConfig();

        // Initialize circuit breakers if configured
        $cbConfig = $this->config->getCircuitBreaker();

        if ($cbConfig !== null) {
            foreach ($this->providers as $index => $provider) {
                $this->circuitBreakers[$index] = new CircuitBreaker($cbConfig);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $messages, array $options = []): ChatResponse {
        return $this->executeWithFallback(
            fn (ProviderInterface $provider) => $provider->chat($messages, $options)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        return $this->executeWithFallback(
            fn (ProviderInterface $provider) => $provider->embed($input, $options)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generateImage(ImageRequest $request): ImageResponse {
        return $this->executeWithFallback(
            fn (ProviderInterface $provider) => $provider->generateImage($request)
        );
    }

    /**
     * Returns the circuit breaker for a specific provider.
     *
     * @param int $index The provider index.
     *
     * @return CircuitBreaker|null The circuit breaker, or null if not configured.
     */
    public function getCircuitBreaker(int $index): ?CircuitBreaker {
        return $this->circuitBreakers[$index] ?? null;
    }

    /**
     * Returns all circuit breakers.
     *
     * @return array<int, CircuitBreaker> Map of provider index to circuit breaker.
     */
    public function getCircuitBreakers(): array {
        return $this->circuitBreakers;
    }

    /**
     * Returns the fallback configuration.
     *
     * @return FallbackConfig The configuration.
     */
    public function getConfig(): FallbackConfig {
        return $this->config;
    }

    /**
     * Returns the name of the provider that served the last request.
     *
     * Useful for metrics and logging to know which provider was actually used.
     *
     * @return string|null The provider name, or null if no request has been made.
     */
    public function getLastUsedProvider(): ?string {
        return $this->lastUsedProvider;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'fallback';
    }

    /**
     * Returns the underlying provider at the given index.
     *
     * @param int $index The provider index.
     *
     * @return ProviderInterface|null The provider, or null if index is invalid.
     */
    public function getProvider(int $index): ?ProviderInterface {
        return $this->providers[$index] ?? null;
    }

    /**
     * Returns the number of providers.
     *
     * @return int The provider count.
     */
    public function getProviderCount(): int {
        return count($this->providers);
    }

    /**
     * Returns all underlying providers.
     *
     * @return ProviderInterface[] The list of providers.
     */
    public function getProviders(): array {
        return $this->providers;
    }

    /**
     * Performs a health check on all providers.
     *
     * Returns a combined result. The fallback provider is considered healthy
     * if at least one underlying provider is available.
     *
     * @param int $timeout Timeout in seconds for each provider's check.
     *
     * @return HealthCheckResult The combined health check result.
     */
    public function healthCheck(int $timeout = 5): HealthCheckResult {
        $startTime = hrtime(true);
        $availableProviders = [];
        $errors = [];

        foreach ($this->providers as $index => $provider) {
            // Skip providers with open circuits
            if (isset($this->circuitBreakers[$index])) {
                if (!$this->circuitBreakers[$index]->allowRequest()) {
                    $errors[] = sprintf('%s: circuit open', $provider->getName());

                    continue;
                }
            }

            $result = $provider->healthCheck($timeout);

            if ($result->isAvailable()) {
                $availableProviders[] = $provider->getName();
            } else {
                $errors[] = sprintf('%s: %s', $provider->getName(), $result->getError() ?? 'unavailable');
            }
        }

        $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        if (count($availableProviders) > 0) {
            return new HealthCheckResult(
                true,
                $elapsedMs,
                'fallback_health_check',
                null
            );
        }

        return new HealthCheckResult(
            false,
            $elapsedMs,
            'fallback_health_check',
            'All providers unavailable: ' . implode('; ', $errors)
        );
    }

    /**
     * Resets all circuit breakers to closed state.
     */
    public function resetCircuitBreakers(): void {
        foreach ($this->circuitBreakers as $cb) {
            $cb->reset();
        }
    }

    /**
     * {@inheritdoc}
     *
     * Sets the HTTP client on all underlying providers.
     */
    public function setHttpClient(HttpClientInterface $client): void {
        foreach ($this->providers as $provider) {
            $provider->setHttpClient($client);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Sets the log callback on this provider and all underlying providers.
     */
    public function setLogCallback(?callable $callback): void {
        $this->logCallback = $callback;

        foreach ($this->providers as $provider) {
            $provider->setLogCallback($callback);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function streamChat(
        array $messages,
        callable $onToken,
        ?callable $onComplete = null,
        ?callable $onError = null,
        array $options = []
    ): void {
        $this->executeWithFallback(
            function (ProviderInterface $provider) use ($messages, $onToken, $onComplete, $onError, $options) {
                $provider->streamChat($messages, $onToken, $onComplete, $onError, $options);

                // Return a dummy value since streamChat is void
                return true;
            }
        );
    }

    /**
     * Executes an operation with fallback support.
     *
     * @param callable $operation The operation to execute, receives a provider.
     *
     * @return mixed The result of the operation.
     *
     * @throws \Throwable The last exception if all providers fail.
     */
    private function executeWithFallback(callable $operation): mixed {
        $providerOrder = $this->getProviderOrder();
        $attempts = 0;
        $maxAttempts = min($this->config->getMaxAttempts(), count($providerOrder));
        $lastException = null;

        foreach ($providerOrder as $index) {
            if ($attempts >= $maxAttempts) {
                break;
            }

            // Check circuit breaker
            if (isset($this->circuitBreakers[$index])) {
                if (!$this->circuitBreakers[$index]->allowRequest()) {
                    $this->log('debug', 'Skipping provider due to open circuit', [
                        'provider' => $this->providers[$index]->getName(),
                        'circuit_state' => $this->circuitBreakers[$index]->getState()->value,
                    ]);

                    continue;
                }
            }

            $provider = $this->providers[$index];
            $providerName = $provider->getName();
            $startTime = hrtime(true);
            $attempts++;

            try {
                $this->log('debug', 'Attempting request with provider', [
                    'provider' => $providerName,
                    'attempt' => $attempts,
                ]);

                $result = $operation($provider);

                // Record success
                $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $this->recordSuccess($index);
                $this->lastUsedProvider = $providerName;
                $this->lastProviderIndex = $index;
                $this->emitMetrics($providerName, true, $latencyMs, null);

                $this->log('debug', 'Request succeeded', [
                    'provider' => $providerName,
                    'latency_ms' => $latencyMs,
                ]);

                return $result;
            } catch (\Throwable $e) {
                $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $lastException = $e;
                $this->recordFailure($index);
                $this->emitMetrics($providerName, false, $latencyMs, $e->getMessage());

                $this->log('warning', 'Provider request failed', [
                    'provider' => $providerName,
                    'error' => $e->getMessage(),
                    'attempt' => $attempts,
                    'latency_ms' => $latencyMs,
                ]);

                // Check if we should failover for this exception type
                if (!$this->config->shouldFailover($e)) {
                    $this->log('debug', 'Exception does not trigger failover, rethrowing', [
                        'exception_class' => get_class($e),
                    ]);

                    throw $e;
                }
            }
        }

        // All providers failed
        $this->log('error', 'All providers failed', [
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'last_error' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? new \RuntimeException('All providers failed');
    }

    /**
     * Emits metrics via the configured callback.
     *
     * @param string $providerName The provider name.
     * @param bool $success Whether the request succeeded.
     * @param int $latencyMs Latency in milliseconds.
     * @param string|null $error Error message, if any.
     */
    private function emitMetrics(string $providerName, bool $success, int $latencyMs, ?string $error): void {
        $callback = $this->config->getMetricsCallback();

        if ($callback !== null) {
            $callback($providerName, $success, $latencyMs, $error);
        }
    }

    /**
     * Returns the order in which providers should be tried.
     *
     * @return int[] Array of provider indices.
     */
    private function getProviderOrder(): array {
        $count = count($this->providers);

        switch ($this->config->getStrategy()) {
            case FallbackStrategy::SEQUENTIAL:
                return range(0, $count - 1);

            case FallbackStrategy::ROUND_ROBIN:
                return $this->getRoundRobinOrder($count);

            case FallbackStrategy::WEIGHTED:
                return $this->getWeightedOrder($count);

            default:
                return range(0, $count - 1);
        }
    }

    /**
     * Returns provider order for round-robin strategy.
     *
     * @param int $count Number of providers.
     *
     * @return int[] Provider indices starting from the next one.
     */
    private function getRoundRobinOrder(int $count): array {
        $startIndex = ($this->lastProviderIndex + 1) % $count;
        $order = [];

        for ($i = 0; $i < $count; $i++) {
            $order[] = ($startIndex + $i) % $count;
        }

        return $order;
    }

    /**
     * Returns provider order for weighted strategy.
     *
     * Selects the first provider based on weights, then falls back
     * to other providers in descending weight order.
     *
     * @param int $count Number of providers.
     *
     * @return int[] Provider indices ordered by weighted selection.
     */
    private function getWeightedOrder(int $count): array {
        // Build array of [index => weight]
        $weights = [];
        $totalWeight = 0;

        for ($i = 0; $i < $count; $i++) {
            $weight = $this->config->getWeight($i);
            $weights[$i] = $weight;
            $totalWeight += $weight;
        }

        // Select first provider based on weighted random
        $random = mt_rand(1, $totalWeight);
        $cumulative = 0;
        $selectedIndex = 0;

        foreach ($weights as $index => $weight) {
            $cumulative += $weight;

            if ($random <= $cumulative) {
                $selectedIndex = $index;

                break;
            }
        }

        // Build order: selected first, then others by descending weight
        $order = [$selectedIndex];
        arsort($weights);

        foreach (array_keys($weights) as $index) {
            if ($index !== $selectedIndex) {
                $order[] = $index;
            }
        }

        return $order;
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
            ($this->logCallback)($level, "[FallbackProvider] $message", $context);
        }
    }

    /**
     * Records a failure for a provider's circuit breaker.
     *
     * @param int $index The provider index.
     */
    private function recordFailure(int $index): void {
        if (isset($this->circuitBreakers[$index])) {
            $this->circuitBreakers[$index]->recordFailure();
        }
    }

    /**
     * Records a success for a provider's circuit breaker.
     *
     * @param int $index The provider index.
     */
    private function recordSuccess(int $index): void {
        if (isset($this->circuitBreakers[$index])) {
            $this->circuitBreakers[$index]->recordSuccess();
        }
    }
}
