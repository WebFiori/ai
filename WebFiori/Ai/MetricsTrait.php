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

/**
 * Provides metrics emission functionality to AI provider classes.
 *
 * Classes using this trait can emit structured metrics events to a
 * developer-supplied callback for routing to monitoring systems
 * (Prometheus, DataDog, CloudWatch, etc.).
 *
 * @author Ibrahim
 */
trait MetricsTrait {
    /**
     * The metrics callback.
     *
     * @var callable|null
     */
    private $metricsCallback = null;

    /**
     * Returns the current metrics callback.
     *
     * @return callable|null The callback, or null if not set.
     */
    public function getMetricsCallback(): ?callable {
        return $this->metricsCallback;
    }

    /**
     * Sets the callback for emitting metrics events.
     *
     * The callback receives an event name and structured data array.
     * All events include 'timestamp', 'request_id', 'provider', and 'model'.
     *
     * ```php
     * $provider->setMetricsCallback(function (string $event, array $data) {
     *     // Send to Prometheus, DataDog, CloudWatch, etc.
     *     MyMonitoring::record($event, $data);
     * });
     * ```
     *
     * @param callable|null $callback The metrics callback, or null to disable.
     */
    public function setMetricsCallback(?callable $callback): void {
        $this->metricsCallback = $callback;
    }

    /**
     * Emits a metrics event.
     *
     * @param string $event The event name (e.g., 'request.completed').
     * @param array<string, mixed> $data The event data.
     */
    protected function emitMetric(string $event, array $data): void {
        if ($this->metricsCallback === null) {
            return;
        }

        ($this->metricsCallback)($event, $data);
    }

    /**
     * Builds the base data payload included in every metric event.
     *
     * @param string $requestId The unique request identifier.
     * @param string $provider The provider name.
     * @param string|null $model The model name.
     *
     * @return array<string, mixed> Base data array.
     */
    protected function buildBaseMetricData(string $requestId, string $provider, ?string $model): array {
        return [
            'timestamp' => (int) (microtime(true) * 1000),
            'request_id' => $requestId,
            'provider' => $provider,
            'model' => $model,
        ];
    }
}
