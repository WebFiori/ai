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
    public const TOOL_EXECUTING = 'tool_executing';
    public const TRUNCATING_CONTEXT = 'truncating_context';
    public const WAITING_RESPONSE = 'waiting_response';
}
