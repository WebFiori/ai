<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Bedrock;

/**
 * Defines the available AWS Bedrock Runtime invocation API methods.
 *
 * Use these constants when configuring the BedrockClient to select
 * which Bedrock API surface to use for requests.
 *
 * @author Ibrahim
 */
class ApiMethod {
    /**
     * Unified chat API that works across all supported models.
     *
     * AWS translates the standardized request format to each model's native
     * format automatically. This is the recommended method for most use cases
     * as it removes the need to know each model's specific request format.
     *
     * Endpoint: POST /model/{modelId}/converse
     * Streaming: POST /model/{modelId}/converse-stream
     *
     * Supports: chat, streaming, tools, system messages.
     * All of these work identically regardless of the underlying model.
     */
    const CONVERSE = 'converse';

    /**
     * Raw model invocation using each model's native request format.
     *
     * Sends requests directly to the model in its native schema. You are
     * responsible for formatting the request correctly for the target model
     * family (Anthropic, Meta, Mistral, Amazon, etc.).
     *
     * Endpoint: POST /model/{modelId}/invoke
     * Streaming: POST /model/{modelId}/invoke-with-response-stream
     *
     * Use this when you need model-specific parameters not exposed by
     * the Converse API, or when working with models that only support Invoke.
     */
    const INVOKE = 'invoke';

    /**
     * Stateful session-based invocation API.
     *
     * AWS maintains the conversation history server-side. You send only the
     * new message each turn and reference a session ID. This avoids
     * resending the full message history on every request, which is useful
     * for very long conversations.
     *
     * Endpoint: POST /model/{modelId}/invoke-with-response-stream (with session)
     *
     * Note: Not all models support this API. Check AWS documentation for
     * model compatibility before using this method.
     */
    const RESPONSES = 'responses';
}
