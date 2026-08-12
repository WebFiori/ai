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
 * Callback-based status emitter.
 *
 * Delegates status events to a user-provided callable.
 *
 * ```php
 * $emitter = new CallbackStatusEmitter(function(string $status, array $context) {
 *     echo "[{$status}] " . json_encode($context) . "\n";
 * });
 *
 * $client->setStatusEmitter($emitter);
 * ```
 *
 * @author Ibrahim
 */
class CallbackStatusEmitter implements StatusEmitterInterface {
    /**
     * The callback to invoke on each status event.
     *
     * @var callable
     */
    private $callback;

    /**
     * Creates a new CallbackStatusEmitter.
     *
     * @param callable $callback The callback to invoke.
     *        Signature: function(string $status, array $context): void
     */
    public function __construct(callable $callback) {
        $this->callback = $callback;
    }

    /**
     * Emits a status event by invoking the callback.
     *
     * @param string $status The status identifier.
     * @param array<string, mixed> $context Additional context data.
     */
    public function emit(string $status, array $context = []): void {
        ($this->callback)($status, $context);
    }
}
