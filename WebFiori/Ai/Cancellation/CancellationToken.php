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

use WebFiori\Ai\Exception\OperationCancelledException;

/**
 * A read/subscribe view over a cancellation signal.
 *
 * A token is produced by a {@see CancellationTokenSource} and passed to an
 * operation (via `$options[ChatOption::CANCELLATION_TOKEN]`). The operation can
 * poll {@see CancellationToken::isCancelled()}, bail out with
 * {@see CancellationToken::throwIfCancelled()}, or register cleanup via
 * {@see CancellationToken::onCancel()}. Only the owning source can trigger
 * cancellation — the token itself is read-only with respect to the signal.
 *
 * This is a small, self-contained pub/sub primitive and is intentionally not
 * built on an external pub/sub package.
 *
 * @author Ibrahim
 */
final class CancellationToken {
    /**
     * Shared state owned by the source.
     *
     * @var CancellationState
     */
    private CancellationState $state;

    /**
     * Creates a token bound to the given shared cancellation state.
     *
     * Not intended for direct construction by consumers; obtain a token from
     * {@see CancellationTokenSource::getToken()}.
     *
     * @param CancellationState $state The shared state owned by the source.
     */
    public function __construct(CancellationState $state) {
        $this->state = $state;
    }

    /**
     * Returns whether cancellation has been requested.
     *
     * @return bool True if the owning source has been cancelled.
     */
    public function isCancelled(): bool {
        return $this->state->isCancelled();
    }

    /**
     * Registers a listener to run when cancellation is requested.
     *
     * If cancellation has already been requested, the listener is invoked
     * immediately. Multiple listeners may be registered; all are invoked on
     * cancellation, and a listener that throws does not prevent the others from
     * running.
     *
     * @param callable $listener The listener to invoke on cancellation.
     */
    public function onCancel(callable $listener): void {
        $this->state->subscribe($listener);
    }

    /**
     * Throws an OperationCancelledException if cancellation has been requested.
     *
     * @throws OperationCancelledException If the owning source has been cancelled.
     */
    public function throwIfCancelled(): void {
        if ($this->state->isCancelled()) {
            throw new OperationCancelledException();
        }
    }
}
