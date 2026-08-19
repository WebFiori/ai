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
 * Configuration for the circuit breaker pattern.
 *
 * The circuit breaker prevents repeated calls to a failing provider
 * by tracking failures and temporarily disabling the provider after
 * a threshold is reached.
 *
 * @author Ibrahim
 */
class CircuitBreakerConfig {
    /**
     * Number of seconds to wait before attempting recovery.
     *
     * @var int
     */
    private int $cooldownSeconds;

    /**
     * Number of consecutive failures before opening the circuit.
     *
     * @var int
     */
    private int $failureThreshold;

    /**
     * Number of consecutive successes needed to close the circuit.
     *
     * @var int
     */
    private int $successThreshold;

    /**
     * Creates a new CircuitBreakerConfig instance.
     *
     * @param int $failureThreshold Number of consecutive failures before
     *        opening the circuit. Default is 5.
     * @param int $cooldownSeconds Seconds to wait before testing recovery.
     *        Default is 60.
     * @param int $successThreshold Number of consecutive successes in
     *        half-open state needed to close the circuit. Default is 2.
     */
    public function __construct(
        int $failureThreshold = 5,
        int $cooldownSeconds = 60,
        int $successThreshold = 2
    ) {
        $this->failureThreshold = max(1, $failureThreshold);
        $this->cooldownSeconds = max(1, $cooldownSeconds);
        $this->successThreshold = max(1, $successThreshold);
    }

    /**
     * Returns the cooldown period in seconds.
     *
     * @return int Seconds to wait before testing recovery.
     */
    public function getCooldownSeconds(): int {
        return $this->cooldownSeconds;
    }

    /**
     * Returns the failure threshold.
     *
     * @return int Number of consecutive failures before opening the circuit.
     */
    public function getFailureThreshold(): int {
        return $this->failureThreshold;
    }

    /**
     * Returns the success threshold.
     *
     * @return int Number of consecutive successes needed to close the circuit.
     */
    public function getSuccessThreshold(): int {
        return $this->successThreshold;
    }
}
