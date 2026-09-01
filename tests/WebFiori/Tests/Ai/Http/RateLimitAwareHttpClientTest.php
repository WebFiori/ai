<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Http;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\RateLimitAwareHttpClient;

/**
 * Tests for RateLimitAwareHttpClient — header parsing, status exposure, and
 * the low-capacity warning callback. Fully offline via FakeHttpClient.
 */
class RateLimitAwareHttpClientTest extends TestCase {
    private function request(): HttpRequest {
        return new HttpRequest('POST', 'https://api.example.com/v1/chat');
    }

    public function testSend_ParsesRateLimitHeaders(): void {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(200, [
            'x-ratelimit-remaining-requests' => '80',
            'x-ratelimit-limit-requests' => '100',
        ], '{}'));

        $client = new RateLimitAwareHttpClient($inner);
        $client->send($this->request());

        $status = $client->getLastStatus();
        $this->assertNotNull($status);
        $this->assertSame(80, $status->getRemainingRequests());
        $this->assertSame(100, $status->getLimitRequests());
    }

    public function testGetInner_ReturnsDecoratedClient(): void {
        $inner = new FakeHttpClient();
        $client = new RateLimitAwareHttpClient($inner);
        $this->assertSame($inner, $client->getInner());
    }

    public function testGetLastStatus_NullBeforeAnyRequest(): void {
        $client = new RateLimitAwareHttpClient(new FakeHttpClient());
        $this->assertNull($client->getLastStatus());
    }

    public function testSend_NoRateLimitHeadersLeavesStatusNull(): void {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(200, [], '{}'));

        $client = new RateLimitAwareHttpClient($inner);
        $client->send($this->request());

        $this->assertNull($client->getLastStatus());
    }

    public function testSend_TriggersWarningWhenCapacityLow(): void {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(200, [
            'x-ratelimit-remaining-requests' => '2',
            'x-ratelimit-limit-requests' => '100',
        ], '{}'));

        $logs = [];
        $client = new RateLimitAwareHttpClient(
            $inner,
            warningThreshold: 0.1,
            logCallback: function (string $level, string $message, array $context) use (&$logs): void {
                $logs[] = [$level, $message, $context];
            }
        );

        $client->send($this->request());

        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0][0]);
        $this->assertStringContainsStringIgnoringCase('rate limit', $logs[0][1]);
        $this->assertSame(2, $logs[0][2]['remaining_requests']);
    }

    public function testSend_NoWarningWhenCapacityHealthy(): void {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(200, [
            'x-ratelimit-remaining-requests' => '90',
            'x-ratelimit-limit-requests' => '100',
        ], '{}'));

        $logs = [];
        $client = new RateLimitAwareHttpClient(
            $inner,
            warningThreshold: 0.1,
            logCallback: function (string $level, string $message, array $context) use (&$logs): void {
                $logs[] = $level;
            }
        );

        $client->send($this->request());

        $this->assertSame([], $logs);
    }

    public function testSendStreaming_DelegatesToInner(): void {
        $inner = new FakeHttpClient();
        $inner->addStreamingChunks(['a', 'b', 'c']);

        $client = new RateLimitAwareHttpClient($inner);

        $received = '';
        $client->sendStreaming($this->request(), function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame('abc', $received);
    }
}
