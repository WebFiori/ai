<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Fallback;

/**
 * Enumeration of circuit breaker states.
 *
 * The circuit breaker follows the standard pattern:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Provider is marked as unavailable, requests are rejected
 * - HALF_OPEN: Testing if the provider has recovered
 *
 * @author Ibrahim
 */
enum CircuitState: string {
    /**
     * Normal operation. Requests pass through to the provider.
     */
    case CLOSED = 'closed';

    /**
     * Testing recovery. A limited number of requests are allowed through.
     */
    case HALF_OPEN = 'half_open';

    /**
     * Provider is unavailable. Requests are immediately rejected.
     */
    case OPEN = 'open';
}
