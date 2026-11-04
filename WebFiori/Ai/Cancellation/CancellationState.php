<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Cancellation;

/**
 * Shared cancellation state behind a {@see CancellationTokenSource} and its
 * {@see CancellationToken}.
 *
 * Holds the cancelled flag and the listener list, and implements the pub/sub
 * semantics: idempotent cancellation (listeners fire once), late subscribers
 * fire immediately, and a throwing listener does not prevent the others from
 * running. This is an internal collaborator; consumers interact with
 * CancellationTokenSource / CancellationToken.
 *
 * @internal
 *
 * @author Ibrahim
 */
final class CancellationState {
    /**
     * Whether cancellation has been requested.
     *
     * @var bool
     */
    private bool $cancelled = false;
    /**
     * Registered cancellation listeners (cleared once fired).
     *
     * @var array<int, callable>
     */
    private array $listeners = [];
    /**
     * Optional external stop-signal poller.
     *
     * @var callable|null
     */
    private $poller;
    /**
     * Minimum time between two poller invocations, in milliseconds.
     *
     * @var int
     */
    private int $pollIntervalMs;
    /**
     * Time source returning the current time in milliseconds (monotonic).
     *
     * @var callable
     */
    private $clock;
    /**
     * Optional log callback: function(string $level, string $message, array $context).
     *
     * @var callable|null
     */
    private $logCallback;
    /**
     * Clock reading of the last poller invocation, or null if never polled.
     *
     * @var float|null
     */
    private ?float $lastPollMs = null;
    /**
     * Whether the poller is currently running (guards against re-entrancy).
     *
     * @var bool
     */
    private bool $polling = false;
    /**
     * Whether the last poller invocation failed (used to log once per failure streak).
     *
     * @var bool
     */
    private bool $pollerFailing = false;

    /**
     * Creates the shared state.
     *
     * @param callable|null $poller Optional callable returning true when an
     *        external stop has been requested.
     * @param int $pollIntervalMs Minimum time between poller calls, in milliseconds.
     * @param callable|null $clock Optional time source returning the current
     *        time in milliseconds. Defaults to a monotonic clock.
     * @param callable|null $logCallback Optional log callback used to report
     *        poller failures.
     *
     * @throws \InvalidArgumentException If the poll interval is negative.
     */
    public function __construct(
        ?callable $poller = null,
        int $pollIntervalMs = 500,
        ?callable $clock = null,
        ?callable $logCallback = null
    ) {
        if ($pollIntervalMs < 0) {
            throw new \InvalidArgumentException('The poll interval must not be negative.');
        }

        $this->poller = $poller;
        $this->pollIntervalMs = $pollIntervalMs;
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e6;
        $this->logCallback = $logCallback;
    }

    /**
     * Requests cancellation and invokes all registered listeners once.
     *
     * Idempotent: a second call does nothing. Each listener is invoked inside a
     * try/catch so that a throwing listener does not prevent the remaining
     * listeners from running.
     */
    public function cancel(): void {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        $listeners = $this->listeners;
        $this->listeners = [];

        foreach ($listeners as $listener) {
            $this->invoke($listener);
        }
    }

    /**
     * Returns whether cancellation has been requested.
     *
     * If a poller is configured and the poll interval has elapsed since the
     * last poll, the poller is consulted first. A poller that returns true
     * cancels the state through the normal path (listeners fire once). A
     * poller that throws is treated as "not cancelled" and the failure is
     * logged once per failure streak.
     *
     * @return bool True if cancelled.
     */
    public function isCancelled(): bool {
        if (!$this->cancelled && $this->poller !== null) {
            $this->poll();
        }

        return $this->cancelled;
    }

    /**
     * Registers a listener, or invokes it immediately if already cancelled.
     *
     * @param callable $listener The listener to register.
     */
    public function subscribe(callable $listener): void {
        if ($this->cancelled) {
            $this->invoke($listener);

            return;
        }

        $this->listeners[] = $listener;
    }

    /**
     * Consults the poller if the throttle interval has elapsed.
     */
    private function poll(): void {
        if ($this->polling) {
            return;
        }

        $now = (float) ($this->clock)();

        if ($this->lastPollMs !== null && ($now - $this->lastPollMs) < $this->pollIntervalMs) {
            return;
        }

        $this->lastPollMs = $now;
        $this->polling = true;

        try {
            $stop = (bool) ($this->poller)();
            $this->pollerFailing = false;
        } catch (\Throwable $e) {
            $stop = false;
            $this->reportPollerFailure($e);
        } finally {
            $this->polling = false;
        }

        if ($stop) {
            $this->cancel();
        }
    }

    /**
     * Logs a poller failure, once per failure streak.
     *
     * @param \Throwable $e The exception thrown by the poller.
     */
    private function reportPollerFailure(\Throwable $e): void {
        if ($this->pollerFailing) {
            return;
        }

        $this->pollerFailing = true;

        if ($this->logCallback === null) {
            return;
        }

        try {
            ($this->logCallback)('warning', 'Cancellation poller failed; treating as not cancelled', [
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $ignored) {
            // A failing log callback must not break cancellation checks.
        }
    }

    /**
     * Invokes a single listener, swallowing any throwable it raises so that
     * one failing listener cannot break cancellation fan-out.
     *
     * @param callable $listener The listener to invoke.
     */
    private function invoke(callable $listener): void {
        try {
            $listener();
        } catch (\Throwable $e) {
            // Isolate failing listeners — cancellation must still notify the rest.
        }
    }
}
