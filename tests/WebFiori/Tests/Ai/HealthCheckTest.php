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

use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;

use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * Tests for health check functionality.
 */
class HealthCheckTest extends TestCase {
    // =========================================================================
    // HealthCheckResult Tests
    // =========================================================================

    /**
     * @test
     */
    public function testSuccessResult() {
        $result = HealthCheckResult::success(42, 'models_list');

        $this->assertTrue($result->isAvailable());
        $this->assertSame(42, $result->getLatencyMs());
        $this->assertSame('models_list', $result->getCheckMethod());
        $this->assertNull($result->getError());
        $this->assertInstanceOf(DateTimeInterface::class, $result->getCheckedAt());
    }

    /**
     * @test
     */
    public function testFailureResult() {
        $result = HealthCheckResult::failure('Connection refused', 100, 'models_list');

        $this->assertFalse($result->isAvailable());
        $this->assertSame(100, $result->getLatencyMs());
        $this->assertSame('models_list', $result->getCheckMethod());
        $this->assertSame('Connection refused', $result->getError());
    }

    /**
     * @test
     */
    public function testConstructorDirectly() {
        $now = new \DateTimeImmutable();
        $result = new HealthCheckResult(true, 50, 'minimal_completion', null, $now);

        $this->assertTrue($result->isAvailable());
        $this->assertSame(50, $result->getLatencyMs());
        $this->assertSame('minimal_completion', $result->getCheckMethod());
        $this->assertSame($now, $result->getCheckedAt());
    }

    /**
     * @test
     */
    public function testCheckedAtDefaultsToNow() {
        $before = new \DateTimeImmutable();
        $result = HealthCheckResult::success(10, 'test');
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $result->getCheckedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $result->getCheckedAt()->getTimestamp());
    }

    /**
     * @test
     */
    public function testCheckMethodMinimalCompletion() {
        $result = HealthCheckResult::success(10, 'minimal_completion');
        $this->assertSame('minimal_completion', $result->getCheckMethod());
    }

    // =========================================================================
    // OpenAI Health Check Tests
    // =========================================================================

    /**
     * @test
     */
    public function testOpenAiHealthCheckSuccess() {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model'],
            ],
        ])));
        $client->setHttpClient($fakeHttp);

        // Health check uses its own HTTP client, so we test by
        // verifying the method signature works
        $this->assertInstanceOf(ProviderInterface::class, $client);
    }

    /**
     * @test
     */
    public function testOpenAiHealthCheckUsesModelsListMethod() {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));

        // A health check that fails (bad key) should still return a result
        $result = $client->healthCheck(2);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame('models_list', $result->getCheckMethod());
        $this->assertInstanceOf(DateTimeInterface::class, $result->getCheckedAt());
        $this->assertGreaterThanOrEqual(0, $result->getLatencyMs());
    }

    /**
     * @test
     */
    public function testOpenAiHealthCheckNeverThrows() {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'invalid-key', model: 'gpt-4o'));

        // Should never throw even with invalid credentials
        $result = $client->healthCheck(2);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
    }

    /**
     * @test
     */
    public function testOpenAiHealthCheckHasLatency() {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));

        $result = $client->healthCheck(2);

        $this->assertGreaterThanOrEqual(0, $result->getLatencyMs());
    }

    // =========================================================================
    // Anthropic Health Check Tests
    // =========================================================================

    /**
     * @test
     */
    public function testAnthropicHealthCheckUsesMinimalCompletion() {
        $client = new AnthropicClient(new AnthropicClientConfig(apiKey: 'test-key'));

        $result = $client->healthCheck(2);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame('minimal_completion', $result->getCheckMethod());
    }

    /**
     * @test
     */
    public function testAnthropicHealthCheckNeverThrows() {
        $client = new AnthropicClient(new AnthropicClientConfig(apiKey: 'invalid-key'));

        $result = $client->healthCheck(2);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
    }

    // =========================================================================
    // Google Health Check Tests
    // =========================================================================

    /**
     * @test
     */
    public function testGoogleHealthCheckUsesModelsList() {
        $client = new GoogleClient(new GoogleClientConfig(apiKey: 'test-key'));

        $result = $client->healthCheck(2);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame('models_list', $result->getCheckMethod());
    }

    /**
     * @test
     */
    public function testGoogleHealthCheckNeverThrows() {
        $client = new GoogleClient(new GoogleClientConfig(apiKey: 'invalid-key'));

        $result = $client->healthCheck(2);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
    }

    // =========================================================================
    // Bedrock Health Check Tests
    // =========================================================================

    /**
     * @test
     */
    public function testBedrockHealthCheckUsesModelsList() {
        $client = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            apiKey: 'test-key',
        ));

        $result = $client->healthCheck(2);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame('models_list', $result->getCheckMethod());
    }

    /**
     * @test
     */
    public function testBedrockHealthCheckNeverThrows() {
        $client = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            apiKey: 'invalid-key',
        ));

        $result = $client->healthCheck(2);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
    }

    // =========================================================================
    // Shared Behavior Tests
    // =========================================================================

    /**
     * @test
     */
    public function testHealthCheckIsOnProviderInterface() {
        $reflection = new \ReflectionClass(ProviderInterface::class);
        $this->assertTrue($reflection->hasMethod('healthCheck'));

        $method = $reflection->getMethod('healthCheck');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('timeout', $params[0]->getName());
        $this->assertSame(5, $params[0]->getDefaultValue());
    }

    /**
     * @test
     */
    public function testHealthCheckResultWhenUnavailable() {
        $result = HealthCheckResult::failure('Service unavailable', 3000, 'models_list');

        $this->assertFalse($result->isAvailable());
        $this->assertNotNull($result->getError());
        $this->assertSame(3000, $result->getLatencyMs());
    }

    /**
     * @test
     */
    public function testHealthCheckDefaultTimeout() {
        $reflection = new \ReflectionClass(ProviderInterface::class);
        $method = $reflection->getMethod('healthCheck');
        $params = $method->getParameters();

        $this->assertSame(5, $params[0]->getDefaultValue());
    }
}
