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
     * Tool lifecycle events carry the following context keys:
     * - {@see Status::TOOL_CALLING}:   `tool`, `tool_call_id`, `arguments`
     * - {@see Status::TOOL_EXECUTING}: `tool`, `tool_call_id`
     * - {@see Status::TOOL_COMPLETED} (success only): `tool`, `tool_call_id`,
     *   `duration_ms`, `ok` (true), `multimodal`, `parts_count`
     * - {@see Status::TOOL_FAILED}: `tool`, `tool_call_id`, `duration_ms`,
     *   `ok` (false), `error`, `error_type`, and `exception_class` when the
     *   failure originated from a thrown exception.
     *
     * `tool_call_id` lets consumers correlate the three lifecycle events for a
     * single call — essential when the same tool is called multiple times in
     * one batch. Prefer switching on {@see Status::TOOL_FAILED} to detect
     * failures; the `ok` flag is provided for consumers that only observe the
     * completion event. Allowed `error_type` values are the `Status::TOOL_ERROR_*`
     * constants ({@see Status::TOOL_ERROR_NOT_FOUND},
     * {@see Status::TOOL_ERROR_EXCEPTION}, {@see Status::TOOL_ERROR_RETURNED}).
     *
     * @param string $status The status identifier. Use {@see Status} constants.
     * @param array<string, mixed> $context Additional context data for the event.
     */
    public function emit(string $status, array $context = []): void;
}
