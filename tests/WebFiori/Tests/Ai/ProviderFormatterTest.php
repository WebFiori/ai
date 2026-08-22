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
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\Formatter\AnthropicFormatter;
use WebFiori\Ai\Provider\Formatter\OpenAIFormatter;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\AnthropicBuiltInTool;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Tests for ProviderFormatterInterface implementations.
 */
class ProviderFormatterTest extends TestCase {
    // =========================================================================
    // AnthropicFormatter — error handling
    // =========================================================================

    public function testAnthropicHandleErrorPassesSuccess(): void {
        $formatter = $this->anthropicFormatter();
        $response = new HttpResponse(200, [], '{}');
        $formatter->handleErrorResponse($response); // Should not throw
        $this->assertTrue(true);
    }

    public function testAnthropicHandleError401(): void {
        $formatter = $this->anthropicFormatter();
        $response = new HttpResponse(401, [], json_encode(['error' => ['message' => 'Invalid key', 'type' => 'auth']]));
        $this->expectException(AuthenticationException::class);
        $formatter->handleErrorResponse($response);
    }

    public function testAnthropicHandleError429(): void {
        $formatter = $this->anthropicFormatter();
        $response = new HttpResponse(429, ['retry-after' => '30'], json_encode(['error' => ['message' => 'Rate limited']]));
        $this->expectException(RateLimitException::class);
        $formatter->handleErrorResponse($response);
    }

    public function testAnthropicHandleError529(): void {
        $formatter = $this->anthropicFormatter();
        $response = new HttpResponse(529, [], json_encode(['error' => ['message' => 'Overloaded', 'type' => 'overloaded_error']]));
        $this->expectException(ProviderException::class);
        $formatter->handleErrorResponse($response);
    }

    public function testAnthropicHandleError500(): void {
        $formatter = $this->anthropicFormatter();
        $response = new HttpResponse(500, [], json_encode(['error' => ['message' => 'Server error']]));
        $this->expectException(ProviderException::class);
        $formatter->handleErrorResponse($response);
    }

    // =========================================================================
    // AnthropicFormatter — unsupported features
    // =========================================================================

    public function testAnthropicBuildEmbedRequestThrows(): void {
        $formatter = $this->anthropicFormatter();
        $this->expectException(UnsupportedFeatureException::class);
        $formatter->buildEmbedRequest('text', [], '', []);
    }

    public function testAnthropicBuildImageRequestThrows(): void {
        $formatter = $this->anthropicFormatter();
        $this->expectException(UnsupportedFeatureException::class);
        $formatter->buildImageRequest(new ImageRequest('prompt'), '', []);
    }

    public function testAnthropicParseEmbedResponseThrows(): void {
        $formatter = $this->anthropicFormatter();
        $this->expectException(UnsupportedFeatureException::class);
        $formatter->parseEmbedResponse(new HttpResponse(200, [], '{}'));
    }

    public function testAnthropicParseImageResponseThrows(): void {
        $formatter = $this->anthropicFormatter();
        $this->expectException(UnsupportedFeatureException::class);
        $formatter->parseImageResponse(new HttpResponse(200, [], '{}'));
    }

    // =========================================================================
    // AnthropicFormatter — request building
    // =========================================================================

    public function testAnthropicBuildStreamChatRequest(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildStreamChatRequest(
            [new Message('user', 'Hi')],
            [],
            'https://api.anthropic.com/v1/messages',
            ['Content-Type' => 'application/json']
        );

        $body = json_decode($request->getBody(), true);
        $this->assertTrue($body['stream']);
    }

