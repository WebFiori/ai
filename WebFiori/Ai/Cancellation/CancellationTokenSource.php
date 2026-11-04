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
     *
     * With no arguments the source is cancelled only by {@see CancellationTokenSource::cancel()}.
     * Supplying a poller lets the token observe an external stop signal (for
     * example a flag written by a different HTTP request): the poller is
     * consulted from {@see CancellationToken::isCancelled()}, at most once per
     * interval, and a `true` result cancels the source.
     *
     * The poller should be cheap, because it can run inside the cURL progress
     * callback. An exception thrown by the poller is treated as "not
     * cancelled" and reported once per failure streak through the log callback.
     * The poller only runs when a checkpoint calls `isCancelled()`; while a
     * request is idle libcurl invokes its progress callback about once per
     * second, so intervals below that add no responsiveness.
     *
     * @param callable|null $poller Returns true when an external stop was requested.
     * @param int $pollIntervalMs Minimum time between poller calls, in milliseconds.
     * @param callable|null $clock Optional time source returning the current
     *        time in milliseconds (defaults to a monotonic clock); mainly for tests.
     * @param callable|null $logCallback Optional log callback
     *        `function(string $level, string $message, array $context)` for poller failures.
     *
     * @throws \InvalidArgumentException If the poll interval is negative.
     */
    public function __construct(
        ?callable $poller = null,
        int $pollIntervalMs = 500,
        ?callable $clock = null,
        ?callable $logCallback = null
    ) {
        $this->state = new CancellationState($poller, $pollIntervalMs, $clock, $logCallback);
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
