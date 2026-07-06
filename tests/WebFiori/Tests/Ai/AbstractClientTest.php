<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Usage;

/**
 * Unit tests for AbstractClient using a concrete test implementation.
 *
 * @author Ibrahim
 */
class AbstractClientTest extends TestCase {
    /**
     * @test
     */
    public function testChatLogsRequestAndResponse() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'Hello!',
            'model' => 'test-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ])));

        $provider = $this->createTestProvider(['model' => 'test-model']);
        $provider->setHttpClient($client);

        $logs = [];
        $provider->setLogCallback(function (string $level, string $message, array $context) use (&$logs) {
            $logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });

        $response = $provider->chat([new Message('user', 'Hi')]);

        $this->assertEquals('Hello!', $response->getMessage()->getContent());
        $this->assertEquals('test-model', $response->getModel());
        $this->assertEquals(10, $response->getUsage()->getPromptTokens());
        $this->assertEquals(5, $response->getUsage()->getCompletionTokens());
        $this->assertEquals(15, $response->getUsage()->getTotalTokens());

        // Verify logging occurred
        $infoLogs = array_filter($logs, fn($l) => $l['level'] === 'info');
        $this->assertGreaterThanOrEqual(2, count($infoLogs));
    }

    /**
     * @test
     */
    public function testChatSendsCorrectRequest() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'Response',
            'model' => 'test-model',
            'prompt_tokens' => 5,
            'completion_tokens' => 3,
        ])));

        $provider = $this->createTestProvider(['model' => 'test-model']);
        $provider->setHttpClient($client);

        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hello'),
        ];

        $provider->chat($messages, ['temperature' => 0.5]);

        $lastRequest = $client->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertEquals('POST', $lastRequest->getMethod());

        $body = json_decode($lastRequest->getBody(), true);
        $this->assertEquals('test-model', $body['model']);
        $this->assertEquals(0.5, $body['temperature']);
        $this->assertCount(2, $body['messages']);
    }

    /**
     * @test
     */
    public function testChatWithModelOverride() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'OK',
            'model' => 'override-model',
            'prompt_tokens' => 1,
            'completion_tokens' => 1,
        ])));

        $provider = $this->createTestProvider(['model' => 'default-model']);
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hi')], ['model' => 'override-model']);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals('override-model', $body['model']);
    }

    /**
     * @test
     */
    public function testGetConfig() {
        $provider = $this->createTestProvider([
            'model' => 'gpt-4o',
            'api_key' => 'sk-test',
        ]);

        $this->assertEquals('gpt-4o', $provider->getConfig('model'));
        $this->assertEquals('sk-test', $provider->getConfig('api_key'));
        $this->assertNull($provider->getConfig('missing'));
        $this->assertEquals('default', $provider->getConfig('missing', 'default'));
    }

    /**
     * @test
     */
    public function testHandleAuthenticationError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(401, [], json_encode([
            'error' => 'Invalid API key',
        ])));

        $provider = $this->createTestProvider(['model' => 'test']);
        $provider->setHttpClient($client);

        $this->expectException(AuthenticationException::class);
        $provider->chat([new Message('user', 'Hi')]);
    }

    /**
     * @test
     */
    public function testHandleProviderError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(500, [], json_encode([
            'error' => 'Internal server error',
        ])));

        $provider = $this->createTestProvider(['model' => 'test']);
        $provider->setHttpClient($client);

        $this->expectException(ProviderException::class);
        $provider->chat([new Message('user', 'Hi')]);
    }

    /**
     * @test
     */
    public function testHandleRateLimitError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(429, ['Retry-After' => '30'], json_encode([
            'error' => 'Rate limit exceeded',
        ])));

        $provider = $this->createTestProvider(['model' => 'test']);
        $provider->setHttpClient($client);

        try {
            $provider->chat([new Message('user', 'Hi')]);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertEquals(30, $e->getRetryAfterSeconds());
        }
    }

    /**
     * @test
     */
    public function testSetHttpClient() {
        $provider = $this->createTestProvider(['model' => 'test']);
        $client = new FakeHttpClient();
        $provider->setHttpClient($client);

        $this->assertSame($client, $provider->getHttpClient());
    }

    /**
     * Creates a minimal concrete provider implementation for testing.
     *
     * @param array<string, mixed> $config Provider configuration.
     *
     * @return AbstractClient A concrete test provider instance.
     */
    private function createTestProvider(array $config): AbstractClient {
        return new class($config) extends AbstractClient {
            public function getName(): string {
                return 'test';
            }

            protected function buildChatRequest(array $messages, array $options): HttpRequest {
                $model = $options['model'] ?? $this->getConfig('model');
                $body = [
                    'model' => $model,
                    'messages' => array_map(fn(Message $m) => [
                        'role' => $m->getRole(),
                        'content' => $m->getContent(),
                    ], $messages),
                ];

                if (isset($options['temperature'])) {
                    $body['temperature'] = $options['temperature'];
                }

                return new HttpRequest(
                    'POST',
                    'https://api.test.com/v1/chat',
                    ['Content-Type' => 'application/json'],
                    json_encode($body)
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
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    return;
                }

                if ($status === 401 || $status === 403) {
                    throw new \WebFiori\Ai\Exception\AuthenticationException(
                        'Authentication failed',
                        $status
                    );
                }

                if ($status === 429) {
                    $retryAfter = $response->getHeader('Retry-After');

                    throw new \WebFiori\Ai\Exception\RateLimitException(
                        'Rate limit exceeded',
                        $retryAfter !== null ? (int) $retryAfter : null
                    );
                }

                throw new \WebFiori\Ai\Exception\ProviderException(
                    'Provider error',
                    $status
                );
            }

            protected function parseChatResponse(HttpResponse $response): ChatResponse {
                $data = $response->getJson();

                return new ChatResponse(
                    new Message('assistant', $data['content']),
                    $data['model'],
                    new \WebFiori\Ai\Usage($data['prompt_tokens'], $data['completion_tokens']),
                    'stop'
                );
            }

            protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
                return new EmbeddingResponse([[0.1, 0.2]], 'test-model');
            }

            protected function parseImageResponse(HttpResponse $response): ImageResponse {
                return new ImageResponse([], 'test-model');
            }
        };
    }
}
