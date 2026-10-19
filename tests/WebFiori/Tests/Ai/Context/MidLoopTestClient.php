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
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Provider\ClientConfig;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Usage;

/**
 * A concrete AbstractClient used to exercise the tool-execution loop in tests.
 *
 * `parseChatResponse` returns a scripted sequence: the first `toolIterations`
 * responses request a tool call, the final one is a plain completion. Each
 * outgoing request's formatted messages and incremental slices are recorded so
 * tests can assert on budget and tool-call pairing.
 */
class MidLoopTestClient extends AbstractClient {
    /**
     * Full message set handed to each (incremental or initial) request build.
     *
     * @var array<int, Message[]>
     */
    public array $formattedRequests = [];

    /**
     * Incremental "new messages" slice for each in-loop request build.
     *
     * @var array<int, Message[]>
     */
    public array $incrementalSlices = [];

    private int $toolIterations;
    private int $responseIndex = 0;

    public function __construct(int $toolIterations = 2) {
        $this->toolIterations = $toolIterations;

        $config = new class extends ClientConfig {
            public function __construct() {
                parent::__construct('test-model');
            }

            public function toArray(): array {
                return ['model' => 'test-model'];
            }
        };

        parent::__construct($config);

        // Queue enough HTTP responses for every round-trip (initial + each loop).
        $http = new FakeHttpClient();

        for ($i = 0; $i < $toolIterations + 2; $i++) {
            $http->addResponse(new HttpResponse(200, [], json_encode(['ok' => true])));
        }

        $this->setHttpClient($http);
    }

    public function getName(): string {
        return 'midloop-test';
    }

    protected function buildChatRequest(array $messages, array $options): HttpRequest {
        $this->formattedRequests[] = $messages;

        return new HttpRequest(
            'POST',
            'https://api.test.com/v1/chat',
            ['Content-Type' => 'application/json'],
            json_encode(['messages' => count($messages)])
        );
    }

    protected function buildIncrementalChatRequest(
        HttpRequest $previousRequest,
        array $allMessages,
        array $newMessages,
        array $options
    ): HttpRequest {
        $this->formattedRequests[] = $allMessages;
        $this->incrementalSlices[] = $newMessages;

        return new HttpRequest(
            'POST',
            'https://api.test.com/v1/chat',
            ['Content-Type' => 'application/json'],
            json_encode(['messages' => count($allMessages)])
        );
    }

    protected function buildEmbedRequest(string|array $input, array $options): HttpRequest {
        return new HttpRequest('POST', 'https://api.test.com/v1/embed');
    }

    protected function buildImageRequest(ImageRequest $request): HttpRequest {
        return new HttpRequest('POST', 'https://api.test.com/v1/images');
    }

    protected function buildStreamChatRequest(array $messages, array $options): HttpRequest {
        return new HttpRequest('POST', 'https://api.test.com/v1/chat');
    }

    protected function doStreamChat(
        HttpRequest $request,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void {
    }

    protected function handleErrorResponse(HttpResponse $response): void {
        if ($response->getStatusCode() >= 400) {
            throw new ProviderException('Provider error', $response->getStatusCode());
        }
    }

    protected function parseChatResponse(HttpResponse $response): ChatResponse {
        $index = $this->responseIndex;
        $this->responseIndex++;

        if ($index < $this->toolIterations) {
            // Request a tool call this round.
            $call = new ToolCall('call_'.$index, 'extract', ['doc' => $index]);

            return new ChatResponse(
                Message::assistant('', [$call]),
                'test-model',
                new Usage(10, 5),
                'tool_calls'
            );
        }

        return new ChatResponse(
            new Message(Role::ASSISTANT, 'Final answer.'),
            'test-model',
            new Usage(10, 5),
            'stop'
        );
    }

    protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
        return new EmbeddingResponse([[0.1, 0.2]], 'test-model');
    }

    protected function parseImageResponse(HttpResponse $response): ImageResponse {
        return new ImageResponse([], 'test-model');
    }

    public function healthCheck(int $timeout = 5): HealthCheckResult {
        return HealthCheckResult::success(1, 'midloop-test');
    }
}
