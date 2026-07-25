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
use WebFiori\Ai\Provider\Bedrock\BedrockClient;

/**
 * Unit tests for the Bedrock provider.
 *
 * @author Ibrahim
 */
class BedrockClientTest extends TestCase {
    /**
     * @test
     */
    public function testChatCompletionWithClaude() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_bedrock',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            'content' => [[
                'type' => 'text',
                'text' => 'Hello! How can I help you?',
            ]],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 8,
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([
            new Message('user', 'Hello'),
        ]);

        $this->assertEquals('Hello! How can I help you?', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertEquals(10, $response->getUsage()->getPromptTokens());
        $this->assertEquals(8, $response->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testRequestIncludesAwsSignature() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hello')]);

        $request = $client->getLastRequest();
        $headers = $request->getHeaders();

        // Should have AWS SigV4 headers
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertArrayHasKey('X-Amz-Date', $headers);
        $this->assertArrayHasKey('X-Amz-Content-Sha256', $headers);

        // Authorization should be SigV4 format
        $this->assertStringStartsWith('AWS4-HMAC-SHA256', $headers['Authorization']);
    }

    /**
     * @test
     */
    public function testEndpointFormat() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hello')]);

        $request = $client->getLastRequest();
        $url = $request->getUrl();

        // Should use Bedrock runtime endpoint format
        $this->assertStringContainsString('bedrock-runtime.us-east-1.amazonaws.com', $url);
        $this->assertStringContainsString('/model/', $url);
        $this->assertStringContainsString('/invoke', $url);
    }

    /**
     * @test
     */
    public function testMissingAccessKey() {
        $this->expectException(InvalidConfigException::class);
        new BedrockClient([
            'secret_key' => 'secret',
            'region' => 'us-east-1',
        ]);
    }

    /**
     * @test
     */
    public function testMissingSecretKey() {
        $this->expectException(InvalidConfigException::class);
        new BedrockClient([
            'access_key' => 'access',
            'region' => 'us-east-1',
        ]);
    }

    /**
     * @test
     */
    public function testMissingRegion() {
        $this->expectException(InvalidConfigException::class);
        new BedrockClient([
            'access_key' => 'access',
            'secret_key' => 'secret',
        ]);
    }

    /**
     * @test
     */
    public function testEmbeddingsNotSupported() {
        $provider = $this->createProvider();

        $this->expectException(UnsupportedFeatureException::class);
        $provider->embed('Hello world');
    }

    /**
     * @test
     */
    public function testImageGenerationNotSupported() {
        $provider = $this->createProvider();

        $this->expectException(UnsupportedFeatureException::class);
        $provider->generateImage(new ImageRequest('A cat'));
    }

    /**
     * @test
     */
    public function testGetName() {
        $provider = $this->createProvider();
        $this->assertEquals('bedrock', $provider->getName());
    }

    /**
     * Creates a configured Bedrock provider for testing.
     *
     * @return BedrockClient
     */
    private function createProvider(): BedrockClient {
        return new BedrockClient([
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'model' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
        ]);
    }
}
