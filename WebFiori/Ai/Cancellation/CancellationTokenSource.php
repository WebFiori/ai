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
 * The publisher side of the cancellation primitive.
 *
 * A caller creates a source, passes its token to an operation (via
 * `$options[ChatOption::CANCELLATION_TOKEN]`), and later calls
 * {@see CancellationTokenSource::cancel()} to abort — for example from a "stop"
 * button or when a client disconnect is detected. All listeners registered on
 * the token fire on cancellation so each layer (HTTP teardown, tool loop,
 * application cleanup, logging) can react independently.
 *
 * Usage:
 * ```php
 * $source = new CancellationTokenSource();
 * $response = $client->chat($messages, [
 *     ChatOption::CANCELLATION_TOKEN => $source->getToken(),
 * ]);
 * // elsewhere / on another signal:
 * $source->cancel();
 * ```
 *
 * @author Ibrahim
 */
final class CancellationTokenSource {
    /**
     * Shared state driving the token.
     *
     * @var CancellationState
     */
    private CancellationState $state;
    /**
     * The token view bound to this source.
     *
     * @var CancellationToken
     */
    private CancellationToken $token;

    /**
     * Creates a new, not-yet-cancelled cancellation source.
     */
    public function __construct() {
        $this->state = new CancellationState();
        $this->token = new CancellationToken($this->state);
    }

    /**
     * Requests cancellation.
     *
     * Invokes all listeners registered on the token exactly once (idempotent);
     * listeners registered afterwards run immediately.
     */
    public function cancel(): void {
        $this->state->cancel();
    }

    /**
     * Returns the token for this source.
     *
     * The same token instance is returned on every call.
     *
     * @return CancellationToken The token bound to this source.
     */
    public function getToken(): CancellationToken {
        return $this->token;
    }

    /**
     * Returns whether cancellation has been requested.
     *
     * @return bool True if {@see CancellationTokenSource::cancel()} has been called.
     */
    public function isCancelled(): bool {
        return $this->state->isCancelled();
    }
}
