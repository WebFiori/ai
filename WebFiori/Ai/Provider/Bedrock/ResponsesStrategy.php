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

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;

/**
 * Bedrock Responses API invocation strategy.
 *
 * Uses the stateful Responses API which maintains conversation history
 * server-side. Only the new message is sent each turn — AWS manages
 * the full session state.
 *
 * Not all models support this API. Check AWS documentation for
 * compatibility before using this strategy.
 *
 * @author Ibrahim
 *
 * @todo Full implementation in a future milestone.
 */
class ResponsesStrategy implements InvocationStrategyInterface {
    /**
     * Builds the HTTP request for a chat completion using the Responses API.
     *
     * @param string $modelId The full Bedrock model ID.
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     * @param BedrockClient $client The parent client.
     *
     * @return HttpRequest The HTTP request to send.
     *
     * @throws UnsupportedFeatureException Not yet implemented.
     */
    public function buildChatRequest(
        string $modelId,
        array $messages,
        array $options,
        BedrockClient $client
    ): HttpRequest {
        throw new UnsupportedFeatureException(
            'The Bedrock Responses API (ApiMethod::RESPONSES) is not yet implemented.',
            'responses',
            'bedrock'
        );
    }

    /**
     * Builds the HTTP request for a streaming chat using the Responses API.
     *
     * @param string $modelId The full Bedrock model ID.
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     * @param BedrockClient $client The parent client.
     *
     * @return HttpRequest The HTTP request to send.
     *
     * @throws UnsupportedFeatureException Not yet implemented.
     */
    public function buildStreamChatRequest(
        string $modelId,
        array $messages,
        array $options,
        BedrockClient $client
    ): HttpRequest {
        throw new UnsupportedFeatureException(
            'The Bedrock Responses API (ApiMethod::RESPONSES) is not yet implemented.',
            'responses',
            'bedrock'
        );
    }

    /**
     * Executes the streaming chat request.
     *
     * @param HttpRequest $request The HTTP request to send.
     * @param BedrockClient $client The parent client.
     * @param callable $onToken Token callback.
     * @param callable|null $onComplete Completion callback.
     * @param callable|null $onError Error callback.
     *
     * @throws UnsupportedFeatureException Not yet implemented.
     */
    public function doStreamChat(
        HttpRequest $request,
        BedrockClient $client,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void {
        throw new UnsupportedFeatureException(
            'The Bedrock Responses API (ApiMethod::RESPONSES) is not yet implemented.',
            'responses',
            'bedrock'
        );
    }

    /**
     * Parses a Responses API response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from Bedrock.
     * @param string $modelId The model ID used for the request.
     *
     * @return ChatResponse The parsed chat response.
     *
     * @throws UnsupportedFeatureException Not yet implemented.
     */
    public function parseChatResponse(HttpResponse $response, string $modelId): ChatResponse {
        throw new UnsupportedFeatureException(
            'The Bedrock Responses API (ApiMethod::RESPONSES) is not yet implemented.',
            'responses',
            'bedrock'
        );
    }
}
