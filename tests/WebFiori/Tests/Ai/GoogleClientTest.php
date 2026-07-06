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
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Unit tests for the Vertex AI provider.
 *
 * @author Ibrahim
 */
class GoogleClientTest extends TestCase {
    /**
     * @test
     */
    public function testChatCompletion() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => 'PHP is a programming language.']],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 12,
                'candidatesTokenCount' => 7,
                'totalTokenCount' => 19,
            ],
            'modelVersion' => 'gemini-1.5-pro-001',
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([
            new Message('user', 'What is PHP?'),
        ]);

        $this->assertEquals('PHP is a programming language.', $response->getMessage()->getContent());
        $this->assertEquals('assistant', $response->getMessage()->getRole());
        $this->assertEquals('gemini-1.5-pro-001', $response->getModel());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertEquals(12, $response->getUsage()->getPromptTokens());
        $this->assertEquals(7, $response->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testChatRequestFormat() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hi']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [
                new Message('system', 'You are helpful.'),
                new Message('user', 'Hello'),
            ],
            ['temperature' => 0.5, 'max_tokens' => 200]
        );

        $request = $client->getLastRequest();
        $this->assertEquals('POST', $request->getMethod());
        $this->assertStringContainsString('generateContent', $request->getUrl());
        $this->assertStringContainsString('my-project', $request->getUrl());
        $this->assertStringContainsString('us-central1', $request->getUrl());
        $this->assertStringContainsString('gemini-1.5-pro', $request->getUrl());
        $this->assertEquals('Bearer test-access-token', $request->getHeader('Authorization'));

        $body = json_decode($request->getBody(), true);

        // System message should be in systemInstruction, not contents
        $this->assertArrayHasKey('systemInstruction', $body);
        $this->assertEquals('You are helpful.', $body['systemInstruction']['parts'][0]['text']);

        // Only user message in contents
        $this->assertCount(1, $body['contents']);
        $this->assertEquals('user', $body['contents'][0]['role']);
        $this->assertEquals('Hello', $body['contents'][0]['parts'][0]['text']);

        // Generation config
        $this->assertEquals(0.5, $body['generationConfig']['temperature']);
        $this->assertEquals(200, $body['generationConfig']['maxOutputTokens']);
    }

    /**
     * @test
     */
    public function testChatWithToolCallResponse() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'name' => 'get_weather',
                            'args' => ['location' => 'London'],
                        ],
                    ]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 15, 'candidatesTokenCount' => 8],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Weather in London?')]);

        $this->assertTrue($response->hasToolCalls());
        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['location' => 'London'], $toolCalls[0]->getArguments());
    }

    /**
     * @test
     */
    public function testEmbeddings() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'predictions' => [
                ['embeddings' => ['values' => [0.1, 0.2, 0.3]]],
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->embed('Hello world');

        $this->assertEquals([0.1, 0.2, 0.3], $response->getVector());
        $this->assertEquals(3, $response->getDimensions());
        $this->assertEquals('text-embedding-004', $response->getModel());
    }

    /**
     * @test
     */
    public function testEmbeddingsBatch() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'predictions' => [
                ['embeddings' => ['values' => [0.1, 0.2]]],
                ['embeddings' => ['values' => [0.3, 0.4]]],
            ],
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
        $client->addResponse(new HttpResponse(403, [], json_encode([
            'error' => [
                'message' => 'Permission denied.',
                'status' => 'PERMISSION_DENIED',
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Permission denied.');
        $provider->chat([new Message('user', 'Hi')]);
    }

    /**
     * @test
     */
    public function testErrorProvider() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(500, [], json_encode([
            'error' => [
                'message' => 'Internal error.',
                'status' => 'INTERNAL',
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
        $client->addResponse(new HttpResponse(429, ['Retry-After' => '60'], json_encode([
            'error' => [
                'message' => 'Quota exceeded.',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        try {
            $provider->chat([new Message('user', 'Hi')]);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertEquals(60, $e->getRetryAfterSeconds());
        }
    }

    /**
     * @test
     */
    public function testGetName() {
        $provider = $this->createProvider();
        $this->assertEquals('google', $provider->getName());
    }

    /**
     * @test
     */
    public function testMissingCredentialsThrows() {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('credentials');
        new GoogleClient([
            'project_id' => 'my-project',
            'location' => 'us-central1',
        ]);
    }

    /**
     * @test
     */
    public function testMissingLocationThrows() {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('location');
        new GoogleClient([
            'project_id' => 'my-project',
            'access_token' => 'token',
        ]);
    }

    /**
     * @test
     */
    public function testMissingProjectIdThrows() {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('project_id');
        new GoogleClient([
            'location' => 'us-central1',
            'access_token' => 'token',
        ]);
    }

    /**
     * @test
     */
    public function testRoleMapping() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Done']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat([
            new Message('user', 'Hello'),
            new Message('assistant', 'Hi there'),
            new Message('user', 'Thanks'),
        ]);

        $body = json_decode($client->getLastRequest()->getBody(), true);

        // assistant should be mapped to 'model'
        $this->assertEquals('user', $body['contents'][0]['role']);
        $this->assertEquals('model', $body['contents'][1]['role']);
        $this->assertEquals('user', $body['contents'][2]['role']);
    }

    /**
     * @test
     */
    public function testStreamChat() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hello\"}],\"role\":\"model\"}}]}\n\n",
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\" world\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":5,\"candidatesTokenCount\":2}}\n\n",
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
        $this->assertEquals('stop', $completedResponse->getFinishReason());
        $this->assertEquals(5, $completedResponse->getUsage()->getPromptTokens());
        $this->assertEquals(2, $completedResponse->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testStreamChatRequestFormat() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hi\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}]}\n\n",
        ]);

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {}
        );

        $request = $client->getLastRequest();
        $this->assertStringContainsString('streamGenerateContent', $request->getUrl());
        $this->assertStringContainsString('alt=sse', $request->getUrl());
    }

    /**
     * @test
     */
    public function testToolResultFormatting() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'It is 22°C.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 5],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $toolResult = new ToolResult('get_weather', '{"temp": 22}');
        $messages = [
            new Message('user', 'Weather in London?'),
            new Message('assistant', '', [new ToolCall('call_1', 'get_weather', ['location' => 'London'])]),
            new Message('tool', '', [], $toolResult),
        ];

        $provider->chat($messages);

        $body = json_decode($client->getLastRequest()->getBody(), true);

        // Tool call message (model role with functionCall)
        $this->assertEquals('model', $body['contents'][1]['role']);
        $this->assertArrayHasKey('functionCall', $body['contents'][1]['parts'][0]);

        // Tool result message (function role with functionResponse)
        $this->assertEquals('function', $body['contents'][2]['role']);
        $this->assertArrayHasKey('functionResponse', $body['contents'][2]['parts'][0]);
        $this->assertEquals('get_weather', $body['contents'][2]['parts'][0]['functionResponse']['name']);
    }

    /**
     * Creates a Vertex AI provider with test configuration using a pre-set access token.
     *
     * @return GoogleClient The configured provider instance.
     */
    private function createProvider(): GoogleClient {
        return new GoogleClient([
            'project_id' => 'my-project',
            'location' => 'us-central1',
            'model' => 'gemini-1.5-pro',
            'access_token' => 'test-access-token',
        ]);
    }

    /**
     * @test
     */
    public function testGeminiApiEndpoint() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hi']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ])));

        $provider = new GoogleClient([
            'api' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'access_token' => 'test-token',
        ]);
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hi')]);

        $request = $client->getLastRequest();
        $this->assertStringContainsString('generativelanguage.googleapis.com', $request->getUrl());
        $this->assertStringContainsString('v1beta/models/gemini-2.0-flash', $request->getUrl());
        $this->assertStringContainsString('generateContent', $request->getUrl());
    }

    /**
     * @test
     */
    public function testGeminiApiStreamEndpoint() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hi\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}]}\n\n",
        ]);

        $provider = new GoogleClient([
            'api' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'access_token' => 'test-token',
        ]);
        $provider->setHttpClient($client);

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {}
        );

        $request = $client->getLastRequest();
        $this->assertStringContainsString('generativelanguage.googleapis.com', $request->getUrl());
        $this->assertStringContainsString('streamGenerateContent', $request->getUrl());
    }

    /**
     * @test
     */
    public function testGeminiApiDoesNotRequireProjectId() {
        // Should not throw — project_id and location not needed for Gemini API
        $provider = new GoogleClient([
            'api' => 'gemini',
            'access_token' => 'test-token',
        ]);

        $this->assertEquals('google', $provider->getName());
    }

    /**
     * @test
     */
    public function testVertexAiApiStillRequiresProjectId() {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('project_id');
        new GoogleClient([
            'location' => 'us-central1',
            'access_token' => 'token',
        ]);
    }
}
