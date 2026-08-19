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
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Tests for metrics collection functionality.
 */
class MetricsTest extends TestCase {
    private function makeClient(): OpenAIClient {
        return new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
    }

    private function chatResponse(string $content = 'Hello!'): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]));
    }

    private function embedResponse(): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 5],
        ]));
    }

    // =========================================================================
    // MetricsTrait basic tests
    // =========================================================================

    /**
     * @test
     */
    public function testSetAndGetMetricsCallback() {
        $client = $this->makeClient();

        $this->assertNull($client->getMetricsCallback());

        $callback = function () {};
        $client->setMetricsCallback($callback);

        $this->assertSame($callback, $client->getMetricsCallback());
    }

    /**
     * @test
     */
    public function testSetMetricsCallbackNull() {
        $client = $this->makeClient();
        $client->setMetricsCallback(function () {});
        $client->setMetricsCallback(null);

        $this->assertNull($client->getMetricsCallback());
    }

    /**
     * @test
     */
    public function testNoCallbackNoOp() {
        $client = $this->makeClient();
        // No callback set - should not throw

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);
        $this->assertSame('Hello!', $response->getMessage()->getContent());
    }

    // =========================================================================
    // chat() metrics tests
    // =========================================================================

    /**
     * @test
     */
    public function testChatEmitsRequestSentAndCompleted() {
        $client = $this->makeClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[] = ['event' => $event, 'data' => $data];
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $eventNames = array_column($events, 'event');
        $this->assertContains('request.sent', $eventNames);
        $this->assertContains('request.completed', $eventNames);
    }

    /**
     * @test
     */
    public function testChatRequestSentData() {
        $client = $this->makeClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[$event] = $data;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $sent = $events['request.sent'];
        $this->assertArrayHasKey('timestamp', $sent);
        $this->assertArrayHasKey('request_id', $sent);
        $this->assertArrayHasKey('provider', $sent);
        $this->assertArrayHasKey('model', $sent);
        $this->assertArrayHasKey('endpoint', $sent);
        $this->assertArrayHasKey('method', $sent);
        $this->assertSame('openai', $sent['provider']);
        $this->assertSame('chat', $sent['endpoint']);
        $this->assertSame('POST', $sent['method']);
    }

    /**
     * @test
     */
    public function testChatRequestCompletedData() {
        $client = $this->makeClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[$event] = $data;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $completed = $events['request.completed'];
        $this->assertArrayHasKey('latency_ms', $completed);
        $this->assertArrayHasKey('status_code', $completed);
        $this->assertArrayHasKey('prompt_tokens', $completed);
        $this->assertArrayHasKey('completion_tokens', $completed);
        $this->assertArrayHasKey('total_tokens', $completed);
        $this->assertSame(200, $completed['status_code']);
        $this->assertSame(10, $completed['prompt_tokens']);
        $this->assertSame(5, $completed['completion_tokens']);
    }

    /**
     * @test
     */
    public function testChatEmitsRequestFailedOnError() {
        $client = $this->makeClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[$event] = $data;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(401, [], json_encode([
            'error' => ['message' => 'Invalid API key'],
        ])));
        $client->setHttpClient($fakeHttp);

        try {
            $client->chat([new Message('user', 'Hi')]);
        } catch (\Throwable $e) {
            // Expected
        }

        $this->assertArrayHasKey('request.failed', $events);
        $this->assertArrayHasKey('error_type', $events['request.failed']);
        $this->assertArrayHasKey('error_message', $events['request.failed']);
        $this->assertArrayHasKey('latency_ms', $events['request.failed']);
    }

    /**
     * @test
     */
    public function testChatRequestIdConsistentAcrossEvents() {
        $client = $this->makeClient();
        $requestIds = [];
        $client->setMetricsCallback(function ($event, $data) use (&$requestIds) {
            $requestIds[$event] = $data['request_id'];
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertSame($requestIds['request.sent'], $requestIds['request.completed']);
    }

    /**
     * @test
     */
    public function testChatRequestIdReturnedInResponse() {
        $client = $this->makeClient();
        $capturedId = null;
        $client->setMetricsCallback(function ($event, $data) use (&$capturedId) {
            if ($event === 'request.sent') {
                $capturedId = $data['request_id'];
            }
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);

        $this->assertNotNull($response->getRequestId());
        $this->assertSame($capturedId, $response->getRequestId());
    }

    /**
     * @test
     */
    public function testChatRequestIdFromOptions() {
        $client = $this->makeClient();
        $capturedId = null;
        $client->setMetricsCallback(function ($event, $data) use (&$capturedId) {
            if ($event === 'request.sent') {
                $capturedId = $data['request_id'];
            }
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $myId = 'my-custom-request-123';
        $response = $client->chat([new Message('user', 'Hi')], ['request_id' => $myId]);

        $this->assertSame($myId, $capturedId);
        $this->assertSame($myId, $response->getRequestId());
    }

    /**
     * @test
     */
    public function testChatEmitsCacheHitMiss() {
        $client = $this->makeClient();

        $cache = new \WebFiori\Ai\Cache\InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new \WebFiori\Ai\Cache\CacheConfig(skipCacheAboveTemperature: null));

        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[] = $event;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $messages = [new Message('user', 'Hi')];

        // First call — cache miss
        $client->chat($messages);
        // Second call — cache hit
        $client->chat($messages);

        $this->assertContains('cache.miss', $events);
        $this->assertContains('cache.hit', $events);
    }

    // =========================================================================
    // embed() metrics tests
    // =========================================================================

    /**
     * @test
     */
    public function testEmbedEmitsRequestSentAndCompleted() {
        $client = $this->makeClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[] = ['event' => $event, 'data' => $data];
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->embedResponse());
        $client->setHttpClient($fakeHttp);

        $client->embed('test text');

        $eventNames = array_column($events, 'event');
        $this->assertContains('request.sent', $eventNames);
        $this->assertContains('request.completed', $eventNames);
    }

    /**
     * @test
     */
    public function testEmbedRequestSentEndpoint() {
        $client = $this->makeClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[$event] = $data;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->embedResponse());
        $client->setHttpClient($fakeHttp);

        $client->embed('test');

        $this->assertSame('embeddings', $events['request.sent']['endpoint']);
    }

    /**
     * @test
     */
    public function testEmbedRequestIdReturnedInResponse() {
        $client = $this->makeClient();
        $capturedId = null;
        $client->setMetricsCallback(function ($event, $data) use (&$capturedId) {
            if ($event === 'request.sent') {
                $capturedId = $data['request_id'];
            }
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->embedResponse());
        $client->setHttpClient($fakeHttp);

        $response = $client->embed('test');

        $this->assertNotNull($response->getRequestId());
        $this->assertSame($capturedId, $response->getRequestId());
    }

    // =========================================================================
    // streamChat() metrics tests
    // =========================================================================

    /**
     * @test
     */
    public function testStreamChatEmitsStreamStartedAndCompleted() {
        $client = $this->makeClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[$event] = $data;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":null}]}\n\n",
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"model\":\"gpt-4o\"}\n\n",
            "data: [DONE]\n\n",
        ]);
        $client->setHttpClient($fakeHttp);

        $client->streamChat(
            [new Message('user', 'Hey')],
            function (string $token) {},
            function ($response) {}
        );

        $this->assertArrayHasKey('stream.started', $events);
        $this->assertArrayHasKey('stream.completed', $events);
        $this->assertArrayHasKey('duration_ms', $events['stream.completed']);
        $this->assertArrayHasKey('tokens', $events['stream.completed']);
    }

    /**
     * @test
     */
    public function testStreamChatTokensCounted() {
        $client = $this->makeClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[$event] = $data;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\" World\"},\"finish_reason\":null}]}\n\n",
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"model\":\"gpt-4o\"}\n\n",
            "data: [DONE]\n\n",
        ]);
        $client->setHttpClient($fakeHttp);

        $client->streamChat(
            [new Message('user', 'Hey')],
            function (string $token) {},
            function ($response) {}
        );

        // 2 tokens emitted
        $this->assertSame(2, $events['stream.completed']['tokens']);
    }

    /**
     * @test
     */
    public function testStreamChatRequestIdConsistent() {
        $client = $this->makeClient();
        $ids = [];
        $client->setMetricsCallback(function ($event, $data) use (&$ids) {
            $ids[$event] = $data['request_id'];
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":null}]}\n\n",
            "data: [DONE]\n\n",
        ]);
        $client->setHttpClient($fakeHttp);

        $client->streamChat(
            [new Message('user', 'Hey')],
            function (string $token) {},
            function ($response) {}
        );

        $this->assertSame($ids['stream.started'], $ids['stream.completed']);
    }

    // =========================================================================
    // Response DTO request ID tests
    // =========================================================================

    /**
     * @test
     */
    public function testChatResponseGetRequestId() {
        $msg = new Message('assistant', 'Hello');
        $response = new \WebFiori\Ai\ChatResponse($msg, 'gpt-4o', null, 'stop', 'req_abc123');

        $this->assertSame('req_abc123', $response->getRequestId());
    }

    /**
     * @test
     */
    public function testChatResponseRequestIdNullByDefault() {
        $msg = new Message('assistant', 'Hello');
        $response = new \WebFiori\Ai\ChatResponse($msg, 'gpt-4o');

        $this->assertNull($response->getRequestId());
    }

    /**
     * @test
     */
    public function testEmbeddingResponseGetRequestId() {
        $response = new \WebFiori\Ai\EmbeddingResponse([[0.1]], 'text-embedding-3-small', null, 'req_xyz');

        $this->assertSame('req_xyz', $response->getRequestId());
    }

    /**
     * @test
     */
    public function testImageResponseGetRequestId() {
        $response = new \WebFiori\Ai\ImageResponse([], 'dall-e-3', 'req_img');

        $this->assertSame('req_img', $response->getRequestId());
    }

    /**
     * @test
     */
    public function testMetricsTimestampIsMilliseconds() {
        $client = $this->makeClient();
        $timestamps = [];
        $client->setMetricsCallback(function ($event, $data) use (&$timestamps) {
            $timestamps[] = $data['timestamp'];
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        foreach ($timestamps as $ts) {
            // Timestamp should be in milliseconds (13 digits in 2026)
            $this->assertGreaterThan(1_000_000_000_000, $ts);
        }
    }
}
