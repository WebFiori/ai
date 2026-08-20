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
use WebFiori\Ai\Provider\Formatter\AnthropicFormatter;
use WebFiori\Ai\Provider\Formatter\OpenAIFormatter;
use WebFiori\Ai\Provider\Formatter\ProviderFormatterInterface;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for #93: Vertex AI Model Garden support via publisher routing.
 * Tests for #111: ProviderFormatterInterface and formatter extraction.
 */
class VertexModelGardenTest extends TestCase {
    // =========================================================================
    // GoogleClientConfig publisher field
    // =========================================================================

    public function testDefaultPublisherIsGoogle(): void {
        $config = new GoogleClientConfig(model: 'gemini-2.5-flash', apiKey: 'key');
        $this->assertEquals('google', $config->publisher);
    }

    public function testCustomPublisher(): void {
        $config = new GoogleClientConfig(
            model: 'claude-haiku-4-5@20251001',
            projectId: 'my-project',
            location: 'us-east5',
            publisher: 'anthropic',
        );
        $this->assertEquals('anthropic', $config->publisher);
    }

    public function testPublisherInToArray(): void {
        $config = new GoogleClientConfig(
            model: 'llama-3',
            projectId: 'my-project',
            publisher: 'meta',
        );
        $arr = $config->toArray();
        $this->assertEquals('meta', $arr['publisher']);
    }

    // =========================================================================
    // GoogleClient publisher routing
    // =========================================================================

