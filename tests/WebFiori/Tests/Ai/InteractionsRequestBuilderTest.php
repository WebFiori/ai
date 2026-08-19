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
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\InteractionsRequestBuilder;
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for #101: InteractionsRequestBuilder.
 */
class InteractionsRequestBuilderTest extends TestCase {
    private InteractionsRequestBuilder $builder;

    protected function setUp(): void {
        $this->builder = new InteractionsRequestBuilder();
    }

    // =========================================================================
    // Endpoint URL generation
    // =========================================================================

    public function testGeminiEndpointWithApiKey(): void {
        $url = $this->builder->buildGeminiEndpoint('my-api-key');
        $this->assertEquals(
            'https://generativelanguage.googleapis.com/v1beta/interactions?key=my-api-key',
            $url
        );
    }

    public function testGeminiEndpointWithoutApiKey(): void {
        $url = $this->builder->buildGeminiEndpoint(null);
        $this->assertEquals(
            'https://generativelanguage.googleapis.com/v1beta/interactions',
            $url
        );
    }

    public function testGeminiStreamingEndpoint(): void {
        $url = $this->builder->buildGeminiEndpoint('key', stream: true);
        $this->assertStringContainsString('interactions:stream', $url);
        $this->assertStringContainsString('?key=key', $url);
    }

    public function testVertexEndpointGlobalLocation(): void {
        $url = $this->builder->buildVertexEndpoint('my-project', 'global');
        $this->assertEquals(
            'https://aiplatform.googleapis.com/v1beta1/projects/my-project/locations/global/interactions',
            $url
        );
    }

    public function testVertexEndpointRegionalLocation(): void {
        $url = $this->builder->buildVertexEndpoint('my-project', 'us-central1');
        $this->assertEquals(
            'https://us-central1-aiplatform.googleapis.com/v1beta1/projects/my-project/locations/us-central1/interactions',
            $url
        );
    }

    public function testVertexStreamingEndpoint(): void {
        $url = $this->builder->buildVertexEndpoint('my-project', 'us-central1', stream: true);
        $this->assertStringContainsString('interactions:stream', $url);
    }

    // =========================================================================
    // Tool formatting
    // =========================================================================

    public function testFormatToolsUsesFlatFunctionFormat(): void {
        $tool = new Tool('get_weather', 'Get weather', [
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
            'required' => ['location'],
        ], fn() => '');

        $formatted = $this->builder->formatTools([$tool]);

        $this->assertCount(1, $formatted);
        $this->assertEquals('function', $formatted[0]['type']);
        $this->assertEquals('get_weather', $formatted[0]['name']);
        $this->assertEquals('Get weather', $formatted[0]['description']);
        $this->assertArrayHasKey('parameters', $formatted[0]);
    }

    public function testFormatMultipleTools(): void {
        $tools = [
            new Tool('tool_a', 'Tool A', ['type' => 'object', 'properties' => []], fn() => ''),
            new Tool('tool_b', 'Tool B', ['type' => 'object', 'properties' => []], fn() => ''),
        ];

        $formatted = $this->builder->formatTools($tools);

        $this->assertCount(2, $formatted);
        $this->assertEquals('tool_a', $formatted[0]['name']);
        $this->assertEquals('tool_b', $formatted[1]['name']);
    }

    // =========================================================================
    // Request body structure
    // =========================================================================

    public function testChatRequestBody(): void {
        $messages = [new Message('user', 'Hello')];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            [],
            'https://generativelanguage.googleapis.com/v1beta/interactions',
            ['Content-Type' => 'application/json']
        );

        $body = json_decode($request->getBody(), true);

