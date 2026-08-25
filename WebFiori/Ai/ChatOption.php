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
 * Constants for chat request option keys.
 *
 * These are the recognized keys in the $options array passed to chat(),
 * streamChat(), and related methods. Using these constants instead of
 * raw strings provides IDE discoverability and prevents typo-related bugs.
 *
 * Usage:
 * ```php
 * $client->chat($messages, [
 *     ChatOption::TEMPERATURE => 0.7,
 *     ChatOption::MAX_TOKENS  => 1024,
 *     ChatOption::JSON_MODE   => true,
 * ]);
 * ```
 *
 * @author Ibrahim
 */
final class ChatOption {
    /**
     * Whether to automatically execute tool calls and feed results back.
     */
    public const AUTO_EXECUTE_TOOLS = 'auto_execute_tools';

    // ─── Embeddings ───────────────────────────────────────────────────────────

    /**
     * The number of dimensions for embedding vectors.
     */
    public const DIMENSIONS = 'dimensions';

    /**
     * The model to use specifically for embedding generation.
     */
    public const EMBEDDING_MODEL = 'embedding_model';

    // ─── Routing ──────────────────────────────────────────────────────────────

    /**
     * Forces routing to a specific provider/tier, bypassing strategy.
     */
    public const FORCE_PROVIDER = 'force_provider';

    // ─── Structured Output ────────────────────────────────────────────────────

    /**
     * When true, instructs the model to output valid JSON.
     */
    public const JSON_MODE = 'json_mode';

    /**
     * A JSON Schema that the model's output must conform to.
     */
    public const JSON_SCHEMA = 'json_schema';

    /**
     * Maximum number of tokens to generate in the response.
     */
    public const MAX_TOKENS = 'max_tokens';

    /**
     * Maximum number of tool call/response iterations before stopping.
     */
    public const MAX_TOOL_ITERATIONS = 'max_tool_iterations';
    // ─── Core Parameters ──────────────────────────────────────────────────────

    /**
     * The model identifier to use for the request.
     */
    public const MODEL = 'model';

    /**
     * Whether to execute multiple tool calls in parallel.
     */
    public const PARALLEL_TOOL_EXECUTION = 'parallel_tool_execution';

    // ─── Google Interactions API ──────────────────────────────────────────────

    /**
     * The ID of a previous interaction for multi-turn conversations.
     */
    public const PREVIOUS_INTERACTION_ID = 'previous_interaction_id';

    // ─── Metadata ─────────────────────────────────────────────────────────────

    /**
     * A unique identifier for the request, used in logging and metrics.
     */
    public const REQUEST_ID = 'request_id';

    /**
     * Stop sequences that cause the model to stop generating.
     */
    public const STOP = 'stop';

    /**
     * Controls randomness of the response (0.0 = deterministic, 2.0 = most random).
     */
    public const TEMPERATURE = 'temperature';

    // ─── Tool Calling ─────────────────────────────────────────────────────────

    /**
     * Array of Tool/ToolInterface instances available for the model to call.
     */
    public const TOOLS = 'tools';

    /**
     * Nucleus sampling: only consider tokens with cumulative probability <= top_p.
     */
    public const TOP_P = 'top_p';

    /**
     * Prevent instantiation.
     */
    private function __construct() {
    }
}