    public function testAnthropicBuildChatWithSystemMessage(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('system', 'Be helpful.'), new Message('user', 'Hi')],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('Be helpful.', $body['system']);
        $this->assertCount(1, $body['messages']); // system filtered out
    }

    public function testAnthropicBuildChatWithTools(): void {
        $formatter = $this->anthropicFormatter();
        $tool = new Tool('search', 'Search', ['type' => 'object', 'properties' => []], fn() => '');

        $request = $formatter->buildChatRequest(
            [new Message('user', 'Search')],
            ['tools' => [$tool]],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $this->assertEquals('input_schema', array_key_last($body['tools'][0]));
    }

    public function testAnthropicBuildChatWithJsonMode(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', 'Give JSON')],
            ['json_mode' => true],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertStringContainsString('JSON', $body['system'] ?? '');
    }

    public function testAnthropicBuildChatWithJsonSchema(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', 'Give JSON')],
            ['json_schema' => ['type' => 'object']],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertStringContainsString('schema', $body['system'] ?? '');
    }

    public function testAnthropicFormatMultimodalMessage(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::text('Analyze this.'),
                ContentPart::imageBase64(base64_encode('fake-png'), 'image/png'),
            ])],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertIsArray($body['messages'][0]['content']);
        $this->assertEquals('text', $body['messages'][0]['content'][0]['type']);
        $this->assertEquals('image', $body['messages'][0]['content'][1]['type']);
    }

    public function testAnthropicFormatToolCallMessage(): void {
        $formatter = $this->anthropicFormatter();
        $toolCall = new ToolCall('call_1', 'search', ['q' => 'PHP']);
        $msg = new Message('assistant', 'Searching...', [$toolCall]);

        $request = $formatter->buildChatRequest(
            [$msg],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $toolUseBlock = array_values(array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_use'))[0];
        $this->assertEquals('search', $toolUseBlock['name']);
    }

    public function testAnthropicFormatToolResultMessage(): void {
        $formatter = $this->anthropicFormatter();
        $result = new ToolResult('call_1', 'result text', 'search');
        $msg = new Message('tool', '', [], $result);

        $request = $formatter->buildChatRequest(
            [$msg],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('tool_result', $body['messages'][0]['content'][0]['type']);
        $this->assertEquals('call_1', $body['messages'][0]['content'][0]['tool_use_id']);
    }

    public function testAnthropicParseChatResponseWithToolUse(): void {
        $formatter = $this->anthropicFormatter();
        $response = new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'call_1',
                'name' => 'search',
                'input' => ['q' => 'PHP'],
            ]],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]));

        $chatResponse = $formatter->parseChatResponse($response);
        $this->assertTrue($chatResponse->hasToolCalls());
        $this->assertEquals('tool_calls', $chatResponse->getFinishReason());
    }

    public function testAnthropicStreamingExecute(): void {
        $formatter = $this->anthropicFormatter();

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"model\":\"claude-sonnet-4-20250514\",\"usage\":{\"input_tokens\":10}}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\" World\"}}\n\n",
            "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n",
            "data: {\"type\":\"message_stop\"}\n\n",
        ]);

        $tokens = [];
        $completionResponse = null;
        $request = $formatter->buildStreamChatRequest(
            [new Message('user', 'Hi')],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $formatter->executeStreamChat(
            $request,
            $fakeHttp,
            function (string $t) use (&$tokens) { $tokens[] = $t; },
            function ($r) use (&$completionResponse) { $completionResponse = $r; },
            null
        );

        $this->assertEquals(['Hello', ' World'], $tokens);
        $this->assertNotNull($completionResponse);
        $this->assertEquals('Hello World', $completionResponse->getMessage()->getContent());
    }

    // =========================================================================
    // OpenAIFormatter — error handling
    // =========================================================================

    public function testOpenAIHandleError401(): void {
        $formatter = $this->openAIFormatter();
        $response = new HttpResponse(401, [], json_encode(['error' => ['message' => 'Invalid key']]));
        $this->expectException(AuthenticationException::class);
        $formatter->handleErrorResponse($response);
    }

    public function testOpenAIHandleError429WithRetryAfter(): void {
        $formatter = $this->openAIFormatter();
        $response = new HttpResponse(429, ['Retry-After' => '60'], json_encode(['error' => ['message' => 'Rate limit']]));
        try {
            $formatter->handleErrorResponse($response);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertEquals(60, $e->getRetryAfterSeconds());
        }
    }

    public function testOpenAIHandleError500(): void {
        $formatter = $this->openAIFormatter();
        $response = new HttpResponse(500, [], json_encode(['error' => ['message' => 'Server error', 'code' => 'server_error']]));
        $this->expectException(ProviderException::class);
        $formatter->handleErrorResponse($response);
    }

    // =========================================================================
    // OpenAIFormatter — request building
    // =========================================================================

    public function testOpenAIBuildChatWithTools(): void {
        $formatter = $this->openAIFormatter();
        $tool = new Tool('search', 'Search', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], fn() => '');

        $request = $formatter->buildChatRequest(
            [new Message('user', 'Search for PHP')],
            ['tools' => [$tool]],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $this->assertEquals('function', $body['tools'][0]['type']);
        $this->assertEquals('search', $body['tools'][0]['function']['name']);
    }

    public function testOpenAIBuildChatWithJsonSchema(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', 'JSON')],
            ['json_schema' => ['name' => 'result', 'schema' => ['type' => 'object']]],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('json_schema', $body['response_format']['type']);
    }

    public function testOpenAIBuildEmbedRequest(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildEmbedRequest(
            'Hello world',
            [],
            'https://api.openai.com/v1/embeddings',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('Hello world', $body['input']);
        $this->assertArrayHasKey('model', $body);
    }

    public function testOpenAIBuildImageRequest(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildImageRequest(
            new ImageRequest('A cat'),
            'https://api.openai.com/v1/images/generations',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('A cat', $body['prompt']);
        $this->assertArrayHasKey('model', $body);
    }

    public function testOpenAIParseEmbedResponse(): void {
        $formatter = $this->openAIFormatter();
        $response = new HttpResponse(200, [], json_encode([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ]));

        $embedResponse = $formatter->parseEmbedResponse($response);
        $this->assertCount(1, $embedResponse->getVectors());
        $this->assertEquals([0.1, 0.2, 0.3], $embedResponse->getVectors()[0]);
    }

    public function testOpenAIParseImageResponse(): void {
        $formatter = $this->openAIFormatter();
        $response = new HttpResponse(200, [], json_encode([
            'data' => [['url' => 'https://example.com/img.png', 'revised_prompt' => 'A nice cat']],
        ]));

        $imageResponse = $formatter->parseImageResponse($response);
        $this->assertCount(1, $imageResponse->getImages());
    }

    public function testOpenAIFormatMultimodalMessage(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::text('What is in this?'),
                ContentPart::imageUrl('https://example.com/img.jpg'),
            ])],
            [],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertIsArray($content);
        $this->assertEquals('text', $content[0]['type']);
        $this->assertEquals('image_url', $content[1]['type']);
    }

    public function testOpenAIFormatToolCallMessage(): void {
        $formatter = $this->openAIFormatter();
        $toolCall = new ToolCall('call_1', 'search', ['q' => 'PHP']);
        $msg = new Message('assistant', '', [$toolCall]);

        $request = $formatter->buildChatRequest(
            [$msg],
            [],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertArrayHasKey('tool_calls', $body['messages'][0]);
    }

    public function testOpenAIFormatToolResultMessage(): void {
        $formatter = $this->openAIFormatter();
        $result = new ToolResult('call_1', 'result text', 'search');
        $msg = new Message('tool', '', [], $result);

        $request = $formatter->buildChatRequest(
            [$msg],
            [],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('call_1', $body['messages'][0]['tool_call_id']);
        $this->assertEquals('result text', $body['messages'][0]['content']);
    }

    public function testOpenAIStreamingExecute(): void {
        $formatter = $this->openAIFormatter();

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"id\":\"chatcmpl-1\",\"model\":\"gpt-4o\",\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n",
            "data: {\"id\":\"chatcmpl-1\",\"model\":\"gpt-4o\",\"choices\":[{\"delta\":{\"content\":\" World\"},\"finish_reason\":\"stop\"}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $tokens = [];
        $completionResponse = null;
        $request = $formatter->buildStreamChatRequest(
            [new Message('user', 'Hi')],
            [],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $formatter->executeStreamChat(
            $request,
            $fakeHttp,
            function (string $t) use (&$tokens) { $tokens[] = $t; },
            function ($r) use (&$completionResponse) { $completionResponse = $r; },
            null
        );

        $this->assertEquals(['Hello', ' World'], $tokens);
        $this->assertNotNull($completionResponse);
    }

    // =========================================================================
    // AnthropicFormatter — multimodal edge cases
    // =========================================================================

    public function testAnthropicFormatImageBase64Message(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::imageBase64(base64_encode('fake'), 'image/jpeg'),
            ])],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('image', $content[0]['type']);
        $this->assertEquals('base64', $content[0]['source']['type']);
        $this->assertEquals('image/jpeg', $content[0]['source']['media_type']);
    }

    public function testAnthropicFormatDocumentAsPdf(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::document(base64_encode('fake-pdf'), 'application/pdf'),
            ])],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('document', $content[0]['type']);
    }

    public function testAnthropicFormatDocumentAsImageType(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::document(base64_encode('fake-png'), 'image/png'),
            ])],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('image', $content[0]['type']);
    }

    public function testAnthropicFormatDocumentAsText(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::document(base64_encode('plain text content'), 'text/plain'),
            ])],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('text', $content[0]['type']);
    }

    public function testAnthropicBuildChatWithTemperature(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', 'Hi')],
            ['temperature' => 0.5, 'top_p' => 0.9, 'top_k' => 40],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals(0.5, $body['temperature']);
        $this->assertEquals(0.9, $body['top_p']);
        $this->assertEquals(40, $body['top_k']);
    }

    public function testAnthropicBuildChatWithStop(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', 'Hi')],
            ['stop' => ['STOP', 'END']],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals(['STOP', 'END'], $body['stop_sequences']);
    }

    public function testAnthropicBuiltInToolsFormatted(): void {
        $formatter = $this->anthropicFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', 'Use computer')],
            ['built_in_tools' => [\WebFiori\Ai\Tool\AnthropicBuiltInTool::BASH]],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $this->assertEquals('bash_20241022', $body['tools'][0]['type']);
    }

    public function testAnthropicWrongBuiltInToolThrows(): void {
        $formatter = $this->anthropicFormatter();
        $this->expectException(UnsupportedFeatureException::class);
        $formatter->buildChatRequest(
            [new Message('user', 'Use Google')],
            ['built_in_tools' => [\WebFiori\Ai\Tool\GoogleBuiltInTool::GOOGLE_SEARCH]],
            'https://api.anthropic.com/v1/messages',
            []
        );
    }

    public function testAnthropicStreamingWithToolCall(): void {
        $formatter = $this->anthropicFormatter();

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"model\":\"claude-sonnet-4-20250514\",\"usage\":{\"input_tokens\":10}}}\n\n",
            "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"tool_use\",\"id\":\"call_1\",\"name\":\"search\"}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\\\"q\\\":\"}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"\\\"PHP\\\"}\"  }}\n\n",
            "data: {\"type\":\"content_block_stop\",\"index\":0}\n\n",
            "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"tool_use\"},\"usage\":{\"output_tokens\":10}}\n\n",
        ]);

        $completionResponse = null;
        $request = $formatter->buildStreamChatRequest(
            [new Message('user', 'Search PHP')],
            [],
            'https://api.anthropic.com/v1/messages',
            []
        );

        $formatter->executeStreamChat(
            $request,
            $fakeHttp,
            fn(string $t) => null,
            function ($r) use (&$completionResponse) { $completionResponse = $r; },
            null
        );

        $this->assertNotNull($completionResponse);
        $this->assertTrue($completionResponse->hasToolCalls());
        $this->assertEquals('search', $completionResponse->getMessage()->getToolCalls()[0]->getName());
    }

    // =========================================================================
    // OpenAIFormatter — additional edge cases
    // =========================================================================

    public function testOpenAIFormatBase64ImageMessage(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::imageBase64(base64_encode('fake'), 'image/png'),
            ])],
            [],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('image_url', $content[0]['type']);
        $this->assertStringContainsString('data:image/png;base64,', $content[0]['image_url']['url']);
    }

    public function testOpenAIFormatDocumentPdfMessage(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::document(base64_encode('pdf'), 'application/pdf'),
            ])],
            [],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('file', $content[0]['type']);
    }

    public function testOpenAIBuildChatWithJsonMode(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', 'JSON')],
            ['json_mode' => true],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('json_object', $body['response_format']['type']);
    }

    public function testOpenAIParseChatResponseWithToolCalls(): void {
        $formatter = $this->openAIFormatter();
        $response = new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'search', 'arguments' => '{"q":"PHP"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]));

        $chatResponse = $formatter->parseChatResponse($response);
        $this->assertTrue($chatResponse->hasToolCalls());
        $this->assertEquals('search', $chatResponse->getMessage()->getToolCalls()[0]->getName());
    }

    public function testOpenAIHandleError403(): void {
        $formatter = $this->openAIFormatter();
        $response = new HttpResponse(403, [], json_encode(['error' => ['message' => 'Forbidden']]));
        $this->expectException(AuthenticationException::class);
        $formatter->handleErrorResponse($response);
    }

    public function testOpenAIBuiltInToolsFormatted(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', 'Search')],
            ['built_in_tools' => [\WebFiori\Ai\Tool\OpenAIBuiltInTool::WEB_SEARCH]],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
    }

    public function testOpenAIWrongBuiltInToolThrows(): void {
        $formatter = $this->openAIFormatter();
        $this->expectException(UnsupportedFeatureException::class);
        $formatter->buildChatRequest(
            [new Message('user', 'Search')],
            ['built_in_tools' => [\WebFiori\Ai\Tool\GoogleBuiltInTool::GOOGLE_SEARCH]],
            'https://api.openai.com/v1/chat/completions',
            []
        );
    }

    public function testOpenAIFormatDocumentImageType(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildChatRequest(
            [new Message('user', [
                ContentPart::document(base64_encode('img'), 'image/png'),
            ])],
            [],
            'https://api.openai.com/v1/chat/completions',
            []
        );

        $body = json_decode($request->getBody(), true);
        $content = $body['messages'][0]['content'];
        $this->assertEquals('image_url', $content[0]['type']);
    }

    public function testOpenAIBuildEmbedRequestWithDimensions(): void {
        $formatter = $this->openAIFormatter();
        $request = $formatter->buildEmbedRequest(
            'Hello',
            ['dimensions' => 512],
            'https://api.openai.com/v1/embeddings',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals(512, $body['dimensions']);
    }

    public function testOpenAIBuildImageRequestWithStyle(): void {
        $formatter = $this->openAIFormatter();
        $imageReq = new ImageRequest('A cat', style: 'vivid');

        $request = $formatter->buildImageRequest(
            $imageReq,
            'https://api.openai.com/v1/images/generations',
            []
        );

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('vivid', $body['style']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function anthropicFormatter(): AnthropicFormatter {
        return new AnthropicFormatter(new AnthropicClientConfig(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-20250514',
        ));
    }

    private function openAIFormatter(): OpenAIFormatter {
        return new OpenAIFormatter(new OpenAIClientConfig(
            apiKey: 'test-key',
            model: 'gpt-4o',
        ));
    }
}
