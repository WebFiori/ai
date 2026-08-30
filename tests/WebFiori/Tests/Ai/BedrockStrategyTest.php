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
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Bedrock\ApiMethod;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Unit tests for Bedrock invocation strategies (ConverseStrategy and InvokeStrategy).
 */
class BedrockStrategyTest extends TestCase {
    // ==================== CONVERSE STRATEGY ====================

    /**
     * @test
     */
    public function testConverseSystemMessage() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hi']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 5, 'outputTokens' => 1],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $provider->chat([
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hello'),
        ]);

        $body = json_decode($client->getLastRequest()->getBody(), true);

        // System message should be extracted as top-level 'system'
        $this->assertArrayHasKey('system', $body);
        $this->assertEquals([['text' => 'You are helpful.']], $body['system']);

        // Messages should not contain system message
        $this->assertCount(1, $body['messages']);
        $this->assertEquals('user', $body['messages'][0]['role']);
    }

    /**
     * @test
     */
    public function testConverseTemperatureAndTopP() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hi']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['temperature' => 0.7, 'top_p' => 0.9, 'max_tokens' => 500]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);

        $this->assertEquals(0.7, $body['inferenceConfig']['temperature']);
        $this->assertEquals(0.9, $body['inferenceConfig']['topP']);
        $this->assertEquals(500, $body['inferenceConfig']['maxTokens']);
    }

    /**
     * @test
     */
    public function testConverseToolCallingRequest() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['toolUse' => [
                    'toolUseId' => 'tool_123',
                    'name' => 'get_weather',
                    'input' => ['location' => 'Paris'],
                ]],
            ]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 20, 'outputTokens' => 15],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $weatherTool = new Tool(
            'get_weather',
            'Get weather for a location',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']],
            function (array $args): string { return '{"temp": 22}'; }
        );

        $response = $provider->chat(
            [new Message('user', 'Weather in Paris?')],
            ['tools' => [$weatherTool]]
        );

        $this->assertTrue($response->hasToolCalls());
        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['location' => 'Paris'], $toolCalls[0]->getArguments());
        $this->assertEquals('tool_calls', $response->getFinishReason());

        // Verify tool format in request
        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('toolConfig', $body);
        $this->assertEquals('get_weather', $body['toolConfig']['tools'][0]['toolSpec']['name']);
    }

    /**
     * @test
     */
    public function testConverseToolResultFormatting() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'It is 22°C in Paris.']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 30, 'outputTokens' => 10],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $toolResult = new ToolResult('tool_123', '{"temp": 22}');
        $messages = [
            new Message('user', 'Weather in Paris?'),
            new Message('assistant', '', [new ToolCall('tool_123', 'get_weather', ['location' => 'Paris'])]),
            new Message('tool', '', [], $toolResult),
        ];

        $response = $provider->chat($messages);
        $this->assertEquals('It is 22°C in Paris.', $response->getMessage()->getContent());

        // Verify message formatting
        $body = json_decode($client->getLastRequest()->getBody(), true);

        // Tool call message should have assistant role with toolUse
        $this->assertEquals('assistant', $body['messages'][1]['role']);
        $this->assertArrayHasKey('toolUse', $body['messages'][1]['content'][0]);

        // Tool result message should have user role with toolResult
        $this->assertEquals('user', $body['messages'][2]['role']);
        $this->assertArrayHasKey('toolResult', $body['messages'][2]['content'][0]);
        $this->assertEquals('tool_123', $body['messages'][2]['content'][0]['toolResult']['toolUseId']);
    }

    /**
     * @test
     */
    public function testConverseMultiModalImageBase64() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'I see a cat.']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 50, 'outputTokens' => 5],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('What is in this image?'),
            ContentPart::imageBase64('iVBORw0KGgo=', 'image/png'),
        ]);

        $response = $provider->chat([$message]);
        $this->assertEquals('I see a cat.', $response->getMessage()->getContent());

        // Verify multimodal formatting
        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('What is in this image?', $content[0]['text']);
        $this->assertArrayHasKey('image', $content[1]);
        $this->assertEquals('png', $content[1]['image']['format']);
        $this->assertEquals('iVBORw0KGgo=', $content[1]['image']['source']['bytes']);
    }

    /**
     * @test
     */
    public function testConverseDocumentPdf() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'PDF content here.']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 50, 'outputTokens' => 5],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('Summarize this document.'),
            ContentPart::document(base64_encode('fake pdf data'), 'application/pdf'),
        ]);

        $response = $provider->chat([$message]);
        $this->assertEquals('PDF content here.', $response->getMessage()->getContent());

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertArrayHasKey('document', $content[1]);
        $this->assertEquals('pdf', $content[1]['document']['format']);
    }

    /**
     * @test
     */
    public function testConverseDocumentTextBased() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Noted.']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 1],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $textContent = 'Hello world text document';
        $message = new Message('user', [
            ContentPart::text('What does this say?'),
            ContentPart::document(base64_encode($textContent), 'text/plain'),
        ]);

        $response = $provider->chat([$message]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        // Text-based documents should be converted to text
        $this->assertArrayHasKey('text', $content[1]);
        $this->assertEquals($textContent, $content[1]['text']);
    }

    /**
     * @test
     */
    public function testConverseStopReasonMapping() {
        // Test max_tokens stop reason
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Incomplete...']]]],
            'stopReason' => 'max_tokens',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 100],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Tell me a long story')]);
        $this->assertEquals('length', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testConverseStopSequenceReason() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Done']]]],
            'stopReason' => 'stop_sequence',
            'usage' => ['inputTokens' => 5, 'outputTokens' => 1],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);
        $this->assertEquals('stop', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testConverseStreaming() {
        $client = new FakeHttpClient();

        // Build event stream binary messages for Converse streaming
        $chunks = [
            $this->buildEventStreamMessage('messageStart', ['role' => 'assistant']),
            $this->buildEventStreamMessage('contentBlockStart', ['contentBlockStart' => ['start' => []]]),
            $this->buildEventStreamMessage('contentBlockDelta', ['delta' => ['text' => 'Hello']]),
            $this->buildEventStreamMessage('contentBlockDelta', ['delta' => ['text' => ' world']]),
            $this->buildEventStreamMessage('contentBlockStop', []),
            $this->buildEventStreamMessage('messageStop', ['stopReason' => 'end_turn']),
            $this->buildEventStreamMessage('metadata', ['usage' => ['inputTokens' => 5, 'outputTokens' => 2]]),
        ];

        $client->addStreamingChunks($chunks);

        $provider = $this->createConverseProvider();
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
        $this->assertEquals(5, $completedResponse->getUsage()->getPromptTokens());
        $this->assertEquals(2, $completedResponse->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testConverseStreamingWithToolCalls() {
        $client = new FakeHttpClient();

        $chunks = [
            $this->buildEventStreamMessage('messageStart', ['role' => 'assistant']),
            $this->buildEventStreamMessage('contentBlockStart', [
                'contentBlockStart' => ['start' => ['toolUse' => ['toolUseId' => 'tc_1', 'name' => 'get_weather']]],
            ]),
            $this->buildEventStreamMessage('contentBlockDelta', [
                'delta' => ['toolUse' => ['input' => '{"location"']],
            ]),
            $this->buildEventStreamMessage('contentBlockDelta', [
                'delta' => ['toolUse' => ['input' => ':"London"}']],
            ]),
            $this->buildEventStreamMessage('contentBlockStop', []),
            $this->buildEventStreamMessage('messageStop', ['stopReason' => 'tool_use']),
            $this->buildEventStreamMessage('metadata', ['usage' => ['inputTokens' => 10, 'outputTokens' => 5]]),
        ];

        $client->addStreamingChunks($chunks);

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $tokens = [];
        $completedResponse = null;

        $provider->streamChat(
            [new Message('user', 'Weather in London?')],
            function (string $token) use (&$tokens) { $tokens[] = $token; },
            function ($response) use (&$completedResponse) { $completedResponse = $response; }
        );

        $this->assertNotNull($completedResponse);
        $this->assertTrue($completedResponse->hasToolCalls());
        $toolCalls = $completedResponse->getMessage()->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertEquals('tc_1', $toolCalls[0]->getId());
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['location' => 'London'], $toolCalls[0]->getArguments());
        $this->assertEquals('tool_calls', $completedResponse->getFinishReason());
    }

    /**
     * @test
     */
    public function testConverseStreamEndpoint() {
        $client = new FakeHttpClient();
        $chunks = [
            $this->buildEventStreamMessage('messageStart', ['role' => 'assistant']),
            $this->buildEventStreamMessage('contentBlockDelta', ['delta' => ['text' => 'Hi']]),
            $this->buildEventStreamMessage('contentBlockStop', []),
            $this->buildEventStreamMessage('messageStop', ['stopReason' => 'end_turn']),
            $this->buildEventStreamMessage('metadata', ['usage' => ['inputTokens' => 1, 'outputTokens' => 1]]),
        ];
        $client->addStreamingChunks($chunks);

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {}
        );

        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('/converse-stream', $url);
    }

    /**
     * @test
     */
    public function testConverseNoUsageInResponse() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hi']]]],
            'stopReason' => 'end_turn',
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);
        $this->assertEquals('Hi', $response->getMessage()->getContent());
        $this->assertNull($response->getUsage());
    }

    /**
     * @test
     */
    public function testConverseMultipleTextBlocks() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['text' => 'First part. '],
                ['text' => 'Second part.'],
            ]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 5, 'outputTokens' => 10],
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);
        $this->assertEquals('First part. Second part.', $response->getMessage()->getContent());
    }

    // ==================== INVOKE STRATEGY ====================

    /**
     * @test
     */
    public function testInvokeAnthropicFormat() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hello from Claude!']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])));

        $provider = $this->createInvokeProvider('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $provider->setHttpClient($client);

        $response = $provider->chat([
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hello'),
        ]);

        $this->assertEquals('Hello from Claude!', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertEquals(10, $response->getUsage()->getPromptTokens());
        $this->assertEquals(5, $response->getUsage()->getCompletionTokens());

        // Verify request body
        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals('bedrock-2023-05-31', $body['anthropic_version']);
        $this->assertEquals('You are helpful.', $body['system']);
        $this->assertCount(1, $body['messages']);
        $this->assertEquals('user', $body['messages'][0]['role']);
    }

    /**
     * @test
     */
    public function testInvokeAnthropicToolCalling() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [
                ['type' => 'tool_use', 'id' => 'tc_1', 'name' => 'get_weather', 'input' => ['location' => 'NYC']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
        ])));

        $provider = $this->createInvokeProvider('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $provider->setHttpClient($client);

        $weatherTool = new Tool(
            'get_weather',
            'Get weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            function (array $args): string { return '{}'; }
        );

        $response = $provider->chat(
            [new Message('user', 'Weather in NYC?')],
            ['tools' => [$weatherTool]]
        );

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('tool_calls', $response->getFinishReason());
        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['location' => 'NYC'], $toolCalls[0]->getArguments());

        // Verify tool formatting in request
        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $this->assertEquals('get_weather', $body['tools'][0]['name']);
        $this->assertEquals('Get weather', $body['tools'][0]['description']);
        $this->assertArrayHasKey('input_schema', $body['tools'][0]);
    }

    /**
     * @test
     */
    public function testInvokeAnthropicToolResult() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'It is sunny in NYC.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 30, 'output_tokens' => 10],
        ])));

        $provider = $this->createInvokeProvider('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $provider->setHttpClient($client);

        $toolResult = new ToolResult('tc_1', '{"weather": "sunny"}');
        $messages = [
            new Message('user', 'Weather in NYC?'),
            new Message('assistant', '', [new ToolCall('tc_1', 'get_weather', ['location' => 'NYC'])]),
            new Message('tool', '', [], $toolResult),
        ];

        $response = $provider->chat($messages);
        $this->assertEquals('It is sunny in NYC.', $response->getMessage()->getContent());

        $body = json_decode($client->getLastRequest()->getBody(), true);
        // Tool result should use Anthropic format
        $this->assertEquals('user', $body['messages'][2]['role']);
        $this->assertEquals('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertEquals('tc_1', $body['messages'][2]['content'][0]['tool_use_id']);
    }

    /**
     * @test
     */
    public function testInvokeLlamaFormat() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'generation' => 'Hello from Llama!',
            'stop_reason' => 'stop',
        ])));

        $provider = $this->createInvokeProvider('meta.llama3-70b-instruct-v1:0');
        $provider->setHttpClient($client);

        $response = $provider->chat([
            new Message('system', 'Be helpful.'),
            new Message('user', 'Hello'),
        ]);

        $this->assertEquals('Hello from Llama!', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());

        // Verify Llama body format
        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('prompt', $body);
        $this->assertArrayHasKey('max_gen_len', $body);
        $this->assertStringContainsString('<<SYS>>', $body['prompt']);
        $this->assertStringContainsString('Be helpful.', $body['prompt']);
    }

    /**
     * @test
     */
    public function testInvokeLlamaTemperature() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'generation' => 'Hi',
            'stop_reason' => 'stop',
        ])));

        $provider = $this->createInvokeProvider('meta.llama3-70b-instruct-v1:0');
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['temperature' => 0.5, 'max_tokens' => 200]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals(0.5, $body['temperature']);
        $this->assertEquals(200, $body['max_gen_len']);
    }

    /**
     * @test
     */
    public function testInvokeAnthropicMaxTokensStopReason() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Incomplete...']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 100],
        ])));

        $provider = $this->createInvokeProvider('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Long story')]);
        $this->assertEquals('length', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testInvokeAnthropicImageBase64() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'I see a cat.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5],
        ])));

        $provider = $this->createInvokeProvider('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('Describe this image.'),
            ContentPart::imageBase64('iVBORw0KGgo=', 'image/png'),
        ]);

        $response = $provider->chat([$message]);
        $this->assertEquals('I see a cat.', $response->getMessage()->getContent());

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('text', $content[0]['type']);
        $this->assertEquals('Describe this image.', $content[0]['text']);
        $this->assertEquals('image', $content[1]['type']);
        $this->assertEquals('base64', $content[1]['source']['type']);
        $this->assertEquals('image/png', $content[1]['source']['media_type']);
    }

    /**
     * @test
     */
    public function testInvokeAnthropicDocument() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Document content.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5],
        ])));

        $provider = $this->createInvokeProvider('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('Summarize this.'),
            ContentPart::document(base64_encode('fake pdf'), 'application/pdf'),
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
    public function testInvokeAnthropicTextDocument() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'OK.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
        ])));

        $provider = $this->createInvokeProvider('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $provider->setHttpClient($client);

        $textContent = 'Hello world';
        $message = new Message('user', [
            ContentPart::text('Read this.'),
            ContentPart::document(base64_encode($textContent), 'text/plain'),
        ]);

        $response = $provider->chat([$message]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];
        // Text documents should be decoded and used as text
        $this->assertEquals('text', $content[1]['type']);
        $this->assertEquals($textContent, $content[1]['text']);
    }

    /**
     * @test
     */
    public function testInvokeStreamingAnthropicFormat() {
        $client = new FakeHttpClient();

        // Anthropic streaming via Invoke uses SSE format with base64 encoded payloads
        $chunk1 = json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5]]]);
        $chunk2 = json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]);
        $chunk3 = json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => ' there']]);
        $chunk4 = json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]]);

        $client->addStreamingChunks([
            "data: " . json_encode(['bytes' => base64_encode($chunk1)]) . "\n\n",
            "data: " . json_encode(['bytes' => base64_encode($chunk2)]) . "\n\n",
            "data: " . json_encode(['bytes' => base64_encode($chunk3)]) . "\n\n",
            "data: " . json_encode(['bytes' => base64_encode($chunk4)]) . "\n\n",
        ]);

        $provider = $this->createInvokeProvider('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $provider->setHttpClient($client);

        $tokens = [];
        $completedResponse = null;

        $provider->streamChat(
            [new Message('user', 'Hello')],
            function (string $token) use (&$tokens) { $tokens[] = $token; },
            function ($response) use (&$completedResponse) { $completedResponse = $response; }
        );

        $this->assertEquals(['Hello', ' there'], $tokens);
        $this->assertNotNull($completedResponse);
        $this->assertEquals('Hello there', $completedResponse->getMessage()->getContent());
        $this->assertEquals('stop', $completedResponse->getFinishReason());
        $this->assertEquals(5, $completedResponse->getUsage()->getPromptTokens());
        $this->assertEquals(3, $completedResponse->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testInvokeStreamingLlamaFormat() {
        $client = new FakeHttpClient();

        $chunk1 = json_encode(['generation' => 'Hello']);
        $chunk2 = json_encode(['generation' => ' from Llama', 'stop_reason' => 'stop']);

        $client->addStreamingChunks([
            "data: " . json_encode(['bytes' => base64_encode($chunk1)]) . "\n\n",
            "data: " . json_encode(['bytes' => base64_encode($chunk2)]) . "\n\n",
        ]);

        $provider = $this->createInvokeProvider('meta.llama3-70b-instruct-v1:0');
        $provider->setHttpClient($client);

        $tokens = [];
        $completedResponse = null;

        $provider->streamChat(
            [new Message('user', 'Hello')],
            function (string $token) use (&$tokens) { $tokens[] = $token; },
            function ($response) use (&$completedResponse) { $completedResponse = $response; }
        );

        $this->assertEquals(['Hello', ' from Llama'], $tokens);
        $this->assertNotNull($completedResponse);
        $this->assertEquals('Hello from Llama', $completedResponse->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testInvokeStreamEndpoint() {
        $client = new FakeHttpClient();

        $chunk = json_encode(['generation' => 'Hi', 'stop_reason' => 'stop']);
        $client->addStreamingChunks([
            "data: " . json_encode(['bytes' => base64_encode($chunk)]) . "\n\n",
        ]);

        $provider = $this->createInvokeProvider('meta.llama3-70b-instruct-v1:0');
        $provider->setHttpClient($client);

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {}
        );

        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('/invoke-with-response-stream', $url);
    }

    /**
     * @test
     */
    public function testInvokeLlamaNoStopReason() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'generation' => 'Hello from Llama!',
        ])));

        $provider = $this->createInvokeProvider('meta.llama3-70b-instruct-v1:0');
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);
        $this->assertEquals('Hello from Llama!', $response->getMessage()->getContent());
        $this->assertNull($response->getFinishReason());
    }

    // ==================== ERROR HANDLING ====================

    /**
     * @test
     */
    public function testBedrockAuthenticationError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(403, [], json_encode([
            'message' => 'Access denied.',
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $this->expectException(\WebFiori\Ai\Exception\AuthenticationException::class);
        $provider->chat([new Message('user', 'Hi')]);
    }

    /**
     * @test
     */
    public function testBedrockRateLimitError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(429, [], json_encode([
            'message' => 'Throttling: Rate exceeded',
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $this->expectException(\WebFiori\Ai\Exception\RateLimitException::class);
        $provider->chat([new Message('user', 'Hi')]);
    }

    /**
     * @test
     */
    public function testBedrockServerError() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(500, [], json_encode([
            'message' => 'Internal server error',
        ])));

        $provider = $this->createConverseProvider();
        $provider->setHttpClient($client);

        $this->expectException(\WebFiori\Ai\Exception\ProviderException::class);
        $provider->chat([new Message('user', 'Hi')]);
    }

    // ==================== HELPERS ====================

    /**
     * Creates a Bedrock provider with Converse API method.
     */
    private function createConverseProvider(): BedrockClient {
        return new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            apiKey: 'test-bedrock-key',
            apiMethod: ApiMethod::CONVERSE,
        ));
    }

    /**
     * Creates a Bedrock provider with Invoke API method and specific model.
     */
    private function createInvokeProvider(string $model = 'anthropic.claude-3-5-sonnet-20241022-v2:0'): BedrockClient {
        return new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: $model,
            apiKey: 'test-bedrock-key',
            apiMethod: ApiMethod::INVOKE,
        ));
    }

    /**
     * Builds a binary AWS Event Stream message for testing.
     *
     * @param string $eventType The event type header value.
     * @param array $payload The JSON payload.
     *
     * @return string The binary event stream message.
     */
    private function buildEventStreamMessage(string $eventType, array $payload): string {
        $payloadBytes = json_encode($payload);

        // Build headers: ':event-type' header
        $headerName = ':event-type';
        $headerValue = $eventType;
        $headers = chr(strlen($headerName)) . $headerName;
        $headers .= chr(7); // value type 7 = string
        $headers .= pack('n', strlen($headerValue)) . $headerValue;

        $headersLength = strlen($headers);
        $totalLength = 4 + 4 + 4 + $headersLength + strlen($payloadBytes) + 4;

        // Build message: total_len + headers_len + prelude_crc + headers + payload + message_crc
        $prelude = pack('N', $totalLength) . pack('N', $headersLength);
        $preludeCrc = pack('N', crc32($prelude));
        $messageBody = $prelude . $preludeCrc . $headers . $payloadBytes;
        $messageCrc = pack('N', crc32($messageBody));

        return $messageBody . $messageCrc;
    }
}
