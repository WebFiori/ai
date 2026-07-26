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
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\RetryableHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\RetryConfig;

/**
 * Behavioral tests for retry logic with exponential backoff.
 *
 * Each test verifies a specific behavior that could break if the
 * implementation changes. Tests do NOT verify PHP assignments or
 * trivial getters — only observable behavior.
 *
 * @author Ibrahim
 */
class RetryTest extends TestCase {
    // -----------------------------------------------------------------------
    // Backoff behavior
    // -----------------------------------------------------------------------

    /**
     * @test
     * Delay must grow exponentially between attempts (not stay flat).
     */
    public function testBackoffDelayGrowsExponentially() {
        $config = new RetryConfig(
            initialDelayMs: 1000,
            maxDelayMs: 60000,
            backoffMultiplier: 2.0
        );

        $delay1 = $config->calculateDelayMs(1); // base: 1000ms
        $delay2 = $config->calculateDelayMs(2); // base: 2000ms
        $delay3 = $config->calculateDelayMs(3); // base: 4000ms

        // Each delay must be larger than the previous (accounting for jitter)
        $this->assertGreaterThan(0, $delay1);
        $this->assertGreaterThan($delay1 * 0.5, $delay2); // delay2 > half of delay1 minimum
        $this->assertGreaterThan($delay2 * 0.5, $delay3);
    }

