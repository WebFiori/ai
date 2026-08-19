<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Google;

/**
 * Specifies which API version to use for Google chat completions.
 *
 * @author Ibrahim
 */
enum GoogleApiVersion: string {
    /**
     * Auto-detect based on model name.
     *
     * Models gemini-3.x and above use INTERACTIONS, others use GENERATE_CONTENT.
     */
    case AUTO = 'auto';

    /**
     * Legacy generateContent API.
     *
     * Endpoint: /v1beta/models/{model}:generateContent
     * Used by gemini-1.x and gemini-2.x models.
     */
    case GENERATE_CONTENT = 'generate_content';

    /**
     * New Interactions API.
     *
     * Endpoint: /v1beta/interactions
     * Used by gemini-3.x and newer models.
     * Different request/response format with steps-based output.
     */
    case INTERACTIONS = 'interactions';
}
