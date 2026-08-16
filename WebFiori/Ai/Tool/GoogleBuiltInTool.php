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
 * Native built-in tools available on Google Gemini.
 *
 * These tools are provided by Google and require no PHP handler.
 * Pass them via the `built_in_tools` option in `chat()`:
 *
 * ```php
 * $response = $googleClient->chat($messages, [
 *     'built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH],
 * ]);
 * ```
 *
 * Note: On Vertex AI, mixing `GOOGLE_SEARCH` with custom `tools`
 * (function declarations) is not supported and will throw
 * `UnsupportedFeatureException`. The standard Gemini API allows both.
 *
 * @author Ibrahim
 */
enum GoogleBuiltInTool: string implements BuiltInToolInterface {
    /**
     * Enables Google Search grounding.
     *
     * The model can search the web to ground its responses in current
     * information. Responses include search result citations.
     */
    case GOOGLE_SEARCH = 'google_search';

    /**
     * Enables server-side code execution.
     *
     * The model can write and execute Python code to perform calculations,
     * data analysis, or other computational tasks.
     */
    case CODE_EXECUTION = 'code_execution';

    /**
     * Enables URL context retrieval.
     *
     * The model can fetch and read the content of URLs mentioned in
     * the conversation.
     */
    case URL_CONTEXT = 'url_context';

    /**
     * Returns the provider-specific identifier.
     *
     * @return string The tool identifier for the Google API.
     */
    public function getValue(): string {
        return $this->value;
    }
}
