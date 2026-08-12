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
 * No-op status emitter. Default implementation that discards all events.
 *
 * @author Ibrahim
 */
class NullStatusEmitter implements StatusEmitterInterface {
    public function emit(string $status, array $context = []): void {
        // Intentionally empty
    }
}
