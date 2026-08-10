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

use WebFiori\Ai\Redaction\RedactionService;

/**
 * Provides metrics emission functionality to AI provider classes.
 *
 * If a RedactionService is configured, event data is redacted before
 * being passed to the callback.
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
     * The redaction service for sanitizing metric data.
     *
     * @var RedactionService|null
     */
    private ?RedactionService $metricsRedactionService = null;

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
     * @param callable|null $callback The metrics callback, or null to disable.
     */
    public function setMetricsCallback(?callable $callback): void {
        $this->metricsCallback = $callback;
    }

    /**
     * Sets the redaction service for metric data sanitization.
     *
     * @param RedactionService|null $service The redaction service, or null to disable.
     */
    protected function setMetricsRedactionService(?RedactionService $service): void {
        $this->metricsRedactionService = $service;
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

        if ($this->metricsRedactionService !== null) {
            $data = $this->metricsRedactionService->redactContext($data);
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
