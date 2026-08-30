<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Formatter;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;

/**
 * Contract for provider-specific request/response formatting.
 *
 * A formatter encapsulates how messages, tools, and other options are
 * serialized into HTTP requests and how HTTP responses are parsed back
 * into library types. It is concerned only with **format**, not transport
 * (authentication, endpoint URLs, connection handling).
 *
 * This separation allows a formatter to be reused across multiple
 * transport contexts. For example, AnthropicFormatter can be used by
 * AnthropicClient (direct API) and by GoogleClient (Vertex AI Model Garden),
 * since both use the same Anthropic Messages API format.
 *
 * @author Ibrahim
 */
interface ProviderFormatterInterface {
    /**
     * Builds the full HTTP request for a chat completion.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options (temperature, tools, etc.).
     * @param string $endpointUrl The endpoint URL for this request.
     * @param array<string, string> $headers The HTTP headers to include.
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildChatRequest(
        array $messages,
        array $options,
        string $endpointUrl,
        array $headers
    ): HttpRequest;

    /**
     * Builds the full HTTP request for an embeddings call.
     *
     * @param string|string[] $input The text(s) to embed.
     * @param array<string, mixed> $options Additional options.
     * @param string $endpointUrl The endpoint URL.
     * @param array<string, string> $headers The HTTP headers.
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildEmbedRequest(
        string|array $input,
        array $options,
        string $endpointUrl,
        array $headers
    ): HttpRequest;

    /**
     * Builds the full HTTP request for image generation.
     *
     * @param ImageRequest $request The image generation request.
     * @param string $endpointUrl The endpoint URL.
     * @param array<string, string> $headers The HTTP headers.
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildImageRequest(
        ImageRequest $request,
        string $endpointUrl,
        array $headers
    ): HttpRequest;

    /**
     * Builds the full HTTP request for a streaming chat completion.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     * @param string $endpointUrl The endpoint URL.
     * @param array<string, string> $headers The HTTP headers.
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildStreamChatRequest(
        array $messages,
        array $options,
        string $endpointUrl,
        array $headers
    ): HttpRequest;

    /**
     * Inspects an HTTP response and throws the appropriate exception for errors.
     *
     * @param HttpResponse $response The HTTP response to check.
     *
     * @throws AuthenticationException If 401/403.
     * @throws RateLimitException If 429.
     * @throws ProviderException For other errors.
     */
    public function handleErrorResponse(HttpResponse $response): void;

    /**
     * Parses an HTTP response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return ChatResponse The parsed response.
     */
    public function parseChatResponse(HttpResponse $response): ChatResponse;

    /**
     * Parses an HTTP response into an EmbeddingResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return EmbeddingResponse The parsed response.
     */
    public function parseEmbedResponse(HttpResponse $response): EmbeddingResponse;

    /**
     * Parses an HTTP response into an ImageResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return ImageResponse The parsed response.
     */
    public function parseImageResponse(HttpResponse $response): ImageResponse;
}
