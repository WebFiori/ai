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
 * Enumeration of fallback routing strategies.
 *
 * @author Ibrahim
 */
enum FallbackStrategy: string {
    /**
     * Distribute requests across providers by cycling through them.
     *
     * Each request goes to the next provider in the list, wrapping
     * around to the first after the last. Failed providers are skipped.
     */
    case ROUND_ROBIN = 'round_robin';

    /**
     * Try providers in order until one succeeds.
     *
     * The first provider is always tried first. If it fails, the
     * next provider is tried, and so on.
     */
    case SEQUENTIAL = 'sequential';

    /**
     * Distribute requests based on assigned weights.
     *
     * Higher-weighted providers receive proportionally more traffic.
     * Useful for cost optimization or gradual rollouts.
     */
    case WEIGHTED = 'weighted';
}
