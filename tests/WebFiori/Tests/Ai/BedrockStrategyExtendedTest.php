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

/**
 * Extended offline scenarios for the Bedrock invocation strategies.
 *
 * These complement BedrockClientTest by exercising request-building branches
 * (system prompts, inference config, tools) and response-parsing branches
 * (tool use, stop-reason mapping) that the basic happy-path tests do not.
 *
 * All requests go through a FakeHttpClient — no network or real credentials.
 */
class BedrockStrategyExtendedTest extends TestCase {
    private function client(ApiMethod|string $apiMethod = ApiMethod::CONVERSE): BedrockClient {
        return new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            accessKey: 'AKIAIOSFODNN7EXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            apiMethod: $apiMethod,
        ));
    }

    private function weatherTool(): Tool {
        return new Tool(
            'get_weather',
            'Get the weather for a city',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            fn (array $args): string => 'sunny'
        );
    }

    // =========================================================================
    // Converse: request building branches
    // =========================================================================

    public function testConverse_SystemMessageAndInferenceConfig(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
            'stopReason' => 'end_turn',
        ])));

        $client = $this->client();
        $client->setHttpClient($http);

        $client->chat([
            new Message('system', 'You are terse.'),
            new Message('user', 'Hi'),
        ], [
            ChatOption::TEMPERATURE => 0.3,
            ChatOption::MAX_TOKENS => 256,
            ChatOption::TOP_P => 0.9,
        ]);

        $body = json_decode($http->getLastRequest()->getBody(), true);

        // System prompt is lifted into the top-level "system" field.
        $this->assertSame('You are terse.', $body['system'][0]['text']);
        // Inference config is mapped.
        $this->assertEqualsWithDelta(0.3, $body['inferenceConfig']['temperature'], 0.001);
        $this->assertSame(256, $body['inferenceConfig']['maxTokens']);
        $this->assertEqualsWithDelta(0.9, $body['inferenceConfig']['topP'], 0.001);
        // Only the user message remains in messages.
        $this->assertCount(1, $body['messages']);
        $this->assertSame('user', $body['messages'][0]['role']);
    }

    public function testConverse_ToolsIncludedInRequest(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
            'stopReason' => 'end_turn',
        ])));

        $client = $this->client();
        $client->setHttpClient($http);

        $client->chat([new Message('user', 'weather in Cairo?')], [
            ChatOption::TOOLS => [$this->weatherTool()],
        ]);

        $body = json_decode($http->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('toolConfig', $body);
        $tools = $body['toolConfig']['tools'];
        $this->assertSame('get_weather', $tools[0]['toolSpec']['name']);
    }

    // =========================================================================
    // Converse: response parsing branches
    // =========================================================================

    public function testConverse_ParsesToolUseResponse(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'output' => [
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        ['text' => 'Let me check.'],
                        ['toolUse' => [
                            'toolUseId' => 'tu_1',
                            'name' => 'get_weather',
                            'input' => ['city' => 'Cairo'],
                        ]],
                    ],
                ],
            ],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 12, 'outputTokens' => 5],
        ])));

        $client = $this->client();
        $client->setHttpClient($http);

        $response = $client->chat([new Message('user', 'weather?')]);

        $this->assertTrue($response->getMessage()->hasToolCalls());
        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertSame('get_weather', $toolCalls[0]->getName());
        $this->assertSame(['city' => 'Cairo'], $toolCalls[0]->getArguments());
        $this->assertSame('tool_calls', $response->getFinishReason());
        $this->assertSame(12, $response->getUsage()->getPromptTokens());
    }

    public function testConverse_MaxTokensStopReasonMapping(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'truncated']]]],
            'stopReason' => 'max_tokens',
        ])));

        $client = $this->client();
        $client->setHttpClient($http);

        $response = $client->chat([new Message('user', 'write a long essay')]);
        $this->assertSame('length', $response->getFinishReason());
    }

    // =========================================================================
    // Invoke strategy
    // =========================================================================

    public function testInvoke_ParsesAnthropicResponse(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hi from invoke']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 4, 'output_tokens' => 3],
        ])));

        $client = $this->client(ApiMethod::INVOKE);
        $client->setHttpClient($http);

        $response = $client->chat([new Message('user', 'hi')]);

        $this->assertSame('Hi from invoke', $response->getMessage()->getContent());
        $this->assertStringContainsString('/invoke', $http->getLastRequest()->getUrl());
    }

    // =========================================================================
    // Multi-modal content parts (formatContentParts branches)
    // =========================================================================

    private function multiModalUserMessage(): Message {
        // 1x1 transparent PNG.
        $png = base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        return new Message('user', [
            ContentPart::text('Describe these.'),
            ContentPart::imageBase64($png, 'image/png'),
            ContentPart::document(base64_encode('%PDF-1.4 fake'), 'application/pdf'),
            ContentPart::document(base64_encode('plain text doc'), 'text/plain'),
        ]);
    }

    public function testConverse_FormatsMultiModalContentParts(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
            'stopReason' => 'end_turn',
        ])));

        $client = $this->client();
        $client->setHttpClient($http);

        $client->chat([$this->multiModalUserMessage()]);

        $body = json_decode($http->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];

        $types = [];

        foreach ($content as $block) {
            $types[] = array_key_first($block);
        }

        $this->assertContains('text', $types);
        $this->assertContains('image', $types);
        $this->assertContains('document', $types);
    }

    public function testInvoke_FormatsMultiModalContentParts(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ])));

        $client = $this->client(ApiMethod::INVOKE);
        $client->setHttpClient($http);

        $client->chat([$this->multiModalUserMessage()]);

        // Just assert the request was built and sent without error.
        $this->assertNotNull($http->getLastRequest());
        $body = json_decode($http->getLastRequest()->getBody(), true);
        $this->assertNotEmpty($body['messages']);
    }
}