        $this->assertEquals('gemini-3.5-flash', $body['model']);
        $this->assertArrayHasKey('input', $body);
        $this->assertFalse($body['store']);
    }

    public function testChatRequestContainsUserInput(): void {
        $messages = [new Message('user', 'What is PHP?')];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            [],
            'https://example.com',
            []
        );

        $body = json_decode($request->getBody(), true);

        $this->assertEquals('user_input', $body['input'][0]['type']);
        $this->assertEquals('What is PHP?', $body['input'][0]['content'][0]['text']);
    }

    public function testChatRequestIncludesSystemInstruction(): void {
        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hi'),
        ];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            [],
            'https://example.com',
            []
        );

        $body = json_decode($request->getBody(), true);

        $this->assertEquals('You are helpful.', $body['system_instruction']);
    }

    public function testChatRequestIncludesTools(): void {
        $tool = new Tool('search', 'Search web', ['type' => 'object', 'properties' => []], fn() => '');
        $messages = [new Message('user', 'Search for PHP')];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            ['tools' => [$tool]],
            'https://example.com',
            []
        );

        $body = json_decode($request->getBody(), true);

        $this->assertArrayHasKey('tools', $body);
        $this->assertEquals('function', $body['tools'][0]['type']);
        $this->assertEquals('search', $body['tools'][0]['name']);
    }

    public function testChatRequestWithGenerationConfig(): void {
        $messages = [new Message('user', 'Hi')];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            ['temperature' => 0.7, 'max_tokens' => 512, 'thinking_level' => 'low'],
            'https://example.com',
            []
        );

        $body = json_decode($request->getBody(), true);

        $this->assertEquals(0.7, $body['generation_config']['temperature']);
        $this->assertEquals(512, $body['generation_config']['max_output_tokens']);
        $this->assertEquals('low', $body['generation_config']['thinking_level']);
    }

    public function testChatRequestWithStopSequences(): void {
        $messages = [new Message('user', 'Hi')];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            ['stop' => ['END', 'STOP']],
            'https://example.com',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals(['END', 'STOP'], $body['generation_config']['stop_sequences']);
    }

    public function testChatRequestWithStopString(): void {
        $messages = [new Message('user', 'Hi')];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            ['stop' => 'STOP'],
            'https://example.com',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals(['STOP'], $body['generation_config']['stop_sequences']);
    }

    public function testChatRequestWithTopP(): void {
        $messages = [new Message('user', 'Hi')];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            ['top_p' => 0.9],
            'https://example.com',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals(0.9, $body['generation_config']['top_p']);
    }

    public function testBuildStreamChatRequestDirectly(): void {
        $messages = [new Message('user', 'Hi')];
        $request = $this->builder->buildStreamChatRequest(
            'gemini-3.5-flash',
            $messages,
            [],
            'https://generativelanguage.googleapis.com/v1beta/interactions:stream',
            ['Content-Type' => 'application/json']
        );

        $this->assertEquals('POST', $request->getMethod());
        $this->assertStringContainsString('interactions:stream', $request->getUrl());
        $body = json_decode($request->getBody(), true);
        $this->assertEquals('gemini-3.5-flash', $body['model']);
    }

    public function testStreamRequestBodyMatchesChatBody(): void {
        $messages = [new Message('user', 'Hi')];
        $streamRequest = $this->builder->buildStreamChatRequest(
            'gemini-3.5-flash',
            $messages,
            [],
            'https://example.com',
            []
        );

        $body = json_decode($streamRequest->getBody(), true);

        $this->assertEquals('gemini-3.5-flash', $body['model']);
        $this->assertFalse($body['store']);
    }

    public function testPreviousInteractionIdInStatefulMode(): void {
        $messages = [new Message('user', 'Follow-up')];
        $request = $this->builder->buildChatRequest(
            'gemini-3.5-flash',
            $messages,
            ['previous_interaction_id' => 'interaction_abc123'],
            'https://example.com',
            []
        );

        $body = json_decode($request->getBody(), true);

        $this->assertEquals('interaction_abc123', $body['previous_interaction_id']);
        // store should not be set in stateful mode
        $this->assertArrayNotHasKey('store', $body);
    }

    // =========================================================================
    // Integration: GoogleClient uses Interactions API for gemini-3.x
    // =========================================================================

    public function testGoogleClientUsesInteractionsEndpointForGemini3(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));

        $fakeHttp = $this->fakeInteractionsResponse();
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $url = $fakeHttp->getLastRequest()->getUrl();
        $this->assertStringContainsString('interactions', $url);
        $this->assertStringNotContainsString('generateContent', $url);
        $this->assertStringContainsString('key=test-key', $url);
    }

    public function testGoogleClientSendsModelInBodyForInteractions(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));

        $fakeHttp = $this->fakeInteractionsResponse();
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $this->assertEquals('gemini-3.5-flash', $body['model']);
        $this->assertFalse($body['store']);
    }

    public function testGoogleClientVertexUsesCorrectInteractionsEndpoint(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            projectId: 'my-project',
            location: 'us-central1',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
        ));

        $fakeHttp = $this->fakeInteractionsResponse();
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $url = $fakeHttp->getLastRequest()->getUrl();
        $this->assertStringContainsString('us-central1-aiplatform.googleapis.com', $url);
        $this->assertStringContainsString('interactions', $url);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakeInteractionsResponse(): FakeHttpClient {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'interaction_abc123',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'text', 'text' => 'Hello!'],
            ],
            'usage' => [
                'input_tokens' => 5,
                'output_tokens' => 3,
                'total_tokens' => 8,
            ],
        ])));

        return $fakeHttp;
    }
}
