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
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolResponse;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Tests for #96, #97: ToolResponse in auto-execution loop and Gemini multimodal
 * function responses.
 */
class ToolResponseIntegrationTest extends TestCase {
    // =========================================================================
    // ToolResult multimodal support (#96 additions)
    // =========================================================================

    public function testToolResultCarriesParts(): void {
        $part = ContentPart::imageBase64(base64_encode('img'), 'image/png');
        $result = new ToolResult('call_1', 'text output', 'my_tool', [$part]);

        $this->assertEquals('text output', $result->getContent());
        $this->assertEquals('my_tool', $result->getName());
        $this->assertEquals('call_1', $result->getToolCallId());
        $this->assertCount(1, $result->getParts());
        $this->assertTrue($result->isMultimodal());
        $this->assertSame($part, $result->getParts()[0]);
    }

    public function testToolResultTextOnlyNotMultimodal(): void {
        $result = new ToolResult('call_1', 'text only', 'tool');

        $this->assertEmpty($result->getParts());
        $this->assertFalse($result->isMultimodal());
    }

    // =========================================================================
    // Auto-execution loop with ToolResponse (#96)
    // =========================================================================

    public function testLoopHandlesStringToolResult(): void {
        // Plain string tools continue to work unchanged
        $tool = new Tool(
            'get_info',
            'Gets info',
            ['type' => 'object', 'properties' => []],
            fn(array $args) => 'plain string result'
        );

        $fakeHttp = new FakeHttpClient();
        // First response: tool call
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['functionCall' => ['name' => 'get_info', 'args' => []]]],
                ],
                'finishReason' => 'TOOL_CODE',
            ]],
        ])));
        // Second response: final answer
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Done.']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ])));

        $client = $this->createGeminiClient();
        $client->setHttpClient($fakeHttp);

        $response = $client->chat(
            [new Message('user', 'Get info')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('Done.', $response->getMessage()->getContent());
        // Verify second request contains function response
        $requests = $fakeHttp->getRequests();
        $body = json_decode($requests[1]->getBody(), true);
        $functionRole = array_filter($body['contents'], fn($c) => ($c['role'] ?? '') === 'function');
        $this->assertNotEmpty($functionRole);
    }

    public function testLoopHandlesToolResponseTextOnly(): void {
        // ToolResponse with text only works like a plain string
        $tool = new Tool(
            'get_info',
            'Gets info',
            ['type' => 'object', 'properties' => []],
            fn(array $args) => ToolResponse::text('tool response text')
        );

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['functionCall' => ['name' => 'get_info', 'args' => []]]],
                ],
                'finishReason' => 'TOOL_CODE',
            ]],
        ])));
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Got it.']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ])));

        $client = $this->createGeminiClient();
        $client->setHttpClient($fakeHttp);

        $response = $client->chat(
            [new Message('user', 'Get info')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('Got it.', $response->getMessage()->getContent());
        $requests = $fakeHttp->getRequests();
        $body = json_decode($requests[1]->getBody(), true);
        $functionParts = array_values(array_filter($body['contents'], fn($c) => ($c['role'] ?? '') === 'function'));
        $this->assertNotEmpty($functionParts);
        $funcResp = $functionParts[0]['parts'][0]['functionResponse'];
        $this->assertEquals('tool response text', $funcResp['response']['result']);
    }

    public function testLoopHandlesToolResponseWithImages(): void {
        $imageData = base64_encode(str_repeat('X', 100)); // Small fake image
        $tool = new Tool(
            'extract_chart',
            'Extracts a chart as image',
            ['type' => 'object', 'properties' => []],
            fn(array $args) => ToolResponse::withImages(
                'Chart extracted.',
                [ContentPart::imageBase64($imageData, 'image/png')]
            )
        );

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['functionCall' => ['name' => 'extract_chart', 'args' => []]]],
                ],
                'finishReason' => 'TOOL_CODE',
            ]],
        ])));
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'The chart shows growth.']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 8],
        ])));

        $client = $this->createGeminiClient();
        $client->setHttpClient($fakeHttp);

        $response = $client->chat(
            [new Message('user', 'Extract chart')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('The chart shows growth.', $response->getMessage()->getContent());

        // Verify second request contains multimodal functionResponse
        $requests = $fakeHttp->getRequests();
        $body = json_decode($requests[1]->getBody(), true);
        $functionParts = array_values(array_filter($body['contents'], fn($c) => ($c['role'] ?? '') === 'function'));
        $this->assertNotEmpty($functionParts);

        $funcResp = $functionParts[0]['parts'][0]['functionResponse']['response'];
        // Should have content array with text + inlineData
        $this->assertArrayHasKey('content', $funcResp);
        $this->assertCount(2, $funcResp['content']);
        $this->assertEquals('Chart extracted.', $funcResp['content'][0]['text']);
        $this->assertArrayHasKey('inlineData', $funcResp['content'][1]);
        $this->assertEquals('image/png', $funcResp['content'][1]['inlineData']['mimeType']);
    }

    // =========================================================================
    // Gemini formatContents multimodal function response (#97)
    // =========================================================================

    public function testFormatContentsTextOnlyToolResultUnchanged(): void {
        $result = new ToolResult('call_1', '{"result":"ok"}', 'my_tool');
        $message = new Message('tool', '', [], $result);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'ok']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2],
        ])));

        $client = $this->createGeminiClient();
        $client->setHttpClient($fakeHttp);

        $client->chat([
            new Message('user', 'Go'),
            new Message('assistant', 'Calling tool', [
                (new \WebFiori\Ai\Tool\ToolCall('call_1', 'my_tool', []))
            ]),
            $message,
        ]);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $functionContent = array_values(array_filter($body['contents'], fn($c) => ($c['role'] ?? '') === 'function'));
        $funcResp = $functionContent[0]['parts'][0]['functionResponse']['response'];

        // Text-only: should be object with 'result' key
        $this->assertArrayHasKey('result', (array) $funcResp);
    }

    public function testFormatContentsMultimodalToolResultIncludesInlineData(): void {
        $imageData = base64_encode('fake-png');
        $parts = [ContentPart::imageBase64($imageData, 'image/png')];
        $result = new ToolResult('call_1', 'Image extracted', 'extract', $parts);
        $message = new Message('tool', '', [], $result);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Great image.']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3],
        ])));

        $client = $this->createGeminiClient();
        $client->setHttpClient($fakeHttp);

        $client->chat([
            new Message('user', 'Extract'),
            new Message('assistant', '', [(new \WebFiori\Ai\Tool\ToolCall('call_1', 'extract', []))]),
            $message,
        ]);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $functionContent = array_values(array_filter($body['contents'], fn($c) => ($c['role'] ?? '') === 'function'));
        $funcResp = $functionContent[0]['parts'][0]['functionResponse']['response'];

        $this->assertArrayHasKey('content', $funcResp);
        $this->assertEquals('Image extracted', $funcResp['content'][0]['text']);
        $this->assertArrayHasKey('inlineData', $funcResp['content'][1]);
        $this->assertEquals('image/png', $funcResp['content'][1]['inlineData']['mimeType']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createGeminiClient(): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-key',
        ));
    }
}