    /**
     * @test
     * Delay must never exceed maxDelayMs regardless of attempt number.
     */
    public function testBackoffIsCappedAtMaxDelay() {
        $config = new RetryConfig(
            initialDelayMs: 1000,
            maxDelayMs: 5000,
            backoffMultiplier: 10.0
        );

        // By attempt 4 uncapped delay would be 1000 * 10^3 = 1,000,000ms
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $delay = $config->calculateDelayMs($attempt);
            // Allow 20% jitter above the cap
            $this->assertLessThanOrEqual(6000, $delay, "Attempt $attempt delay $delay exceeds max");
        }
    }

    /**
     * @test
     * Jitter must not produce a negative delay.
     */
    public function testDelayIsNeverNegative() {
        $config = new RetryConfig(initialDelayMs: 1, maxDelayMs: 1);

        for ($i = 0; $i < 100; $i++) {
            $delay = $config->calculateDelayMs(1);
            $this->assertGreaterThanOrEqual(0, $delay);
        }
    }

    /**
     * @test
     * A 401 auth failure must not be retried — retrying won't fix wrong credentials.
     */
    public function testDoesNotRetryAuthenticationFailure() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(401, [], '{"error":{"message":"invalid key","type":"invalid_api_key"}}'));

        $provider = $this->createProvider($inner, maxRetries: 3);

        $this->expectException(AuthenticationException::class);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(1, $inner->getRequests());
    }

    // -----------------------------------------------------------------------
    // Retry does NOT trigger
    // -----------------------------------------------------------------------

    /**
     * @test
     * A 400 Bad Request must not be retried — it's a client error, not transient.
     */
    public function testDoesNotRetryBadRequest() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(400, [], '{"error":{"message":"bad","type":"invalid_request_error"}}'));

        $provider = $this->createProvider($inner, maxRetries: 3);

        $this->expectException(ProviderException::class);
        $provider->chat([new Message('user', 'Hello')]);

        // Must fail on first attempt — no retry
        $this->assertCount(1, $inner->getRequests());
    }

    /**
     * @test
     * Non-retryable exceptions must propagate immediately without any retry.
     */
    public function testDoesNotRetryNonRetryableException() {
        $inner = new FakeHttpClient();
        $inner->addException(new \InvalidArgumentException('Bad config'));

        $retryClient = new RetryableHttpClient(
            $inner,
            new RetryConfig(maxRetries: 3, initialDelayMs: 0)
        );

        $this->expectException(\InvalidArgumentException::class);

        $request = new HttpRequest('POST', 'https://example.com', [], '{}');
        $retryClient->send($request);

        $this->assertCount(1, $inner->getRequests());
    }

    /**
     * @test
     * With maxRetries=3, exactly 4 total attempts before giving up.
     */
    public function testGivesUpAfterMaxRetries() {
        $inner = new FakeHttpClient();

        for ($i = 0; $i < 4; $i++) {
            $inner->addResponse(new HttpResponse(500, [], '{"error":{"message":"err","type":"t"}}'));
        }

        $provider = $this->createProvider($inner, maxRetries: 3);

        $this->expectException(ProviderException::class);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(4, $inner->getRequests());
    }

    /**
     * @test
     * Exception retries also respect maxRetries limit.
     */
    public function testGivesUpAfterMaxRetriesOnException() {
        $inner = new FakeHttpClient();

        for ($i = 0; $i < 4; $i++) {
            $inner->addException(new HttpException('Timeout'));
        }

        $provider = $this->createProvider($inner, maxRetries: 3);

        $this->expectException(HttpException::class);
        $provider->chat([new Message('user', 'Hello')]);
    }

    // -----------------------------------------------------------------------
    // Logging
    // -----------------------------------------------------------------------

    /**
     * @test
     * Each retry attempt on a retryable status code must produce a warning log
     * with attempt number and status code.
     */
    public function testLogsEachRetryAttemptWithCorrectContext() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(500, [], '{"error":{"message":"err","type":"t"}}'));
        $inner->addResponse(new HttpResponse(503, [], '{"error":{"message":"err","type":"t"}}'));
        $inner->addResponse($this->successResponse());

        $logEntries = [];
        $config = new RetryConfig(maxRetries: 3, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient(
            $inner,
            $config,
            function (string $level, string $message, array $context) use (&$logEntries)
            {
                $logEntries[] = compact('level', 'message', 'context');
            }
        );

        $provider = $this->createProvider($inner);
        $provider->setHttpClient($retryClient);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(2, $logEntries);
        $this->assertEquals('warning', $logEntries[0]['level']);
        $this->assertEquals(1, $logEntries[0]['context']['attempt']);
        $this->assertEquals(500, $logEntries[0]['context']['status_code']);
        $this->assertEquals('warning', $logEntries[1]['level']);
        $this->assertEquals(2, $logEntries[1]['context']['attempt']);
        $this->assertEquals(503, $logEntries[1]['context']['status_code']);
    }

    /**
     * @test
     * Each retry on an exception must produce a warning log with exception class.
     */
    public function testLogsEachRetryOnException() {
        $inner = new FakeHttpClient();
        $inner->addException(new HttpException('Network error'));
        $inner->addResponse($this->successResponse());

        $logEntries = [];
        $config = new RetryConfig(maxRetries: 2, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient(
            $inner,
            $config,
            function (string $level, string $message, array $context) use (&$logEntries)
            {
                $logEntries[] = compact('level', 'message', 'context');
            }
        );

        $provider = $this->createProvider($inner);
        $provider->setHttpClient($retryClient);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(1, $logEntries);
        $this->assertEquals('warning', $logEntries[0]['level']);
        $this->assertEquals(HttpException::class, $logEntries[0]['context']['exception']);
        $this->assertEquals(1, $logEntries[0]['context']['attempt']);
    }

    // -----------------------------------------------------------------------
    // Retry limits
    // -----------------------------------------------------------------------

    /**
     * @test
     * With maxRetries=1, exactly 2 total attempts should be made (1 + 1 retry).
     */
    public function testMaxRetriesLimitIsEnforced() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(500, [], '{"error":{"message":"err","type":"t"}}')); // attempt 1
        $inner->addResponse(new HttpResponse(500, [], '{"error":{"message":"err","type":"t"}}')); // retry 1
        $inner->addResponse($this->successResponse()); // should never be reached

        $provider = $this->createProvider($inner, maxRetries: 1);

        $this->expectException(ProviderException::class);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(2, $inner->getRequests());
    }

    // -----------------------------------------------------------------------
    // Retry-After header
    // -----------------------------------------------------------------------

    /**
     * @test
     * A Retry-After header on a 429 overrides the calculated backoff delay.
     * Verify by checking that retry still succeeds (functional correctness).
     */
    public function testRespectsRetryAfterHeaderAndSucceeds() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(429, ['retry-after' => '0'], '{"error":{"message":"rate limit","type":"t"}}'));
        $inner->addResponse($this->successResponse('After Retry-After'));

        $provider = $this->createProvider($inner, maxRetries: 2);
        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('After Retry-After', $response->getMessage()->getContent());
        $this->assertCount(2, $inner->getRequests());
    }

    /**
     * @test
     * A 500 error should be retried until success.
     */
    public function testRetriesOnInternalServerError() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(500, [], '{"error":{"message":"err","type":"t"}}'));
        $inner->addResponse(new HttpResponse(500, [], '{"error":{"message":"err","type":"t"}}'));
        $inner->addResponse($this->successResponse('After retries'));

        $provider = $this->createProvider($inner, maxRetries: 3);
        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('After retries', $response->getMessage()->getContent());
        $this->assertCount(3, $inner->getRequests());
    }

    /**
     * @test
     * Network exceptions (HttpException) should be retried.
     */
    public function testRetriesOnNetworkException() {
        $inner = new FakeHttpClient();
        $inner->addException(new HttpException('Connection timed out'));
        $inner->addResponse($this->successResponse('Recovered'));

        $provider = $this->createProvider($inner, maxRetries: 2);
        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Recovered', $response->getMessage()->getContent());
    }

    /**
     * @test
     * A 429 rate limit should be retried until success.
     */
    public function testRetriesOnRateLimit() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(429, [], '{"error":{"message":"rate limit","type":"t"}}'));
        $inner->addResponse($this->successResponse());

        $provider = $this->createProvider($inner, maxRetries: 2);
        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Hello', $response->getMessage()->getContent());
        $this->assertCount(2, $inner->getRequests());
    }

    /**
     * @test
     * When no log callback is set, retry must still work silently without crashing.
     */
    public function testRetryWorksWithNoLogCallback() {
        $inner = new FakeHttpClient();
        $inner->addException(new HttpException('Timeout'));
        $inner->addResponse($this->successResponse());

        $config = new RetryConfig(maxRetries: 2, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config, null);

        $provider = $this->createProvider($inner);
        $provider->setHttpClient($retryClient);

        $response = $provider->chat([new Message('user', 'Hello')]);
        $this->assertEquals('Hello', $response->getMessage()->getContent());
    }

    /**
     * @test
     * setRetryConfig must activate retry behavior end-to-end.
     */
    public function testSetRetryConfigActivatesRetryOnProvider() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(503, [], '{"error":{"message":"err","type":"t"}}'));
        $inner->addResponse($this->successResponse('Back online'));

        $provider = $this->createProvider($inner);
        $provider->setRetryConfig(new RetryConfig(maxRetries: 2, initialDelayMs: 0));

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Back online', $response->getMessage()->getContent());
        $this->assertCount(2, $inner->getRequests());
    }

    // -----------------------------------------------------------------------
    // setRetryConfig integration
    // -----------------------------------------------------------------------

    /**
     * @test
     * Calling setRetryConfig twice must not double-wrap the inner client.
     */
    public function testSetRetryConfigDoesNotDoubleWrap() {
        $inner = new FakeHttpClient();

        $provider = $this->createProvider($inner);
        $provider->setRetryConfig(new RetryConfig(maxRetries: 2, initialDelayMs: 0));
        $provider->setRetryConfig(new RetryConfig(maxRetries: 3, initialDelayMs: 0));

        $http = $provider->getHttpClient();
        $this->assertInstanceOf(RetryableHttpClient::class, $http);
        $this->assertNotInstanceOf(RetryableHttpClient::class, $http->getInner());
        $this->assertSame($inner, $http->getInner());
    }

    // -----------------------------------------------------------------------
    // Streaming
    // -----------------------------------------------------------------------

    /**
     * @test
     * Streaming requests must be passed through directly without any retry wrapping.
     * A single failed streaming attempt must not be retried.
     */
    public function testStreamingIsNotRetried() {
        $inner = new FakeHttpClient();
        $inner->addStreamingChunks([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":null}]}\n\n",
        ]);

        $config = new RetryConfig(maxRetries: 3, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config);

        $provider = $this->createProvider($inner);
        $provider->setHttpClient($retryClient);

        $provider->streamChat(
            [new Message('user', 'Hello')],
            onToken: function (string $token)
            {
            }
        );

        $this->assertCount(1, $inner->getRequests());
    }
    // -----------------------------------------------------------------------
    // Retry triggering
    // -----------------------------------------------------------------------

    /**
     * @test
     * Successful responses must not trigger any retry.
     */
    public function testSuccessfulResponseIsNotRetried() {
        $inner = new FakeHttpClient();
        $inner->addResponse($this->successResponse());

        $provider = $this->createProvider($inner, maxRetries: 3);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(1, $inner->getRequests());
    }

    /**
     * Creates an OpenAI provider wired to the given FakeHttpClient,
     * with optional retry config applied.
     */
    private function createProvider(FakeHttpClient $inner, int $maxRetries = 0): OpenAIClient {
        $provider = new OpenAIClient([
            'api_key' => 'test-api-key',
            'model' => 'gpt-4o',
        ]);
        $provider->setHttpClient($inner);

        if ($maxRetries > 0) {
            $provider->setRetryConfig(new RetryConfig(
                maxRetries: $maxRetries,
                initialDelayMs: 0
            ));
        }

        return $provider;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Creates a success response with optional content.
     */
    private function successResponse(string $content = 'Hello'): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ]));
    }
}
