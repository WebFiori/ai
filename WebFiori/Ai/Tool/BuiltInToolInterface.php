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
 * Marker interface for provider-native built-in tools.
 *
 * Built-in tools are capabilities provided by the AI provider itself
 * (e.g., Google Search, code execution) that require no PHP handler.
 * They are passed to the provider API differently from custom function
 * calling tools.
 *
 * Implementations are per-provider backed enums:
 * - {@see GoogleBuiltInTool} for Google Gemini
 * - {@see AnthropicBuiltInTool} for Anthropic Claude
 * - {@see OpenAIBuiltInTool} for OpenAI
 *
 * Usage:
 * ```php
 * $response = $client->chat($messages, [
 *     'tools'          => [$myCustomTool],
 *     'built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH],
 * ]);
 * ```
 *
 * @author Ibrahim
 */
interface BuiltInToolInterface {
    /**
     * Returns the provider-specific identifier for this built-in tool.
     *
     * @return string The tool identifier as expected by the provider API.
     */
    public function getValue(): string;
}
