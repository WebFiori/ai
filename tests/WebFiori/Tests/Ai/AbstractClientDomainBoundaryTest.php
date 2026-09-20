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
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Provider\ClientConfig;
use WebFiori\Ai\Tool\DomainBoundaries;
use WebFiori\Ai\Usage;

/**
 * Tests for the optional domain-boundary short-circuit in AbstractClient::chat().
 *
 * @author Ibrahim
 */
class AbstractClientDomainBoundaryTest extends TestCase {
    public function testChat_OffTopic_ReturnsSyntheticResponseWithoutHttpCall(): void {
        // No HTTP responses queued: any real request would throw, proving the
        // boundary short-circuited before the provider was called.
        $provider = $this->createProvider();
        $provider->setHttpClient(new FakeHttpClient());

        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather|lyrics'],
            redirectTemplate: "That's outside my focus.",
        );

        $response = $provider->chat(
            [new Message('user', 'What is the weather tomorrow?')],
            [ChatOption::DOMAIN_BOUNDARIES => $boundaries]
        );

        $this->assertSame("That's outside my focus.", $response->getMessage()->getContent());
        $this->assertSame('domain_boundary', $response->getFinishReason());
        $this->assertNotNull($response->getUsage());
        $this->assertSame(0, $response->getUsage()->getTotalTokens());
    }

    public function testChat_InScope_ProceedsToProvider(): void {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'Sales were up 12%.',
            'model' => 'test-model',
            'prompt_tokens' => 8,
            'completion_tokens' => 4,
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather|lyrics'],
            redirectTemplate: "That's outside my focus.",
        );

        $response = $provider->chat(
            [new Message('user', 'What were our sales last quarter?')],
            [ChatOption::DOMAIN_BOUNDARIES => $boundaries]
        );

        $this->assertSame('Sales were up 12%.', $response->getMessage()->getContent());
        $this->assertNotSame('domain_boundary', $response->getFinishReason());
    }

    public function testChat_EmitsBlockedAndAllowedMetrics(): void {
        $events = [];
        $provider = $this->createProvider();
        $provider->setHttpClient(new FakeHttpClient());
        $provider->setMetricsCallback(function (string $event, array $data) use (&$events) {
            $events[$event][] = $data;
        });

        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather'],
            redirectTemplate: 'Redirect.',
        );

        // Blocked path.
        $provider->chat(
            [new Message('user', 'the weather')],
            [ChatOption::DOMAIN_BOUNDARIES => $boundaries]
        );

        $this->assertArrayHasKey('boundary.blocked', $events);
        $this->assertSame('blocked', $events['boundary.blocked'][0]['reason']);
        $this->assertSame('weather', $events['boundary.blocked'][0]['matched_pattern']);
    }

    public function testChat_AllowedMetricEmittedOnPassThrough(): void {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'Answer.',
            'model' => 'test-model',
            'prompt_tokens' => 1,
            'completion_tokens' => 1,
        ])));

        $events = [];
        $provider = $this->createProvider();
        $provider->setHttpClient($client);
        $provider->setMetricsCallback(function (string $event, array $data) use (&$events) {
            $events[$event][] = $data;
        });

        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather'],
            redirectTemplate: 'Redirect.',
        );

        $provider->chat(
            [new Message('user', 'our revenue')],
            [ChatOption::DOMAIN_BOUNDARIES => $boundaries]
        );

        $this->assertArrayHasKey('boundary.allowed', $events);
        $this->assertSame('in_scope', $events['boundary.allowed'][0]['reason']);
    }

    public function testChat_NoBoundaryOption_ProceedsNormally(): void {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'Answer.',
            'model' => 'test-model',
            'prompt_tokens' => 1,
            'completion_tokens' => 1,
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'the weather')]);

        $this->assertSame('Answer.', $response->getMessage()->getContent());
    }

    private function createProvider(): AbstractClient {
        $config = new class(['model' => 'test-model']) extends ClientConfig {
            public function __construct(private array $data) {
                parent::__construct($data['model'] ?? 'test-model');
            }

            public function toArray(): array {
                return $this->data;
            }
        };

        return new class($config) extends AbstractClient {
            public function getName(): string {
                return 'test';
            }

            public function healthCheck(int $timeout = 5): \WebFiori\Ai\HealthCheckResult {
                return \WebFiori\Ai\HealthCheckResult::success(1, 'test');
            }

            protected function buildChatRequest(array $messages, array $options): HttpRequest {
                return new HttpRequest(
                    'POST',
                    'https://api.test.com/v1/chat',
                    ['Content-Type' => 'application/json'],
                    json_encode(['model' => $options['model'] ?? 'test-model'])
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
                    throw new \WebFiori\Ai\Exception\ProviderException('Provider error', $response->getStatusCode());
                }
            }

            protected function parseChatResponse(HttpResponse $response): ChatResponse {
                $data = $response->getJson();

                return new ChatResponse(
                    new Message('assistant', $data['content']),
                    $data['model'],
                    new Usage($data['prompt_tokens'], $data['completion_tokens']),
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
