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
 * Native built-in tools available on Anthropic Claude.
 *
 * These tools are provided by Anthropic and require no PHP handler.
 * Pass them via the `built_in_tools` option in `chat()`:
 *
 * ```php
 * $response = $anthropicClient->chat($messages, [
 *     'built_in_tools' => [AnthropicBuiltInTool::BASH],
 * ]);
 * ```
 *
 * Note: Computer use tools require the appropriate Claude model that
 * supports them (e.g., claude-3-5-sonnet-20241022 or later).
 *
 * @author Ibrahim
 */
enum AnthropicBuiltInTool: string implements BuiltInToolInterface {
    /**
     * Enables computer use — mouse and keyboard control.
     *
     * Allows Claude to interact with a computer desktop by taking
     * screenshots and performing mouse/keyboard actions.
     */
    case COMPUTER = 'computer';

    /**
     * Enables bash command execution.
     *
     * Allows Claude to run shell commands and scripts in a bash
     * environment.
     */
    case BASH = 'bash';

    /**
     * Enables text file editing.
     *
     * Allows Claude to view and edit text files using standard
     * editor operations (view, create, str_replace, insert).
     */
    case TEXT_EDITOR = 'text_editor';

    /**
     * Returns the provider-specific identifier.
     *
     * @return string The tool identifier for the Anthropic API.
     */
    public function getValue(): string {
        return $this->value;
    }
}
