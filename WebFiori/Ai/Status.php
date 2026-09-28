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
 * Status event constants for real-time progress tracking.
 *
 * These constants identify the current operation during a chat() call,
 * allowing frontend applications to show meaningful progress indicators.
 *
 * @author Ibrahim
 */
final class Status {
    // Domain boundaries
    public const BOUNDARY_ALLOWED = 'boundary_allowed';
    public const BOUNDARY_REDIRECT = 'boundary_redirect';
    // Cache
    public const CACHE_HIT = 'cache_hit';
    public const CACHE_MISS = 'cache_miss';
    public const COMPLETED = 'completed';
    public const ERROR = 'error';
    // Request lifecycle
    public const PREPARING = 'preparing';
    public const SENDING_REQUEST = 'sending_request';

    // Tool execution
    public const TOOL_CALLING = 'tool_calling';
    public const TOOL_COMPLETED = 'tool_completed';
    // Allowed values for the `error_type` field on TOOL_FAILED (and on
    // TOOL_COMPLETED when `ok` is false). Consumers should compare against
    // these constants rather than hard-coding the raw strings:
    //   TOOL_ERROR_NOT_FOUND  → the requested tool was not registered.
    //   TOOL_ERROR_EXCEPTION  → the tool's execute() threw a Throwable.
    //   TOOL_ERROR_RETURNED   → the tool returned ToolResponse::error().
    public const TOOL_ERROR_EXCEPTION = 'exception';
    public const TOOL_ERROR_NOT_FOUND = 'not_found';
    public const TOOL_ERROR_RETURNED = 'tool_error';
    public const TOOL_EXECUTING = 'tool_executing';
    public const TOOL_FAILED = 'tool_failed';

    public const TRUNCATING_CONTEXT = 'truncating_context';
    public const WAITING_RESPONSE = 'waiting_response';
}
