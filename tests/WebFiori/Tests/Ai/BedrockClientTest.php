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
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Bedrock\ApiMethod;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;

/**
 * Unit tests for the Bedrock provider.
 *
 * @author Ibrahim
 */
class BedrockClientTest extends TestCase {
    /**
     * @test
     */
    public function testChatCompletionConverseDefault() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['text' => 'Hello! How can I help you?']],
                ],
            ],
            'stopReason' => 'end_turn',
            'usage' => [
                'inputTokens' => 10,
                'outputTokens' => 8,
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Hello! How can I help you?', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertEquals(10, $response->getUsage()->getPromptTokens());
        $this->assertEquals(8, $response->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testConverseEndpoint() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hi']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
        ])));

        $provider = $this->createProvider(ApiMethod::CONVERSE);
        $provider->setHttpClient($client);
        $provider->chat([new Message('user', 'Hello')]);

        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('/converse', $url);
        $this->assertStringNotContainsString('/invoke', $url);
    }

    /**
     * @test
     */
    public function testInvokeEndpoint() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider(ApiMethod::INVOKE);
        $provider->setHttpClient($client);
        $provider->chat([new Message('user', 'Hello')]);

        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('/invoke', $url);
        $this->assertStringNotContainsString('/converse', $url);
    }

    /**
     * @test
     */
    public function testChatCompletionInvokeStrategy() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hello via Invoke!']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])));

        $provider = $this->createProvider(ApiMethod::INVOKE);
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Hello via Invoke!', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testResponsesStrategyThrowsNotImplemented() {
        $provider = $this->createProvider(ApiMethod::RESPONSES);

        $this->expectException(UnsupportedFeatureException::class);
        $provider->chat([new Message('user', 'Hello')]);
    }

    /**
     * @test
     */
    public function testInvalidApiMethodThrows() {
        $this->expectException(InvalidConfigException::class);
        new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            apiKey: 'key',
            apiMethod: 'invalid_method',
        ));
    }

    /**
     * @test
     */
    public function testRequestIncludesAwsSignature() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hi']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);
        $provider->chat([new Message('user', 'Hello')]);

        $headers = $client->getLastRequest()->getHeaders();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertArrayHasKey('X-Amz-Date', $headers);
        $this->assertArrayHasKey('X-Amz-Content-Sha256', $headers);
        $this->assertStringStartsWith('AWS4-HMAC-SHA256', $headers['Authorization']);
    }

    /**
     * @test
     */
    public function testApiKeyRequestUsesBearer() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hi']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
        ])));

        $provider = $this->createProviderWithApiKey();
        $provider->setHttpClient($client);
        $provider->chat([new Message('user', 'Hello')]);

        $headers = $client->getLastRequest()->getHeaders();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
        $this->assertStringContainsString('test-bedrock-api-key', $headers['Authorization']);
        $this->assertArrayNotHasKey('X-Amz-Date', $headers);
    }

    /**
     * @test
     */
    public function testMissingBothAuthOptions() {
        // No explicit credentials = ADC will be tried at request time
        // Construction should succeed — no exception
        $provider = new BedrockClient(new BedrockClientConfig(region: 'us-east-1'));
        $this->assertEquals('bedrock', $provider->getName());
    }

    /**
     * @test
     */
    public function testApiKeyOnlyRequiresRegion() {
        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            apiKey: 'test-key',
        ));
        $this->assertEquals('bedrock', $provider->getName());
    }

    /**
     * @test
     */
    public function testMissingRegion() {
        $this->expectException(InvalidConfigException::class);
        new BedrockClient(new BedrockClientConfig(
            region: '',
            accessKey: 'access',
            secretKey: 'secret',
        ));
    }

    /**
     * @test
     */
    public function testMissingRegionWithApiKey() {
        $this->expectException(InvalidConfigException::class);
        new BedrockClient(new BedrockClientConfig(region: '', apiKey: 'test-key'));
    }

    /**
     * @test
     */
    public function testEmbeddingsNotSupported() {
        $this->expectException(UnsupportedFeatureException::class);
        $this->createProvider()->embed('Hello world');
    }

    /**
     * @test
     */
    public function testImageGenerationNotSupported() {
        $this->expectException(UnsupportedFeatureException::class);
        $this->createProvider()->generateImage(new ImageRequest('A cat'));
    }

    /**
     * @test
     */
    public function testGetName() {
        $this->assertEquals('bedrock', $this->createProvider()->getName());
    }

    /**
     * Creates a Bedrock provider (SigV4 mode) with optional api_method.
     *
     * @param string $apiMethod One of the ApiMethod constants.
     *
     * @return BedrockClient
     */
    private function createProvider(string $apiMethod = ApiMethod::CONVERSE): BedrockClient {
        return new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            accessKey: 'AKIAIOSFODNN7EXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            apiMethod: $apiMethod,
        ));
    }

    /**
     * Creates a Bedrock provider using API key authentication.
     *
     * @return BedrockClient
     */
    private function createProviderWithApiKey(): BedrockClient {
        return new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            apiKey: 'test-bedrock-api-key',
            apiMethod: ApiMethod::CONVERSE,
        ));
    }
}
