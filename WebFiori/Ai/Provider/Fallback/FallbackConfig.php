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

use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;

/**
 * Configuration for the FallbackProvider.
 *
 * Defines the fallback strategy, which exceptions trigger failover,
 * maximum attempts, and optional circuit breaker configuration.
 *
 * @author Ibrahim
 */
class FallbackConfig {
    /**
     * Circuit breaker configuration.
     *
     * @var CircuitBreakerConfig|null
     */
    private ?CircuitBreakerConfig $circuitBreaker;

    /**
     * Exception classes that should trigger failover.
     *
     * @var array<class-string<\Throwable>>
     */
    private array $failoverOn;

    /**
     * Maximum number of providers to attempt.
     *
     * @var int
     */
    private int $maxAttempts;

    /**
     * Callback for reporting metrics.
     *
     * @var callable|null
     */
    private $metricsCallback;

    /**
     * The fallback routing strategy.
     *
     * @var FallbackStrategy
     */
    private FallbackStrategy $strategy;

    /**
     * Weights for weighted routing strategy.
     *
     * @var array<int, int>
     */
    private array $weights;

    /**
     * Creates a new FallbackConfig instance.
     *
     * @param FallbackStrategy $strategy The fallback routing strategy.
     *        Default is SEQUENTIAL.
     * @param array<class-string<\Throwable>>|null $failoverOn Exception classes
     *        that trigger failover. Default is ProviderException, HttpException,
     *        and RateLimitException.
     * @param int $maxAttempts Maximum number of providers to attempt.
     *        Default is 3.
     * @param CircuitBreakerConfig|null $circuitBreaker Optional circuit breaker
     *        configuration. Pass null to disable circuit breaking.
     * @param array<int, int> $weights Provider weights for WEIGHTED strategy.
     *        Keys are provider indices, values are weights. Default is empty
     *        (equal weights).
     */
    public function __construct(
        FallbackStrategy $strategy = FallbackStrategy::SEQUENTIAL,
        ?array $failoverOn = null,
        int $maxAttempts = 3,
        ?CircuitBreakerConfig $circuitBreaker = null,
        array $weights = []
    ) {
        $this->strategy = $strategy;
        $this->failoverOn = $failoverOn ?? [
            ProviderException::class,
            HttpException::class,
            RateLimitException::class,
        ];
        $this->maxAttempts = max(1, $maxAttempts);
        $this->circuitBreaker = $circuitBreaker;
        $this->weights = $weights;
        $this->metricsCallback = null;
    }

    /**
     * Returns the circuit breaker configuration.
     *
     * @return CircuitBreakerConfig|null The circuit breaker config, or null if disabled.
     */
    public function getCircuitBreaker(): ?CircuitBreakerConfig {
        return $this->circuitBreaker;
    }

    /**
     * Returns the exception classes that trigger failover.
     *
     * @return array<class-string<\Throwable>> List of exception class names.
     */
    public function getFailoverOn(): array {
        return $this->failoverOn;
    }

    /**
     * Returns the maximum number of providers to attempt.
     *
     * @return int Maximum number of attempts.
     */
    public function getMaxAttempts(): int {
        return $this->maxAttempts;
    }

    /**
     * Returns the metrics callback.
     *
     * @return callable|null The metrics callback, or null if not set.
     */
    public function getMetricsCallback(): ?callable {
        return $this->metricsCallback;
    }

    /**
     * Returns the fallback routing strategy.
     *
     * @return FallbackStrategy The routing strategy.
     */
    public function getStrategy(): FallbackStrategy {
        return $this->strategy;
    }

    /**
     * Returns the weight for a specific provider index.
     *
     * @param int $index The provider index.
     *
     * @return int The weight for that provider (default is 1).
     */
    public function getWeight(int $index): int {
        return $this->weights[$index] ?? 1;
    }

    /**
     * Returns all weights.
     *
     * @return array<int, int> Map of provider index to weight.
     */
    public function getWeights(): array {
        return $this->weights;
    }

    /**
     * Sets the metrics callback.
     *
     * The callback is invoked after each request with information about
     * which provider was used, whether it succeeded, and latency.
     *
     * @param callable|null $callback Callback with signature:
     *        function(string $providerName, bool $success, int $latencyMs, ?string $error): void
     *
     * @return self For method chaining.
     */
    public function setMetricsCallback(?callable $callback): self {
        $this->metricsCallback = $callback;

        return $this;
    }

    /**
     * Checks if a given exception should trigger failover.
     *
     * @param \Throwable $exception The exception to check.
     *
     * @return bool True if the exception should trigger failover.
     */
    public function shouldFailover(\Throwable $exception): bool {
        foreach ($this->failoverOn as $exceptionClass) {
            if ($exception instanceof $exceptionClass) {
                return true;
            }
        }

        return false;
    }
}
