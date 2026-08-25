<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Tool;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * A mock provider that captures messages and options passed to chat().
 */
class CapturingMockProvider implements ProviderInterface {
    private string $name;
    private string $responseContent;

    /**
     * The messages passed to the last chat() call.
     *
     * @var Message[]
     */
    public array $lastMessages = [];

    /**
     * The options passed to the last chat() call.
     *
     * @var array<string, mixed>
     */
    public array $lastOptions = [];

    /**
     * Total number of chat() calls.
     */
    public int $callCount = 0;

    public function __construct(string $name = 'capturing-mock', string $responseContent = 'Mock response') {
        $this->name = $name;
        $this->responseContent = $responseContent;
    }

    public function chat(array $messages, array $options = []): ChatResponse {
        $this->lastMessages = $messages;
        $this->lastOptions = $options;
        $this->callCount++;

        return new ChatResponse(
            new Message('assistant', $this->responseContent),
            $this->name,
            null,
            'stop'
        );
    }

    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        return new EmbeddingResponse([[0.1, 0.2]], $this->name);
    }

    public function generateImage(ImageRequest $request): ImageResponse {
        return new ImageResponse([], $this->name);
    }

    public function getName(): string {
        return $this->name;
    }

    public function healthCheck(int $timeout = 5): HealthCheckResult {
        return HealthCheckResult::success(10, $this->name);
    }

    public function setHttpClient(\WebFiori\Ai\Http\HttpClientInterface $client): void {
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
        $onToken('mock');

        if ($onComplete !== null) {
            $onComplete(new ChatResponse(
                new Message('assistant', $this->responseContent),
                $this->name,
                null,
                'stop'
            ));
        }
    }

    public function setResponseContent(string $content): void {
        $this->responseContent = $content;
    }
}
