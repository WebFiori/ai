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
 * A fake HTTP client for use in unit and integration tests.
 *
 * This client records all requests made and returns pre-configured
 * responses without making actual network calls.
 *
 * @author Ibrahim
 */
class FakeHttpClient implements HttpClientInterface {
    /**
     * All requests that have been sent through this client.
     *
     * @var HttpRequest[]
     */
    private array $requests = [];

    /**
     * Queue of responses to return for successive calls.
     *
     * @var HttpResponse[]
     */
    private array $responseQueue = [];

    /**
     * Queue of streaming chunks to emit for successive streaming calls.
     *
     * @var string[][]
     */
    private array $streamingQueue = [];

    /**
     * Adds a response to the queue.
     *
     * Responses are returned in FIFO order. Each call to {@see send()}
     * consumes one response from the queue.
     *
     * @param HttpResponse $response The response to enqueue.
     *
     * @return self Returns the instance for method chaining.
     */
    public function addResponse(HttpResponse $response): self {
        $this->responseQueue[] = $response;

        return $this;
    }

    /**
     * Adds streaming chunks to the queue.
     *
     * Chunks are emitted in order for the next call to {@see sendStreaming()}.
     *
     * @param string[] $chunks The chunks to emit.
     *
     * @return self Returns the instance for method chaining.
     */
    public function addStreamingChunks(array $chunks): self {
        $this->streamingQueue[] = $chunks;

        return $this;
    }

    /**
     * Returns the last request that was sent.
     *
     * @return HttpRequest|null The last request, or null if no requests were made.
     */
    public function getLastRequest(): ?HttpRequest {
        if (empty($this->requests)) {
            return null;
        }

        return $this->requests[count($this->requests) - 1];
    }

    /**
     * Returns all requests that have been sent through this client.
     *
     * @return HttpRequest[] An array of all recorded requests.
     */
    public function getRequests(): array {
        return $this->requests;
    }

    /**
     * Resets the client state, clearing all recorded requests and queued responses.
     *
     * @return self Returns the instance for method chaining.
     */
    public function reset(): self {
        $this->requests = [];
        $this->responseQueue = [];
        $this->streamingQueue = [];

        return $this;
    }

    /**
     * Records the request and returns the next response from the queue.
     *
     * @param HttpRequest $request The request to send.
     *
     * @return HttpResponse The next queued response.
     *
     * @throws \RuntimeException If no responses are queued.
     */
    public function send(HttpRequest $request): HttpResponse {
        $this->requests[] = $request;

        if (empty($this->responseQueue)) {
            throw new \RuntimeException(
                'FakeHttpClient: No responses queued. Call addResponse() before sending requests.'
            );
        }

        return array_shift($this->responseQueue);
    }

    /**
     * Records the request and emits chunks from the streaming queue.
     *
     * @param HttpRequest $request The request to send.
     * @param callable $onChunk Callback invoked for each chunk of data.
     *        The callback signature is: function(string $chunk): void
     *
     * @throws \RuntimeException If no streaming chunks are queued.
     */
    public function sendStreaming(HttpRequest $request, callable $onChunk): void {
        $this->requests[] = $request;

        if (empty($this->streamingQueue)) {
            throw new \RuntimeException(
                'FakeHttpClient: No streaming chunks queued. Call addStreamingChunks() before streaming.'
            );
        }

        $chunks = array_shift($this->streamingQueue);

        foreach ($chunks as $chunk) {
            $onChunk($chunk);
        }
    }
}
