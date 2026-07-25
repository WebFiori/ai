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
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Unit tests for the Anthropic provider.
 *
 * @author Ibrahim
 */
class AnthropicClientTest extends TestCase {
    /**
     * @test
     */
    public function testChatCompletion() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_01XFDUDYJgAACzvnptvVoYEL',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [[
                'type' => 'text',
                'text' => 'Paris is the capital of France.',
            ]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => 20,
                'output_tokens' => 8,
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([
            new Message('system', 'You are a helpful assistant.'),
            new Message('user', 'What is the capital of France?'),
        ]);

        $this->assertEquals('Paris is the capital of France.', $response->getMessage()->getContent());
        $this->assertEquals('assistant', $response->getMessage()->getRole());
        $this->assertEquals('claude-sonnet-4-20250514', $response->getModel());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertEquals(20, $response->getUsage()->getPromptTokens());
        $this->assertEquals(8, $response->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testChatRequestFormat() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat([
            new Message('system', 'Be helpful.'),
            new Message('user', 'Hello'),
        ]);

        $request = $client->getLastRequest();
        $body = json_decode($request->getBody(), true);

        // System message should be separate top-level parameter
        $this->assertEquals('Be helpful.', $body['system']);

        // Messages should not include system message
        $this->assertCount(1, $body['messages']);
        $this->assertEquals('user', $body['messages'][0]['role']);
        $this->assertEquals('Hello', $body['messages'][0]['content']);

        // max_tokens should always be present
        $this->assertArrayHasKey('max_tokens', $body);

        // Check headers
        $headers = $request->getHeaders();
        $this->assertArrayHasKey('x-api-key', $headers);
        $this->assertArrayHasKey('anthropic-version', $headers);
    }

    /**
     * @test
     */
    public function testToolCalling() {
        $client = new FakeHttpClient();

        // First response: model wants to use a tool
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_tool',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_01A09q90qw90lq917835lgs0',
                    'name' => 'get_weather',
                    'input' => ['location' => 'Paris'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 30],
        ])));

        // Second response: model provides final answer
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_final',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'The weather in Paris is sunny, 22°C.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 80, 'output_tokens' => 15],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $weatherTool = new Tool(
            'get_weather',
            'Get weather for a location',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string'],
                ],
                'required' => ['location'],
            ],
            function (array $args): string {
                return json_encode(['temp' => 22, 'conditions' => 'sunny']);
            }
        );

        $response = $provider->chat(
            [new Message('user', 'What is the weather in Paris?')],
            ['tools' => [$weatherTool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('The weather in Paris is sunny, 22°C.', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testAuthenticationError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(401, [], json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key',
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $this->expectException(AuthenticationException::class);
        $provider->chat([new Message('user', 'Hello')]);
    }

    /**
     * @test
     */
    public function testRateLimitError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(429, ['retry-after' => '30'], json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $this->expectException(RateLimitException::class);
        $provider->chat([new Message('user', 'Hello')]);
    }

    /**
     * @test
     */
    public function testOverloadedError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(529, [], json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'overloaded_error',
                'message' => 'API is temporarily overloaded',
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $this->expectException(ProviderException::class);
        $provider->chat([new Message('user', 'Hello')]);
    }

    /**
     * @test
     */
    public function testMissingApiKey() {
        $this->expectException(InvalidConfigException::class);
        new AnthropicClient([]);
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
        $this->assertEquals('anthropic', $provider->getName());
    }

    /**
     * Creates a configured Anthropic provider for testing.
     *
     * @return AnthropicClient
     */
    private function createProvider(): AnthropicClient {
        return new AnthropicClient([
            'api_key' => 'test-api-key',
            'model' => 'claude-sonnet-4-20250514',
        ]);
    }
}
