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
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Extended unit tests for the Anthropic provider covering streaming, multimodal, and edge cases.
 */
class AnthropicClientExtendedTest extends TestCase {
    // ==================== STREAMING ====================

    /**
     * @test
     */
    public function testStreamChat() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 10]]]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => ' world']]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n",
            "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]) . "\n\n",
            "data: [DONE]\n\n",
        ]);

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $tokens = [];
        $completedResponse = null;

        $provider->streamChat(
            [new Message('user', 'Say hello')],
            function (string $token) use (&$tokens) { $tokens[] = $token; },
            function ($response) use (&$completedResponse) { $completedResponse = $response; }
        );

        $this->assertEquals(['Hello', ' world'], $tokens);
        $this->assertNotNull($completedResponse);
        $this->assertEquals('Hello world', $completedResponse->getMessage()->getContent());
        $this->assertEquals('stop', $completedResponse->getFinishReason());
        $this->assertEquals(10, $completedResponse->getUsage()->getPromptTokens());
        $this->assertEquals(5, $completedResponse->getUsage()->getCompletionTokens());
        $this->assertEquals('claude-sonnet-4-20250514', $completedResponse->getModel());
    }

    /**
     * @test
     */
    public function testStreamChatRequestFormat() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 1]]]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]) . "\n\n",
            "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]) . "\n\n",
        ]);

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {}
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertTrue($body['stream']);
        $this->assertArrayHasKey('messages', $body);
    }

    /**
     * @test
     */
    public function testStreamChatWithToolCalls() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 20]]]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'get_weather']]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"location"']]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'input_json_delta', 'partial_json' => ':"Paris"}']]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n",
            "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 10]]) . "\n\n",
        ]);

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $completedResponse = null;
        $provider->streamChat(
            [new Message('user', 'Weather in Paris?')],
            function (string $token) {},
            function ($response) use (&$completedResponse) { $completedResponse = $response; }
        );

        $this->assertNotNull($completedResponse);
        $this->assertTrue($completedResponse->hasToolCalls());
        $toolCalls = $completedResponse->getMessage()->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertEquals('toolu_01', $toolCalls[0]->getId());
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['location' => 'Paris'], $toolCalls[0]->getArguments());
        $this->assertEquals('tool_calls', $completedResponse->getFinishReason());
    }

    /**
     * @test
     */
    public function testStreamChatEmptyData() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: \n\n",
            "data: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 1]]]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'OK']]) . "\n\n",
            "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]) . "\n\n",
        ]);

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $tokens = [];
        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$tokens) { $tokens[] = $token; }
        );

        $this->assertEquals(['OK'], $tokens);
    }

    // ==================== TOOL CALLING EXTENDED ====================

    /**
     * @test
     */
    public function testToolCallingWithMultipleTools() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['location' => 'London']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'get_time', 'input' => ['timezone' => 'UTC']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Weather and time?')]);

        $this->assertTrue($response->hasToolCalls());
        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertCount(2, $toolCalls);
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals('get_time', $toolCalls[1]->getName());
    }

    /**
     * @test
     */
    public function testToolResultFormatting() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_final',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'It is 22C in London.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 80, 'output_tokens' => 10],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $messages = [
            new Message('user', 'Weather in London?'),
            new Message('assistant', '', [new ToolCall('toolu_1', 'get_weather', ['location' => 'London'])]),
            new Message('tool', '', [], new ToolResult('toolu_1', '{"temp": 22}')),
        ];

        $response = $provider->chat($messages);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        // Tool call message should be assistant with tool_use
        $this->assertEquals('assistant', $body['messages'][1]['role']);
        $this->assertEquals('tool_use', $body['messages'][1]['content'][0]['type']);
        // Tool result should be user with tool_result
        $this->assertEquals('user', $body['messages'][2]['role']);
        $this->assertEquals('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertEquals('toolu_1', $body['messages'][2]['content'][0]['tool_use_id']);
    }

    /**
     * @test
     */
    public function testToolsFormattedInRequest() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'OK']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $tool = new Tool(
            'search',
            'Search something',
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
            function (array $args): string { return '[]'; }
        );

        $provider->chat(
            [new Message('user', 'Search for PHP')],
            ['tools' => [$tool]]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $this->assertEquals('search', $body['tools'][0]['name']);
        $this->assertEquals('Search something', $body['tools'][0]['description']);
        $this->assertArrayHasKey('input_schema', $body['tools'][0]);
    }

    // ==================== GENERATION OPTIONS ====================

    /**
     * @test
     */
    public function testTemperatureAndTopP() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['temperature' => 0.3, 'top_p' => 0.8, 'top_k' => 40]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals(0.3, $body['temperature']);
        $this->assertEquals(0.8, $body['top_p']);
        $this->assertEquals(40, $body['top_k']);
    }

    /**
     * @test
     */
    public function testStopSequences() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'stop_sequence',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['stop' => ['END', 'STOP']]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals(['END', 'STOP'], $body['stop_sequences']);
    }

    /**
     * @test
     */
    public function testStopSequenceAsString() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['stop' => 'END']
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals(['END'], $body['stop_sequences']);
    }

    /**
     * @test
     */
    public function testJsonModeInjectsSystem() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => '{"key":"value"}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Return JSON')],
            ['json_mode' => true]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertStringContainsString('Respond with valid JSON only', $body['system']);
    }

    /**
     * @test
     */
    public function testJsonSchemaInjectsSystemWithExistingSystem() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => '{"name":"Test"}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $provider->chat(
            [
                new Message('system', 'You are a helper.'),
                new Message('user', 'Give name'),
            ],
            ['json_schema' => $schema]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        // System should have the original system AND the JSON instruction appended
        $this->assertStringContainsString('You are a helper.', $body['system']);
        $this->assertStringContainsString('Respond with valid JSON only', $body['system']);
    }

    // ==================== MULTI-MODAL ====================

    /**
     * @test
     */
    public function testMultiModalImageBase64() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'I see a cat.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('What is this?'),
            ContentPart::imageBase64('iVBORw0KGgo=', 'image/png'),
        ]);

        $response = $provider->chat([$message]);
        $this->assertEquals('I see a cat.', $response->getMessage()->getContent());

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('text', $content[0]['type']);
        $this->assertEquals('What is this?', $content[0]['text']);
        $this->assertEquals('image', $content[1]['type']);
        $this->assertEquals('base64', $content[1]['source']['type']);
        $this->assertEquals('image/png', $content[1]['source']['media_type']);
    }

    /**
     * @test
     */
    public function testMultiModalDocument() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Document summary.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 5],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('Summarize.'),
            ContentPart::document(base64_encode('pdf data'), 'application/pdf'),
        ]);

        $response = $provider->chat([$message]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('document', $content[1]['type']);
        $this->assertEquals('base64', $content[1]['source']['type']);
        $this->assertEquals('application/pdf', $content[1]['source']['media_type']);
    }

    /**
     * @test
     */
    public function testMultiModalTextDocument() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'OK']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $textContent = 'Hello world text file';
        $message = new Message('user', [
            ContentPart::text('Read this.'),
            ContentPart::document(base64_encode($textContent), 'text/plain'),
        ]);

        $response = $provider->chat([$message]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        // text/plain documents should be decoded into text
        $this->assertEquals('text', $content[1]['type']);
        $this->assertEquals($textContent, $content[1]['text']);
    }

    /**
     * @test
     */
    public function testMultiModalImageDocument() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Image.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('Describe.'),
            ContentPart::document(base64_encode('image data'), 'image/jpeg'),
        ]);

        $response = $provider->chat([$message]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        // image/* documents should be formatted as image type
        $this->assertEquals('image', $content[1]['type']);
        $this->assertEquals('image/jpeg', $content[1]['source']['media_type']);
    }

    // ==================== STOP REASON MAPPING ====================

    /**
     * @test
     */
    public function testStopReasonMaxTokens() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Incomplete...']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4096],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Long story')]);
        $this->assertEquals('length', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testStopReasonToolUse() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'calc', 'input' => []]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Calculate')]);
        $this->assertEquals('tool_calls', $response->getFinishReason());
    }

    // ==================== ERROR HANDLING ====================

    /**
     * @test
     */
    public function testServerError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(500, [], json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'api_error',
                'message' => 'Internal server error',
            ],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Internal server error');
        $provider->chat([new Message('user', 'Hello')]);
    }

    /**
     * @test
     */
    public function testMaxTokensDefault() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hello')]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('max_tokens', $body);
        $this->assertIsInt($body['max_tokens']);
    }

    /**
     * @test
     */
    public function testCustomMaxTokens() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = $this->createProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['max_tokens' => 200]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals(200, $body['max_tokens']);
    }

    // ==================== HELPERS ====================

    private function createProvider(): AnthropicClient {
        return new AnthropicClient(new AnthropicClientConfig(
            apiKey: 'test-api-key',
            model: 'claude-sonnet-4-20250514',
        ));
    }
}
