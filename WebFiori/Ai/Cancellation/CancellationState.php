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
     * @return bool True if cancelled.
     */
    public function isCancelled(): bool {
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
