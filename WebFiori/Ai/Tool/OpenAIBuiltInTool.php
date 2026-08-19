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
 * Native built-in tools available on OpenAI.
 *
 * These tools are provided by OpenAI and require no PHP handler.
 * Pass them via the `built_in_tools` option in `chat()`:
 *
 * ```php
 * $response = $openAIClient->chat($messages, [
 *     'built_in_tools' => [OpenAIBuiltInTool::WEB_SEARCH],
 * ]);
 * ```
 *
 * @author Ibrahim
 */
enum OpenAIBuiltInTool: string implements BuiltInToolInterface {
    /**
     * Returns the provider-specific identifier.
     *
     * @return string The tool identifier for the OpenAI API.
     */
    public function getValue(): string {
        return $this->value;
    }

    /**
     * Enables the code interpreter.
     *
     * Allows the model to write and execute Python code in a
     * sandboxed environment.
     */
    case CODE_INTERPRETER = 'code_interpreter';

    /**
     * Enables file search (vector store retrieval).
     *
     * Allows the model to search through uploaded files using
     * OpenAI's built-in vector store.
     */
    case FILE_SEARCH = 'file_search';
    /**
     * Enables web search.
     *
     * Allows the model to search the web for current information
     * to ground its responses.
     */
    case WEB_SEARCH = 'web_search_preview';
}
