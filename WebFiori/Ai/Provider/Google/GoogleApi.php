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
 * Specifies which Google API endpoint to use.
 *
 * @author Ibrahim
 */
enum GoogleApi: string {
    /**
     * Gemini API at generativelanguage.googleapis.com.
     *
     * Simpler API, works with the free tier, uses API key authentication.
     */
    case GEMINI = 'gemini';

    /**
     * Vertex AI at aiplatform.googleapis.com.
     *
     * Enterprise endpoint requiring project_id and OAuth/service account.
     */
    case VERTEX_AI = 'vertex_ai';
}
