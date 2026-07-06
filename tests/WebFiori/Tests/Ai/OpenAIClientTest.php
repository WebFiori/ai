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
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Unit tests for the OpenAI provider.
 *
 * @author Ibrahim
 */
class OpenAIClientTest extends TestCase {
    /**
     * @test
     */
    public function testChatCompletion() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'PHP is a server-side scripting language.',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 8,
                'total_tokens' => 23,
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([
            new Message('system', 'You are helpful.'),
            new Message('user', 'What is PHP?'),
        ]);

        $this->assertEquals('PHP is a server-side scripting language.', $response->getMessage()->getContent());
        $this->assertEquals('assistant', $response->getMessage()->getRole());
        $this->assertEquals('gpt-4o', $response->getModel());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertEquals(15, $response->getUsage()->getPromptTokens());
        $this->assertEquals(8, $response->getUsage()->getCompletionTokens());
        $this->assertEquals(23, $response->getUsage()->getTotalTokens());
    }

    /**
     * @test
     */
    public function testChatRequestFormat() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['temperature' => 0.7, 'max_tokens' => 100]
        );

        $request = $client->getLastRequest();
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://api.openai.com/v1/chat/completions', $request->getUrl());
        $this->assertEquals('Bearer sk-test-key', $request->getHeader('Authorization'));
        $this->assertEquals('application/json', $request->getHeader('Content-Type'));

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('gpt-4o', $body['model']);
        $this->assertEquals(0.7, $body['temperature']);
        $this->assertEquals(100, $body['max_tokens']);
        $this->assertCount(1, $body['messages']);
        $this->assertEquals('user', $body['messages'][0]['role']);
        $this->assertEquals('Hello', $body['messages'][0]['content']);
    }

    /**
     * @test
     */
    public function testChatWithOrganization() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));

        $provider = new OpenAIClient([
            'api_key' => 'sk-test',
            'organization' => 'org-123',
        ]);
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hi')]);

        $request = $client->getLastRequest();
        $this->assertEquals('org-123', $request->getHeader('OpenAI-Organization'));
    }

    /**
     * @test
     */
    public function testChatWithToolCallResponse() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_abc123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"London"}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'What is the weather in London?')]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('tool_calls', $response->getFinishReason());

        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertEquals('call_abc123', $toolCalls[0]->getId());
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['location' => 'London'], $toolCalls[0]->getArguments());
    }

    /**
     * @test
     */
    public function testCustomBaseUrl() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'local-model',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));

        $provider = new OpenAIClient([
            'api_key' => 'sk-test',
            'base_url' => 'https://my-proxy.example.com/api',
        ]);
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hi')]);

        $request = $client->getLastRequest();
        $this->assertEquals('https://my-proxy.example.com/api/chat/completions', $request->getUrl());
    }

    /**
     * @test
     */
    public function testEmbeddings() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'text-embedding-3-small',
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3, 0.4]],
            ],
            'usage' => ['prompt_tokens' => 5],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->embed('Hello world');

        $this->assertEquals([0.1, 0.2, 0.3, 0.4], $response->getVector());
        $this->assertEquals(4, $response->getDimensions());
        $this->assertEquals('text-embedding-3-small', $response->getModel());
        $this->assertEquals(5, $response->getUsage()->getPromptTokens());
    }

    /**
     * @test
     */
    public function testEmbeddingsBatch() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'text-embedding-3-small',
            'data' => [
                ['embedding' => [0.1, 0.2]],
                ['embedding' => [0.3, 0.4]],
            ],
            'usage' => ['prompt_tokens' => 10],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->embed(['Hello', 'World']);

        $this->assertCount(2, $response->getVectors());
    }

    /**
     * @test
     */
    public function testErrorAuthentication() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(401, [], json_encode([
            'error' => [
                'message' => 'Incorrect API key provided: sk-test****.',
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Incorrect API key provided');
        $provider->chat([new Message('user', 'Hi')]);
    }

    /**
     * @test
     */
    public function testErrorProviderGeneric() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(500, [], json_encode([
            'error' => [
                'message' => 'The server had an error.',
                'type' => 'server_error',
                'code' => null,
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $this->expectException(ProviderException::class);
        $provider->chat([new Message('user', 'Hi')]);
    }

    /**
     * @test
     */
    public function testErrorRateLimit() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(429, ['Retry-After' => '20'], json_encode([
            'error' => [
                'message' => 'Rate limit reached.',
                'type' => 'rate_limit_error',
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        try {
            $provider->chat([new Message('user', 'Hi')]);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertEquals('Rate limit reached.', $e->getMessage());
            $this->assertEquals(20, $e->getRetryAfterSeconds());
        }
    }

    /**
     * @test
     */
    public function testGetName() {
        $provider = $this->createProvider();
        $this->assertEquals('openai', $provider->getName());
    }

    /**
     * @test
     */
    public function testImageGeneration() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'data' => [[
                'url' => 'https://images.openai.com/generated/123.png',
                'revised_prompt' => 'A cute cat wearing a red hat',
            ]],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->generateImage(new ImageRequest(
            'A cat wearing a hat',
            '1024x1024',
            1,
            'hd',
            'url',
            'vivid'
        ));

        $this->assertCount(1, $response->getImages());
        $this->assertEquals('https://images.openai.com/generated/123.png', $response->getImages()[0]->getUrl());
        $this->assertEquals('A cute cat wearing a red hat', $response->getImages()[0]->getRevisedPrompt());
    }

    /**
     * @test
     */
    public function testMissingApiKeyThrows() {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('api_key');
        new OpenAIClient([]);
    }

    /**
     * @test
     */
    public function testStreamChat() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: {\"id\":\"chatcmpl-1\",\"model\":\"gpt-4o\",\"choices\":[{\"delta\":{\"role\":\"assistant\",\"content\":\"\"},\"finish_reason\":null}]}\n\n",
            "data: {\"id\":\"chatcmpl-1\",\"model\":\"gpt-4o\",\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n",
            "data: {\"id\":\"chatcmpl-1\",\"model\":\"gpt-4o\",\"choices\":[{\"delta\":{\"content\":\" world\"},\"finish_reason\":null}]}\n\n",
            "data: {\"id\":\"chatcmpl-1\",\"model\":\"gpt-4o\",\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $tokens = [];
        $completedResponse = null;

        $provider->streamChat(
            [new Message('user', 'Say hello')],
            function (string $token) use (&$tokens) {
                $tokens[] = $token;
            },
            function ($response) use (&$completedResponse) {
                $completedResponse = $response;
            }
        );

        $this->assertEquals(['Hello', ' world'], $tokens);
        $this->assertNotNull($completedResponse);
        $this->assertEquals('Hello world', $completedResponse->getMessage()->getContent());
        $this->assertEquals('gpt-4o', $completedResponse->getModel());
        $this->assertEquals('stop', $completedResponse->getFinishReason());
    }

    /**
     * @test
     */
    public function testStreamChatRequestFormat() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks(["data: [DONE]\n\n"]);

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {}
        );

        $request = $client->getLastRequest();
        $body = json_decode($request->getBody(), true);
        $this->assertTrue($body['stream']);
    }

    /**
     * @test
     */
    public function testToolMessageFormatting() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'The weather is 22°C.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $toolResult = new ToolResult('call_abc', '{"temp": 22}');
        $messages = [
            new Message('user', 'What is the weather?'),
            new Message('assistant', '', [new ToolCall('call_abc', 'get_weather', ['location' => 'London'])]),
            new Message('tool', '', [], $toolResult),
        ];

        $provider->chat($messages);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertCount(3, $body['messages']);

        // Tool call message
        $this->assertEquals('assistant', $body['messages'][1]['role']);
        $this->assertArrayHasKey('tool_calls', $body['messages'][1]);
        $this->assertEquals('call_abc', $body['messages'][1]['tool_calls'][0]['id']);
        $this->assertEquals('get_weather', $body['messages'][1]['tool_calls'][0]['function']['name']);

        // Tool result message
        $this->assertEquals('tool', $body['messages'][2]['role']);
        $this->assertEquals('call_abc', $body['messages'][2]['tool_call_id']);
        $this->assertEquals('{"temp": 22}', $body['messages'][2]['content']);
    }

    /**
     * Creates an OpenAI provider with test configuration.
     *
     * @return OpenAIClient The configured provider instance.
     */
    private function createProvider(): OpenAIClient {
        return new OpenAIClient([
            'api_key' => 'sk-test-key',
            'model' => 'gpt-4o',
        ]);
    }
}
