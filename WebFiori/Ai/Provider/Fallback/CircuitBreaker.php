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

/**
 * Tracks the circuit breaker state for a single provider.
 *
 * This class maintains failure/success counters and state transitions
 * for the circuit breaker pattern. It is used internally by
 * FallbackProvider to track each provider's health.
 *
 * @author Ibrahim
 */
class CircuitBreaker {
    /**
     * The circuit breaker configuration.
     *
     * @var CircuitBreakerConfig
     */
    private CircuitBreakerConfig $config;

    /**
     * Count of consecutive failures.
     *
     * @var int
     */
    private int $failureCount = 0;

    /**
     * Timestamp when the circuit was opened.
     *
     * @var int|null
     */
    private ?int $openedAt = null;

    /**
     * Current state of the circuit.
     *
     * @var CircuitState
     */
    private CircuitState $state = CircuitState::CLOSED;

    /**
     * Count of consecutive successes in half-open state.
     *
     * @var int
     */
    private int $successCount = 0;

    /**
     * Creates a new CircuitBreaker instance.
     *
     * @param CircuitBreakerConfig $config The circuit breaker configuration.
     */
    public function __construct(CircuitBreakerConfig $config) {
        $this->config = $config;
    }

    /**
     * Checks if the circuit allows a request to pass through.
     *
     * In CLOSED state, always returns true.
     * In OPEN state, returns true only if the cooldown period has passed.
     * In HALF_OPEN state, always returns true.
     *
     * @return bool True if a request should be attempted.
     */
    public function allowRequest(): bool {
        switch ($this->state) {
            case CircuitState::CLOSED:
                return true;

            case CircuitState::OPEN:
                if ($this->isCooldownExpired()) {
                    $this->transitionToHalfOpen();

                    return true;
                }

                return false;

            case CircuitState::HALF_OPEN:
                return true;
        }
    }

    /**
     * Returns the current failure count.
     *
     * @return int The number of consecutive failures.
     */
    public function getFailureCount(): int {
        return $this->failureCount;
    }

    /**
     * Returns the current state of the circuit.
     *
     * @return CircuitState The circuit state.
     */
    public function getState(): CircuitState {
        return $this->state;
    }

    /**
     * Returns the current success count (in half-open state).
     *
     * @return int The number of consecutive successes.
     */
    public function getSuccessCount(): int {
        return $this->successCount;
    }

    /**
     * Records a failed request.
     *
     * Increments the failure counter. If the threshold is reached
     * in CLOSED state, transitions to OPEN. In HALF_OPEN state,
     * immediately transitions back to OPEN.
     */
    public function recordFailure(): void {
        $this->failureCount++;
        $this->successCount = 0;

        switch ($this->state) {
            case CircuitState::CLOSED:
                if ($this->failureCount >= $this->config->getFailureThreshold()) {
                    $this->transitionToOpen();
                }

                break;

            case CircuitState::HALF_OPEN:
                $this->transitionToOpen();

                break;

            case CircuitState::OPEN:
                // Already open, no change needed
                break;
        }
    }

    /**
     * Records a successful request.
     *
     * Resets the failure counter. In HALF_OPEN state, increments
     * the success counter and transitions to CLOSED if the
     * threshold is reached.
     */
    public function recordSuccess(): void {
        $this->failureCount = 0;

        switch ($this->state) {
            case CircuitState::CLOSED:
                // Already healthy, nothing to do
                break;

            case CircuitState::HALF_OPEN:
                $this->successCount++;

                if ($this->successCount >= $this->config->getSuccessThreshold()) {
                    $this->transitionToClosed();
                }

                break;

            case CircuitState::OPEN:
                // Should not happen, but treat as recovery start
                $this->transitionToHalfOpen();

                break;
        }
    }

    /**
     * Resets the circuit breaker to its initial closed state.
     */
    public function reset(): void {
        $this->state = CircuitState::CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->openedAt = null;
    }

    /**
     * Checks if the cooldown period has expired.
     *
     * @return bool True if the cooldown period has passed.
     */
    private function isCooldownExpired(): bool {
        if ($this->openedAt === null) {
            return true;
        }

        return (time() - $this->openedAt) >= $this->config->getCooldownSeconds();
    }

    /**
     * Transitions the circuit to CLOSED state.
     */
    private function transitionToClosed(): void {
        $this->state = CircuitState::CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->openedAt = null;
    }

    /**
     * Transitions the circuit to HALF_OPEN state.
     */
    private function transitionToHalfOpen(): void {
        $this->state = CircuitState::HALF_OPEN;
        $this->successCount = 0;
    }

    /**
     * Transitions the circuit to OPEN state.
     */
    private function transitionToOpen(): void {
        $this->state = CircuitState::OPEN;
        $this->openedAt = time();
        $this->successCount = 0;
    }
}
