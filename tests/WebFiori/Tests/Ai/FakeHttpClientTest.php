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
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;

/**
 * Unit tests for the FakeHttpClient class.
 *
 * @author Ibrahim
 */
class FakeHttpClientTest extends TestCase {
    /**
     * @test
     */
    public function testGetLastRequestEmpty() {
        $client = new FakeHttpClient();

        $this->assertNull($client->getLastRequest());
    }

    /**
     * @test
     */
    public function testMultipleResponses() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], '{"id":1}'));
        $client->addResponse(new HttpResponse(201, [], '{"id":2}'));

        $request1 = new HttpRequest('GET', 'https://api.example.com/first');
        $request2 = new HttpRequest('POST', 'https://api.example.com/second');

        $response1 = $client->send($request1);
        $response2 = $client->send($request2);

        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(201, $response2->getStatusCode());
        $this->assertCount(2, $client->getRequests());
    }

    /**
     * @test
     */
    public function testReset() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200));
        $client->send(new HttpRequest('GET', 'https://example.com'));

        $client->reset();

        $this->assertEmpty($client->getRequests());
        $this->assertNull($client->getLastRequest());
    }

    /**
     * @test
     */
    public function testSendRecordsRequest() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], '{"ok":true}'));

        $request = new HttpRequest('POST', 'https://api.openai.com/v1/chat/completions', [
            'Authorization' => 'Bearer sk-test',
            'Content-Type' => 'application/json',
        ], '{"model":"gpt-4o"}');

        $response = $client->send($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"ok":true}', $response->getBody());

        $lastRequest = $client->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertEquals('POST', $lastRequest->getMethod());
        $this->assertEquals('https://api.openai.com/v1/chat/completions', $lastRequest->getUrl());
        $this->assertEquals('Bearer sk-test', $lastRequest->getHeader('Authorization'));
        $this->assertEquals('{"model":"gpt-4o"}', $lastRequest->getBody());
    }

    /**
     * @test
     */
    public function testSendWithoutQueuedResponseThrows() {
        $client = new FakeHttpClient();
        $request = new HttpRequest('GET', 'https://example.com');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No responses queued');
        $client->send($request);
    }

    /**
     * @test
     */
    public function testStreamingChunks() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: {\"token\":\"Hello\"}\n\n",
            "data: {\"token\":\" world\"}\n\n",
            "data: [DONE]\n\n",
        ]);

        $request = new HttpRequest('POST', 'https://api.openai.com/v1/chat/completions');
        $received = [];

        $client->sendStreaming($request, function (string $chunk) use (&$received) {
            $received[] = $chunk;
        });

        $this->assertCount(3, $received);
        $this->assertEquals("data: {\"token\":\"Hello\"}\n\n", $received[0]);
        $this->assertEquals("data: {\"token\":\" world\"}\n\n", $received[1]);
        $this->assertEquals("data: [DONE]\n\n", $received[2]);

        $this->assertNotNull($client->getLastRequest());
        $this->assertEquals('POST', $client->getLastRequest()->getMethod());
    }

    /**
     * @test
     */
    public function testStreamingWithoutQueuedChunksThrows() {
        $client = new FakeHttpClient();
        $request = new HttpRequest('POST', 'https://example.com');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No streaming chunks queued');
        $client->sendStreaming($request, function (string $chunk) {});
    }
}
