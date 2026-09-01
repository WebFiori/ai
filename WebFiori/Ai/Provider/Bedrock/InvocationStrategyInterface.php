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
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;

/**
 * Contract for AWS Bedrock invocation strategies.
 *
 * Each strategy corresponds to one of the Bedrock Runtime API methods
 * defined in {@see ApiMethod}. Implementations handle request building,
 * response parsing, and streaming for their specific API surface.
 *
 * @internal This interface is an internal seam for Bedrock invocation
 *           strategies. It is not part of the public API and may change
 *           without a major version bump.
 *
 * @author Ibrahim
 */
interface InvocationStrategyInterface {
    /**
     * Builds the HTTP request for a chat completion call.
     *
     * @param string $modelId The full Bedrock model ID.
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options (temperature, tools, etc.).
     * @param BedrockClient $client The parent client (for config and header signing).
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildChatRequest(
        string $modelId,
        array $messages,
        array $options,
        BedrockClient $client
    ): HttpRequest;

    /**
     * Builds the HTTP request for a streaming chat completion call.
     *
     * @param string $modelId The full Bedrock model ID.
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     * @param BedrockClient $client The parent client.
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildStreamChatRequest(
        string $modelId,
        array $messages,
        array $options,
        BedrockClient $client
    ): HttpRequest;

    /**
     * Executes the streaming chat request and drives token delivery.
     *
     * @param HttpRequest $request The HTTP request to send.
     * @param BedrockClient $client The parent client.
     * @param callable $onToken Callback invoked for each token.
     *        Signature: function(string $token): void
     * @param callable|null $onComplete Callback invoked when streaming completes.
     *        Signature: function(ChatResponse $response): void
     * @param callable|null $onError Callback invoked on stream error.
     *        Signature: function(StreamingException $e): void
     */
    public function doStreamChat(
        HttpRequest $request,
        BedrockClient $client,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void;

    /**
     * Parses an HTTP response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from Bedrock.
     * @param string $modelId The model ID used for the request.
     *
     * @return ChatResponse The parsed chat response.
     */
    public function parseChatResponse(HttpResponse $response, string $modelId): ChatResponse;
}
