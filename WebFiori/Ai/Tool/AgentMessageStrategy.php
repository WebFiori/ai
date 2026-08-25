<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool;

/**
 * Defines how messages are passed to an agent tool when it is invoked.
 *
 * This enum controls whether the agent receives only the delegated task
 * or the full conversation history alongside the task, allowing different
 * levels of context awareness.
 *
 * @author Ibrahim
 */
enum AgentMessageStrategy: string {
    /**
     * Pass the full conversation history plus the task to the agent.
     *
     * The agent receives a system prompt (from its profile), the full
     * conversation context set externally, and a final user message
     * containing the delegated task.
     */
    case FULL_HISTORY = 'full_history';
    /**
     * Only pass the task as a user message to the agent.
     *
     * The agent receives a system prompt (from its profile) and a single
     * user message containing the delegated task. No prior conversation
     * history is included.
     */
    case TASK_ONLY = 'task_only';
}
