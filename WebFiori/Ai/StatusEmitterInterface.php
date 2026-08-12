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
 * Contract for receiving real-time status events during AI operations.
 *
 * Implement this interface to receive progress updates during chat() calls,
 * including tool executions, cache hits, and request lifecycle events.
 *
 * Built-in implementations:
 * - {@see NullStatusEmitter}     No-op (default)
 * - {@see CallbackStatusEmitter} Delegates to a callable
 * - {@see SSEStatusEmitter}      Streams events via Server-Sent Events
 *
 * @author Ibrahim
 */
interface StatusEmitterInterface {
    /**
     * Emits a status event.
     *
     * @param string $status The status identifier. Use {@see Status} constants.
     * @param array<string, mixed> $context Additional context data for the event.
     */
    public function emit(string $status, array $context = []): void;
}
