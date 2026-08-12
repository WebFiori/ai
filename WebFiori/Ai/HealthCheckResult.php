<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Represents the result of a provider health check.
 *
 * Contains information about whether the provider is available,
 * response latency, any errors encountered, and what method was
 * used to perform the check.
 *
 * @author Ibrahim
 */
class HealthCheckResult {
    /**
     * Whether the provider is available.
     *
     * @var bool
     */
    private bool $available;

    /**
     * When the check was performed.
     *
     * @var DateTimeInterface
     */
    private DateTimeInterface $checkedAt;

    /**
     * The method used to perform the health check.
     *
     * @var string
     */
    private string $checkMethod;

    /**
     * Error message if the check failed.
     *
     * @var string|null
     */
    private ?string $error;

    /**
     * Response latency in milliseconds.
     *
     * @var int
     */
    private int $latencyMs;

    /**
     * Creates a new HealthCheckResult instance.
     *
     * @param bool $available Whether the provider is available.
     * @param int $latencyMs Response latency in milliseconds.
     * @param string $checkMethod The method used (e.g., 'models_list', 'minimal_completion').
     * @param string|null $error Error message if unavailable.
     * @param DateTimeInterface|null $checkedAt When the check was performed.
     */
    public function __construct(
        bool $available,
        int $latencyMs,
        string $checkMethod,
        ?string $error = null,
        ?DateTimeInterface $checkedAt = null
    ) {
        $this->available = $available;
        $this->latencyMs = $latencyMs;
        $this->checkMethod = $checkMethod;
        $this->error = $error;
        $this->checkedAt = $checkedAt ?? new DateTimeImmutable();
    }

    /**
     * Creates a failed health check result.
     *
     * @param string $error The error message.
     * @param int $latencyMs Response latency in milliseconds.
     * @param string $checkMethod The method used for the check.
     *
     * @return self The failed result.
     */
    public static function failure(string $error, int $latencyMs, string $checkMethod): self {
        return new self(false, $latencyMs, $checkMethod, $error);
    }

    /**
     * Returns when the health check was performed.
     *
     * @return DateTimeInterface The timestamp of the check.
     */
    public function getCheckedAt(): DateTimeInterface {
        return $this->checkedAt;
    }

    /**
     * Returns the method used to perform the health check.
     *
     * Common values:
     * - 'models_list': Called a models listing endpoint (free)
     * - 'minimal_completion': Made a tiny completion request
     *
     * @return string The check method identifier.
     */
    public function getCheckMethod(): string {
        return $this->checkMethod;
    }

    /**
     * Returns the error message if the check failed.
     *
     * @return string|null The error message, or null if successful.
     */
    public function getError(): ?string {
        return $this->error;
    }

    /**
     * Returns the response latency in milliseconds.
     *
     * @return int Latency in milliseconds.
     */
    public function getLatencyMs(): int {
        return $this->latencyMs;
    }

    /**
     * Returns whether the provider is available.
     *
     * @return bool True if the provider responded successfully.
     */
    public function isAvailable(): bool {
        return $this->available;
    }

    /**
     * Creates a successful health check result.
     *
     * @param int $latencyMs Response latency in milliseconds.
     * @param string $checkMethod The method used for the check.
     *
     * @return self The successful result.
     */
    public static function success(int $latencyMs, string $checkMethod): self {
        return new self(true, $latencyMs, $checkMethod);
    }
}
