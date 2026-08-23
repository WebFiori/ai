<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Routing;

/**
 * Routing mode for ModelRouter.
 *
 * @author Ibrahim
 */
enum RoutingMode: string {
    /**
     * Hybrid routing (default).
     *
     * Rules are evaluated first at zero cost. If no rule matches, the
     * classifier provider is asked to select a tier via tool call.
     */
    case HYBRID = 'hybrid';
    /**
     * Rule-based routing only.
     *
     * Developer-defined conditions determine the tier. No LLM classification
     * call is made. Zero overhead.
     */
    case RULE = 'rule';

    /**
     * Tool-based routing only.
     *
     * A classifier provider calls a built-in routing tool to select the
     * tier. One extra LLM call per request.
     */
    case TOOL = 'tool';
}
