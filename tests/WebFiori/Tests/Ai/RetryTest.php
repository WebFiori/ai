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
use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\RetryableHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\RetryConfig;

/**
 * Unit tests for retry logic with exponential backoff.
 *
 * @author Ibrahim
 */
class RetryTest extends TestCase {
    /**
     * @test
     */
    public function testNoRetryOnSuccess() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ])));

        $config = new RetryConfig(maxRetries: 3, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config);

        $provider = $this->createProvider();
        $provider->setHttpClient($retryClient);

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Hello', $response->getMessage()->getContent());
        $this->assertEquals(1, (count($inner->getRequests())));
    }

    /**
     * @test
     */
    public function testRetriesOn500ThenSucceeds() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(500, [], '{"error": "Internal Server Error"}'));
        $inner->addResponse(new HttpResponse(500, [], '{"error": "Internal Server Error"}'));
        $inner->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello after retries'],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 4, 'total_tokens' => 9],
        ])));

        $config = new RetryConfig(maxRetries: 3, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config);

        $provider = $this->createProvider();
        $provider->setHttpClient($retryClient);

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Hello after retries', $response->getMessage()->getContent());
        $this->assertEquals(3, (count($inner->getRequests())));
    }

    /**
     * @test
     */
    public function testRetriesOn429ThenSucceeds() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(429, [], '{"error": {"message": "Rate limit exceeded"}}'));
        $inner->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'OK'],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1, 'total_tokens' => 3],
        ])));

        $config = new RetryConfig(maxRetries: 2, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config);

        $provider = $this->createProvider();
        $provider->setHttpClient($retryClient);

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('OK', $response->getMessage()->getContent());
        $this->assertEquals(2, (count($inner->getRequests())));
    }

    /**
     * @test
     */
    public function testRespectsRetryAfterHeader() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(429, ['retry-after' => '0'], '{"error": {"message": "Rate limit"}}'));
        $inner->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Done'],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1, 'total_tokens' => 3],
        ])));

        $config = new RetryConfig(maxRetries: 2, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config);

        $provider = $this->createProvider();
        $provider->setHttpClient($retryClient);

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Done', $response->getMessage()->getContent());
        $this->assertEquals(2, (count($inner->getRequests())));
    }

    /**
     * @test
     */
    public function testGivesUpAfterMaxRetries() {
        $inner = new FakeHttpClient();

        // All 4 responses (1 initial + 3 retries) are 500
        for ($i = 0; $i < 4; $i++) {
            $inner->addResponse(new HttpResponse(500, [], '{"error": {"message": "Server Error", "type": "server_error"}}'));
        }

        $config = new RetryConfig(maxRetries: 3, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config);

        $provider = $this->createProvider();
        $provider->setHttpClient($retryClient);

        $this->expectException(ProviderException::class);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals(4, (count($inner->getRequests())));
    }

    /**
     * @test
     */
    public function testNoRetryOn400() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(400, [], '{"error": {"message": "Bad request", "type": "invalid_request_error"}}'));

        $config = new RetryConfig(maxRetries: 3, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config);

        $provider = $this->createProvider();
        $provider->setHttpClient($retryClient);

        // 400 is not in retryable codes, so it should throw immediately
        $this->expectException(ProviderException::class);
        $provider->chat([new Message('user', 'Hello')]);

        // Only 1 request made — no retry
        $this->assertEquals(1, (count($inner->getRequests())));
    }

    /**
     * @test
     */
    public function testNoRetryOn401() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(401, [], '{"error": {"message": "Invalid API key", "type": "invalid_api_key"}}'));

        $config = new RetryConfig(maxRetries: 3, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config);

        $provider = $this->createProvider();
        $provider->setHttpClient($retryClient);

        $this->expectException(\WebFiori\Ai\Exception\AuthenticationException::class);
        $provider->chat([new Message('user', 'Hello')]);
    }

    /**
     * @test
     */
    public function testSetRetryConfigOnProvider() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(503, [], '{"error": {"message": "Service Unavailable", "type": "service_error"}}'));
        $inner->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Back online'],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 2, 'total_tokens' => 4],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($inner);
        $provider->setRetryConfig(new RetryConfig(maxRetries: 2, initialDelayMs: 0));

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Back online', $response->getMessage()->getContent());
        $this->assertEquals(2, (count($inner->getRequests())));
    }

    /**
     * @test
     */
    public function testRetryLogsWarnings() {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(500, [], '{"error": {"message": "Error", "type": "server_error"}}'));
        $inner->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'OK'],
                'finish_reason' => 'stop',
            ]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1, 'total_tokens' => 3],
        ])));

        $logEntries = [];
        $config = new RetryConfig(maxRetries: 2, initialDelayMs: 0);
        $retryClient = new RetryableHttpClient($inner, $config, function (string $level, string $message, array $context) use (&$logEntries) {
            $logEntries[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });

        $provider = $this->createProvider();
        $provider->setHttpClient($retryClient);
        $provider->chat([new Message('user', 'Hello')]);

        $this->assertCount(1, $logEntries);
        $this->assertEquals('warning', $logEntries[0]['level']);
        $this->assertStringContainsString('Retrying', $logEntries[0]['message']);
        $this->assertEquals(500, $logEntries[0]['context']['status_code']);
        $this->assertEquals(1, $logEntries[0]['context']['attempt']);
    }

    /**
     * @test
     */
    public function testRetryConfigCalculatesBackoff() {
        $config = new RetryConfig(
            maxRetries: 3,
            initialDelayMs: 1000,
            maxDelayMs: 30000,
            backoffMultiplier: 2.0
        );

        // Without jitter the base values would be 1000, 2000, 4000
        // With ±20% jitter, they should be within 800-1200, 1600-2400, 3200-4800
        $delay1 = $config->calculateDelayMs(1);
        $delay2 = $config->calculateDelayMs(2);
        $delay3 = $config->calculateDelayMs(3);

        $this->assertGreaterThanOrEqual(800, $delay1);
        $this->assertLessThanOrEqual(1200, $delay1);

        $this->assertGreaterThanOrEqual(1600, $delay2);
        $this->assertLessThanOrEqual(2400, $delay2);

        $this->assertGreaterThanOrEqual(3200, $delay3);
        $this->assertLessThanOrEqual(4800, $delay3);
    }

    /**
     * @test
     */
    public function testRetryConfigCapsAtMaxDelay() {
        $config = new RetryConfig(
            maxRetries: 10,
            initialDelayMs: 1000,
            maxDelayMs: 5000,
            backoffMultiplier: 10.0
        );

        // By attempt 4, base delay would be 1000 * 10^3 = 1,000,000ms
        // Should be capped at 5000ms (±20%)
        $delay = $config->calculateDelayMs(4);
        $this->assertLessThanOrEqual(6000, $delay); // 5000 + 20% jitter max
    }

    /**
     * @test
     */
    public function testGetInnerClient() {
        $inner = new FakeHttpClient();
        $config = new RetryConfig();
        $retryClient = new RetryableHttpClient($inner, $config);

        $this->assertSame($inner, $retryClient->getInner());
    }

    /**
     * @test
     */
    public function testSetRetryConfigPreservesInnerClient() {
        $inner = new FakeHttpClient();
        $provider = $this->createProvider();
        $provider->setHttpClient($inner);

        $provider->setRetryConfig(new RetryConfig(maxRetries: 2, initialDelayMs: 0));

        // Calling setRetryConfig again should not double-wrap
        $provider->setRetryConfig(new RetryConfig(maxRetries: 3, initialDelayMs: 0));

        $http = $provider->getHttpClient();
        $this->assertInstanceOf(RetryableHttpClient::class, $http);
        $this->assertSame($inner, $http->getInner());
        $this->assertNotInstanceOf(RetryableHttpClient::class, $http->getInner());
    }

    /**
     * Creates an OpenAI provider for testing.
     *
     * @return OpenAIClient
     */
    private function createProvider(): OpenAIClient {
        return new OpenAIClient([
            'api_key' => 'test-api-key',
            'model' => 'gpt-4o',
        ]);
    }
}
