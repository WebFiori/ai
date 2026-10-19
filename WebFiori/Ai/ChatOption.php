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

    // ─── Guardrails ─────────────────────────────────────────────────────────

    /**
     * A DomainBoundaries instance used to short-circuit off-topic requests
     * before the provider is called. When the latest user message is out of
     * scope, chat() returns a synthetic ChatResponse without an HTTP call.
     */
    public const DOMAIN_BOUNDARIES = 'domain_boundaries';

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

    /**
     * A {@see Context\ToolLoopOverflowBehavior} controlling how
     * mid-loop overflow is handled when
     * {@see self::TRUNCATE_BETWEEN_TOOL_ITERATIONS} is enabled.
     *
     * Defaults to ToolLoopOverflowBehavior::DROP_OLDEST.
     */
    public const TOOL_LOOP_OVERFLOW_BEHAVIOR = 'tool_loop_overflow_behavior';

    /**
     * An optional {@see Provider\ProviderInterface} used to
     * summarize dropped tool-call groups when
     * {@see self::TOOL_LOOP_OVERFLOW_BEHAVIOR} is
     * ToolLoopOverflowBehavior::SUMMARIZE. Prefer a cheap/fast model.
     *
     * When not provided, a configured SummarizingWindowStrategy's summarizer is
     * reused; if neither is available, chat() fails fast with a configuration
     * error.
     */
    public const TOOL_LOOP_SUMMARIZER = 'tool_loop_summarizer';

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
     * When true, re-applies the configured context window strategy *between*
     * tool-execution iterations (not just once before the loop), keeping the
     * accumulating conversation within budget during long tool chains.
     *
     * Opt-in; defaults to false. No-op when no context strategy is configured.
     * Truncation is tool-call-pair aware so outgoing requests stay valid.
     */
    public const TRUNCATE_BETWEEN_TOOL_ITERATIONS = 'truncate_between_tool_iterations';

    /**
     * Prevent instantiation.
     */
    private function __construct() {
    }
}
