<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Http;

/**
 * Contract for making outbound HTTP requests to AI provider APIs.
 *
 * Implementations of this interface handle the actual transport layer.
 * The default implementation uses cURL, but alternative implementations
 * can be provided for testing or custom transport requirements.
 *
 * @author Ibrahim
 */
interface HttpClientInterface {
    /**
     * Sends an HTTP request and returns the response.
     *
     * @param HttpRequest $request The request to send.
     *
     * @return HttpResponse The response received from the server.
     *
     * @throws \WebFiori\Ai\Exception\HttpException If the request fails due to
     *         a transport error (connection timeout, DNS failure, etc.).
     */
    public function send(HttpRequest $request): HttpResponse;

    /**
     * Sends an HTTP request and processes the response as a stream.
     *
     * This method is used for Server-Sent Events (SSE) streaming responses
     * where tokens are delivered incrementally.
     *
     * @param HttpRequest $request The request to send.
     * @param callable $onChunk Callback invoked for each chunk of data received.
     *        The callback signature is: function(string $chunk): void
     *
     * @throws \WebFiori\Ai\Exception\HttpException If the request fails due to
     *         a transport error.
     * @throws \WebFiori\Ai\Exception\StreamingException If an error occurs
     *         during stream processing.
     */
    public function sendStreaming(HttpRequest $request, callable $onChunk): void;
}
