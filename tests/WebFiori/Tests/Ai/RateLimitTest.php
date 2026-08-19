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
use WebFiori\Ai\Http\RateLimitAwareHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Behavioral tests for rate limit header tracking.
 *
 * @author Ibrahim
 */
class RateLimitTest extends TestCase {
    /**
     * @test
     * Warning threshold must be configurable — a higher threshold warns earlier.
     */
    public function testCustomWarningThresholdIsRespected() {
        $inner = new FakeHttpClient();
        $inner->addResponse($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-requests' => '100',
            'x-ratelimit-remaining-requests' => '25', // 25%
        ]));

        $logEntries = [];
        // Set threshold to 0.3 (30%) — should warn at 25%
        $rateLimitClient = new RateLimitAwareHttpClient(
            $inner,
            0.3,
            function (string $level, string $message, array $context) use (&$logEntries)
            {
                $logEntries[] = $level;
            }
        );

        $provider = $this->createProviderWithClient($inner);
        $provider->setHttpClient($rateLimitClient);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(1, $logEntries);
        $this->assertEquals('warning', $logEntries[0]);
    }

    /**
     * @test
     * Calling enableRateLimitTracking twice must not double-wrap the inner client.
     */
    public function testEnableTrackingDoesNotDoubleWrap() {
        $inner = new FakeHttpClient();
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        $provider->setHttpClient($inner);

        $provider->enableRateLimitTracking();
        $provider->enableRateLimitTracking();

        $http = $provider->getHttpClient();
        $this->assertInstanceOf(RateLimitAwareHttpClient::class, $http);
        $this->assertNotInstanceOf(RateLimitAwareHttpClient::class, $http->getInner());
        $this->assertSame($inner, $http->getInner());
    }

    // -----------------------------------------------------------------------
    // isExhausted
    // -----------------------------------------------------------------------

    /**
     * @test
     * isExhausted must return true when remaining requests hits zero.
     */
    public function testIsExhaustedWhenRemainingRequestsIsZero() {
        $provider = $this->createProvider($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-requests' => '500',
            'x-ratelimit-remaining-requests' => '0',
        ]));

        $provider->chat([new Message('user', 'Hello')]);

        $this->assertTrue($provider->getRateLimitStatus()->isExhausted());
    }

    /**
     * @test
     * isExhausted must return true when remaining tokens hits zero.
     */
    public function testIsExhaustedWhenRemainingTokensIsZero() {
        $provider = $this->createProvider($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-tokens' => '150000',
            'x-ratelimit-remaining-tokens' => '0',
        ]));

        $provider->chat([new Message('user', 'Hello')]);

        $this->assertTrue($provider->getRateLimitStatus()->isExhausted());
    }

    /**
     * @test
     * isExhausted must return false when capacity remains.
     */
    public function testIsNotExhaustedWithRemainingCapacity() {
        $provider = $this->createProvider($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-requests' => '500',
            'x-ratelimit-remaining-requests' => '1',
        ]));

        $provider->chat([new Message('user', 'Hello')]);

        $this->assertFalse($provider->getRateLimitStatus()->isExhausted());
    }

    // -----------------------------------------------------------------------
    // Warning threshold
    // -----------------------------------------------------------------------

    /**
     * @test
     * A warning must be logged when remaining requests fraction drops below threshold.
     */
    public function testLogsWarningWhenBelowThreshold() {
        $inner = new FakeHttpClient();
        $inner->addResponse($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-requests' => '100',
            'x-ratelimit-remaining-requests' => '5', // 5% — below 10% threshold
        ]));

        $logEntries = [];
        $rateLimitClient = new RateLimitAwareHttpClient(
            $inner,
            0.1,
            function (string $level, string $message, array $context) use (&$logEntries)
            {
                $logEntries[] = compact('level', 'message', 'context');
            }
        );

        $provider = $this->createProviderWithClient($inner);
        $provider->setHttpClient($rateLimitClient);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(1, $logEntries);
        $this->assertEquals('warning', $logEntries[0]['level']);
        $this->assertEquals(5, $logEntries[0]['context']['remaining_requests']);
        $this->assertEquals(0.05, $logEntries[0]['context']['remaining_fraction']);
    }

    /**
     * @test
     * No warning must be logged when remaining capacity is above threshold.
     */
    public function testNoWarningWhenAboveThreshold() {
        $inner = new FakeHttpClient();
        $inner->addResponse($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-requests' => '100',
            'x-ratelimit-remaining-requests' => '50', // 50% — well above threshold
        ]));

        $logEntries = [];
        $rateLimitClient = new RateLimitAwareHttpClient(
            $inner,
            0.1,
            function (string $level, string $message, array $context) use (&$logEntries)
            {
                $logEntries[] = compact('level', 'message', 'context');
            }
        );

        $provider = $this->createProviderWithClient($inner);
        $provider->setHttpClient($rateLimitClient);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(0, $logEntries);
    }

    /**
     * @test
     * Anthropic rate limit headers must be parsed correctly.
     */
    public function testParsesAnthropicRateLimitHeaders() {
        $provider = $this->createProvider($this->openAiSuccessWithHeaders([
            'anthropic-ratelimit-requests-limit' => '1000',
            'anthropic-ratelimit-requests-remaining' => '998',
            'anthropic-ratelimit-tokens-limit' => '80000',
            'anthropic-ratelimit-tokens-remaining' => '79500',
        ]));

        $provider->chat([new Message('user', 'Hello')]);
        $status = $provider->getRateLimitStatus();

        $this->assertNotNull($status);
        $this->assertEquals(998, $status->getRemainingRequests());
        $this->assertEquals(79500, $status->getRemainingTokens());
        $this->assertEquals(1000, $status->getLimitRequests());
        $this->assertEquals(80000, $status->getLimitTokens());
    }

    /**
     * @test
     * Generic x-ratelimit-remaining header is also supported.
     */
    public function testParsesGenericRateLimitHeader() {
        $provider = $this->createProvider($this->openAiSuccessWithHeaders([
            'x-ratelimit-remaining' => '42',
        ]));

        $provider->chat([new Message('user', 'Hello')]);
        $status = $provider->getRateLimitStatus();

        $this->assertNotNull($status);
        $this->assertEquals(42, $status->getRemainingRequests());
    }
    // -----------------------------------------------------------------------
    // Status parsing — OpenAI headers
    // -----------------------------------------------------------------------

    /**
     * @test
     * OpenAI rate limit headers must be parsed into a RateLimitStatus.
     */
    public function testParsesOpenAiRateLimitHeaders() {
        $provider = $this->createProvider($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-requests' => '500',
            'x-ratelimit-remaining-requests' => '487',
            'x-ratelimit-limit-tokens' => '150000',
            'x-ratelimit-remaining-tokens' => '149820',
            'x-ratelimit-reset-requests' => '1m2s',
            'x-ratelimit-reset-tokens' => '0.5s',
        ]));

        $provider->chat([new Message('user', 'Hello')]);
        $status = $provider->getRateLimitStatus();

        $this->assertNotNull($status);
        $this->assertEquals(487, $status->getRemainingRequests());
        $this->assertEquals(149820, $status->getRemainingTokens());
        $this->assertEquals(500, $status->getLimitRequests());
        $this->assertEquals(150000, $status->getLimitTokens());
        $this->assertNotNull($status->getRequestsResetsAt());
        $this->assertNotNull($status->getTokensResetsAt());
    }

    // -----------------------------------------------------------------------
    // Reset time parsing
    // -----------------------------------------------------------------------

    /**
     * @test
     * OpenAI-style relative duration "1m30s" must be parsed to a future datetime.
     */
    public function testParsesRelativeDurationToFutureTime() {
        $provider = $this->createProvider($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-requests' => '100',
            'x-ratelimit-remaining-requests' => '50',
            'x-ratelimit-reset-requests' => '1m30s',
        ]));

        $before = new \DateTimeImmutable();
        $provider->chat([new Message('user', 'Hello')]);
        $after = new \DateTimeImmutable();

        $resetsAt = $provider->getRateLimitStatus()->getRequestsResetsAt();

        $this->assertNotNull($resetsAt);
        // Reset time must be in the future (approximately now + 90s)
        $this->assertGreaterThan($before->getTimestamp(), $resetsAt->getTimestamp());
        // Must be within 95 seconds of now (90s + some slack)
        $this->assertLessThanOrEqual($after->getTimestamp() + 95, $resetsAt->getTimestamp());
    }

    /**
     * @test
     * RFC3339 reset time header must be parsed to the exact datetime.
     */
    public function testParsesRfc3339ResetTime() {
        $resetTime = '2026-08-01T12:30:00+00:00';

        $provider = $this->createProvider($this->openAiSuccessWithHeaders([
            'x-ratelimit-limit-requests' => '100',
            'x-ratelimit-remaining-requests' => '80',
            'x-ratelimit-reset-requests' => $resetTime,
        ]));

        $provider->chat([new Message('user', 'Hello')]);
        $resetsAt = $provider->getRateLimitStatus()->getRequestsResetsAt();

        $this->assertNotNull($resetsAt);
        $this->assertEquals(
            (new \DateTimeImmutable($resetTime))->getTimestamp(),
            $resetsAt->getTimestamp()
        );
    }

    /**
     * @test
     * When a response has no rate limit headers, status must remain null.
     */
    public function testStatusIsNullWhenNoRateLimitHeaders() {
        $provider = $this->createProvider($this->openAiSuccessWithHeaders([]));

        $provider->chat([new Message('user', 'Hello')]);

        $this->assertNull($provider->getRateLimitStatus());
    }

    // -----------------------------------------------------------------------
    // enableRateLimitTracking integration
    // -----------------------------------------------------------------------

    /**
     * @test
     * getRateLimitStatus must return null when tracking is not enabled.
     */
    public function testStatusIsNullWhenTrackingNotEnabled() {
        $inner = new FakeHttpClient();
        $inner->addResponse($this->openAiSuccessWithHeaders([
            'x-ratelimit-remaining-requests' => '100',
        ]));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        $provider->setHttpClient($inner);

        $provider->chat([new Message('user', 'Hello')]);

        // No tracking enabled — must return null even with headers present
        $this->assertNull($provider->getRateLimitStatus());
    }

    /**
     * @test
     * Status is updated after each request — reflects the most recent response.
     */
    public function testStatusUpdatesAfterEachRequest() {
        $inner = new FakeHttpClient();
        $inner->addResponse($this->openAiSuccessWithHeaders([
            'x-ratelimit-remaining-requests' => '100',
        ]));
        $inner->addResponse($this->openAiSuccessWithHeaders([
            'x-ratelimit-remaining-requests' => '99',
        ]));

        $provider = $this->createProviderWithClient($inner);

        $provider->chat([new Message('user', 'First')]);
        $this->assertEquals(100, $provider->getRateLimitStatus()->getRemainingRequests());

        $provider->chat([new Message('user', 'Second')]);
        $this->assertEquals(99, $provider->getRateLimitStatus()->getRemainingRequests());
    }

    private function createProvider(HttpResponse $response): OpenAIClient {
        $inner = new FakeHttpClient();
        $inner->addResponse($response);

        return $this->createProviderWithClient($inner);
    }

    private function createProviderWithClient(FakeHttpClient $inner): OpenAIClient {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        $provider->setHttpClient(new RateLimitAwareHttpClient($inner));

        return $provider;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function openAiSuccessWithHeaders(array $headers): HttpResponse {
        return new HttpResponse(200, $headers, json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ]));
    }
}
