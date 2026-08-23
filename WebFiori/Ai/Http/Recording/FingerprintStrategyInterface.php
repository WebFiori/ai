<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Http\Recording;

use WebFiori\Ai\Http\HttpRequest;

/**
 * Contract for request fingerprinting strategies.
 *
 * A fingerprint uniquely identifies a request for fixture matching purposes.
 * Two requests with the same fingerprint are considered equivalent and will
 * match the same fixture.
 *
 * @author Ibrahim
 */
interface FingerprintStrategyInterface {
    /**
     * Computes a fingerprint string for the given request.
     *
     * @param HttpRequest $request The HTTP request.
     *
     * @return string A deterministic fingerprint string.
     */
    public function fingerprint(HttpRequest $request): string;
}