    public function testGooglePublisherUsesGeminiFormat(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-key',
        ));

        $this->assertEquals('google', $client->getName());
    }

    public function testAnthropicPublisherSetsName(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'claude-haiku-4-5@20251001',
            projectId: 'my-project',
            location: 'us-east5',
            credentials: __DIR__.'/../../../keys/vertex-ai-key.json',
            api: GoogleApi::VERTEX_AI,
            publisher: 'anthropic',
        ));

        $this->assertEquals('vertex:anthropic', $client->getName());
    }

    public function testMetaPublisherSetsName(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'llama-3-70b',
            projectId: 'my-project',
            location: 'us-central1',
            credentials: __DIR__.'/../../../keys/vertex-ai-key.json',
            api: GoogleApi::VERTEX_AI,
            publisher: 'meta',
        ));

        $this->assertEquals('vertex:meta', $client->getName());
    }

    public function testAnthropicPublisherUsesRawPredictEndpoint(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'claude-haiku-4-5@20251001',
            projectId: 'my-project',
            location: 'us-east5',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
            publisher: 'anthropic',
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'model' => 'claude-haiku-4-5@20251001',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])));
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);

        // Verify URL uses rawPredict and the correct publisher path
        $url = $fakeHttp->getLastRequest()->getUrl();
        $this->assertStringContainsString('publishers/anthropic', $url);
        $this->assertStringContainsString(':rawPredict', $url);
        $this->assertStringContainsString('us-east5-aiplatform.googleapis.com', $url);
        $this->assertStringContainsString('claude-haiku-4-5@20251001', $url);
    }

    public function testAnthropicPublisherSendsAnthropicFormat(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'claude-haiku-4-5@20251001',
            projectId: 'my-project',
            location: 'us-east5',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
            publisher: 'anthropic',
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'model' => 'claude-haiku-4-5@20251001',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])));
        $client->setHttpClient($fakeHttp);

        $client->chat([
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hi'),
        ]);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);

        // Anthropic format: 'messages' key, 'system' at top level
        $this->assertArrayHasKey('messages', $body);
        $this->assertArrayHasKey('system', $body);
        $this->assertEquals('You are helpful.', $body['system']);
        $this->assertEquals('user', $body['messages'][0]['role']);
        $this->assertEquals('claude-haiku-4-5@20251001', $body['model']);
        $this->assertArrayHasKey('max_tokens', $body);
        // NOT Gemini format
        $this->assertArrayNotHasKey('contents', $body);
    }

    public function testAnthropicPublisherParsesAnthropicResponse(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'claude-haiku-4-5@20251001',
            projectId: 'my-project',
            location: 'us-east5',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
            publisher: 'anthropic',
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'PHP is great!']],
            'model' => 'claude-haiku-4-5@20251001',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 8],
        ])));
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'What is PHP?')]);

        $this->assertEquals('PHP is great!', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertEquals(10, $response->getUsage()->getPromptTokens());
        $this->assertEquals(8, $response->getUsage()->getCompletionTokens());
    }

    public function testOpenAICompatPublisherSendsOpenAIFormat(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'llama-3-70b',
            projectId: 'my-project',
            location: 'us-central1',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
            publisher: 'meta',
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'model' => 'llama-3-70b',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ])));
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $url = $fakeHttp->getLastRequest()->getUrl();

        // OpenAI format: 'messages' key
        $this->assertArrayHasKey('messages', $body);
        // NOT Gemini format
        $this->assertArrayNotHasKey('contents', $body);
        // rawPredict endpoint
        $this->assertStringContainsString('publishers/meta', $url);
        $this->assertStringContainsString(':rawPredict', $url);
    }

    public function testAnthropicPublisherWithToolCalling(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'claude-haiku-4-5@20251001',
            projectId: 'my-project',
            location: 'us-east5',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
            publisher: 'anthropic',
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_456',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'call_1',
                'name' => 'get_weather',
                'input' => ['city' => 'Amman'],
            ]],
            'model' => 'claude-haiku-4-5@20251001',
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 15, 'output_tokens' => 8],
        ])));
        $client->setHttpClient($fakeHttp);

        $weatherTool = new Tool(
            'get_weather',
            'Gets weather',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            fn(array $args) => '25°C'
        );

        $response = $client->chat(
            [new Message('user', 'Weather in Amman?')],
            ['tools' => [$weatherTool]]
        );

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('get_weather', $response->getMessage()->getToolCalls()[0]->getName());

        // Verify Anthropic tool format was sent
        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $this->assertEquals('input_schema', array_keys($body['tools'][0])[2]);
    }

    // =========================================================================
    // ProviderFormatterInterface compliance
    // =========================================================================

    public function testAnthropicFormatterImplementsInterface(): void {
        $config = new \WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig(apiKey: 'test');
        $formatter = new AnthropicFormatter($config);

        $this->assertInstanceOf(ProviderFormatterInterface::class, $formatter);
    }

    public function testOpenAIFormatterImplementsInterface(): void {
        $config = new \WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig(apiKey: 'test');
        $formatter = new OpenAIFormatter($config);

        $this->assertInstanceOf(ProviderFormatterInterface::class, $formatter);
    }

    public function testAnthropicFormatterBuildsChatRequest(): void {
        $config = new \WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-20250514',
        );
        $formatter = new AnthropicFormatter($config);

        $request = $formatter->buildChatRequest(
            [new Message('user', 'Hello')],
            [],
            'https://api.anthropic.com/v1/messages',
            ['Content-Type' => 'application/json', 'x-api-key' => 'test-key']
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('claude-sonnet-4-20250514', $body['model']);
        $this->assertArrayHasKey('max_tokens', $body);
        $this->assertEquals('user', $body['messages'][0]['role']);
    }

    public function testOpenAIFormatterBuildsChatRequest(): void {
        $config = new \WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig(
            apiKey: 'test-key',
            model: 'gpt-4o',
        );
        $formatter = new OpenAIFormatter($config);

        $request = $formatter->buildChatRequest(
            [new Message('user', 'Hello')],
            [],
            'https://api.openai.com/v1/chat/completions',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer test-key']
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('gpt-4o', $body['model']);
        $this->assertEquals('user', $body['messages'][0]['role']);
        $this->assertEquals('Hello', $body['messages'][0]['content']);
    }
}
