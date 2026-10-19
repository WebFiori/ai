<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Context;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * A minimal summarizer provider stub for mid-loop SUMMARIZE tests.
 *
 * Returns a fixed summary string and records how many times chat() was called.
 */
class StubSummarizerProvider implements ProviderInterface {
    public int $callCount = 0;
    private string $summary;

    public function __construct(string $summary = 'summary') {
        $this->summary = $summary;
    }

    public function chat(array $messages, array $options = []): ChatResponse {
        $this->callCount++;

        return new ChatResponse(
            new Message('assistant', $this->summary),
            'stub-summarizer',
            null,
            'stop'
        );
    }

    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        return new EmbeddingResponse([[0.1]], 'stub-summarizer');
    }

    public function generateImage(ImageRequest $request): ImageResponse {
        return new ImageResponse([], 'stub-summarizer');
    }

    public function getName(): string {
        return 'stub-summarizer';
    }

    public function healthCheck(int $timeout = 5): HealthCheckResult {
        return HealthCheckResult::success(1, 'stub-summarizer');
    }

    public function setHttpClient(HttpClientInterface $client): void {
    }

    public function setLogCallback(?callable $callback): void {
    }

    public function streamChat(
        array $messages,
        callable $onToken,
        ?callable $onComplete = null,
        ?callable $onError = null,
        array $options = []
    ): void {
    }
}
