<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Context;

/**
 * Behavior applied when the accumulating conversation exceeds the configured
 * context budget *between* tool-execution iterations.
 *
 * Used only when {@see \WebFiori\Ai\ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS}
 * is enabled. Both behaviors preserve tool-call / tool-result pairing so that
 * every outgoing request remains provider-valid.
 *
 * @author Ibrahim
 */
enum ToolLoopOverflowBehavior {
    /**
     * Hard-drop the oldest tool-call groups (an assistant tool-call message
     * together with all of its tool-result messages) until the conversation
     * fits within the budget. The system message and the most recent tool-call
     * group are always preserved. Requires no summarizer.
     */
    case DROP_OLDEST;

    /**
     * Replace the dropped tool-call groups with a compact summary instead of
     * discarding them outright, preserving the gist of earlier tool activity.
     * Requires a summarizer provider (see
     * {@see \WebFiori\Ai\ChatOption::TOOL_LOOP_SUMMARIZER}).
     */
    case SUMMARIZE;
}
