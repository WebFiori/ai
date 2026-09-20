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
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;
use WebFiori\Ai\Usage;

/**
 * Round-trip serialization tests for the ChatResponse object graph.
 *
 * @author Ibrahim
 */
class SerializationRoundTripTest extends TestCase {
    // =========================================================================
    // Usage
    // =========================================================================

    public function testUsage_RoundTrip(): void {
        $usage = new Usage(120, 45);
        $restored = Usage::fromArray($usage->toArray());

        $this->assertSame(120, $restored->getPromptTokens());
        $this->assertSame(45, $restored->getCompletionTokens());
        $this->assertSame(165, $restored->getTotalTokens());
    }

    public function testUsage_ToArrayKeys(): void {
        $this->assertSame(
            ['prompt_tokens' => 10, 'completion_tokens' => 5],
            (new Usage(10, 5))->toArray()
        );
    }

    // =========================================================================
    // ContentPart
    // =========================================================================

    public function testContentPart_Text_RoundTrip(): void {
        $part = ContentPart::text('Hello world');
        $restored = ContentPart::fromArray($part->toArray());

        $this->assertSame(ContentPart::TYPE_TEXT, $restored->getType());
        $this->assertSame('Hello world', $restored->getText());
        $this->assertTrue($restored->isText());
    }

    public function testContentPart_ImageUrl_RoundTrip(): void {
        $part = ContentPart::imageUrl('https://example.com/photo.jpg');
        $restored = ContentPart::fromArray($part->toArray());

        $this->assertSame(ContentPart::TYPE_IMAGE_URL, $restored->getType());
        $this->assertSame(['url' => 'https://example.com/photo.jpg'], $restored->getData());
        $this->assertTrue($restored->isImage());
    }

    public function testContentPart_ImageBase64_RoundTrip(): void {
        $part = ContentPart::imageBase64('YmFzZTY0ZGF0YQ==', 'image/png');
        $restored = ContentPart::fromArray($part->toArray());

        $this->assertSame(ContentPart::TYPE_IMAGE_BASE64, $restored->getType());
        $this->assertSame('image/png', $restored->getMimeType());
        $this->assertSame('YmFzZTY0ZGF0YQ==', $restored->getData()['data']);
        $this->assertTrue($restored->isImage());
    }

    public function testContentPart_Document_RoundTrip(): void {
        $part = ContentPart::document('cGRmZGF0YQ==', 'application/pdf');
        $restored = ContentPart::fromArray($part->toArray());

        $this->assertSame(ContentPart::TYPE_DOCUMENT, $restored->getType());
        $this->assertSame('application/pdf', $restored->getMimeType());
        $this->assertTrue($restored->isDocument());
    }

    // =========================================================================
    // ToolCall
    // =========================================================================

    public function testToolCall_RoundTrip(): void {
        $call = new ToolCall('call_123', 'get_weather', ['city' => 'Cairo', 'units' => 'metric']);
        $restored = ToolCall::fromArray($call->toArray());

        $this->assertSame('call_123', $restored->getId());
        $this->assertSame('get_weather', $restored->getName());
        $this->assertSame(['city' => 'Cairo', 'units' => 'metric'], $restored->getArguments());
        $this->assertNull($restored->getRawPart());
    }

    public function testToolCall_WithRawPart_RoundTrip(): void {
        $call = new ToolCall('call_1', 'tool', ['a' => 1]);
        $call->setRawPart(['thought_signature' => 'abc123']);
        $restored = ToolCall::fromArray($call->toArray());

        $this->assertSame(['thought_signature' => 'abc123'], $restored->getRawPart());
    }

    // =========================================================================
    // ToolResult
    // =========================================================================

    public function testToolResult_RoundTrip(): void {
        $result = new ToolResult('call_123', '{"temp": 22}', 'get_weather');
        $restored = ToolResult::fromArray($result->toArray());

        $this->assertSame('call_123', $restored->getToolCallId());
        $this->assertSame('{"temp": 22}', $restored->getContent());
        $this->assertSame('get_weather', $restored->getName());
        $this->assertSame([], $restored->getParts());
    }

    public function testToolResult_WithParts_RoundTrip(): void {
        $result = new ToolResult('call_1', 'chart extracted', 'render', [
            ContentPart::imageBase64('Y2hhcnQ=', 'image/png'),
        ]);
        $restored = ToolResult::fromArray($result->toArray());

        $this->assertTrue($restored->isMultimodal());
        $this->assertCount(1, $restored->getParts());
        $this->assertSame('image/png', $restored->getParts()[0]->getMimeType());
    }

    // =========================================================================
    // Message
    // =========================================================================

    public function testMessage_Text_RoundTrip(): void {
        $message = new Message(Role::USER, 'What is PHP?');
        $restored = Message::fromArray($message->toArray());

        $this->assertSame('user', $restored->getRole());
        $this->assertSame('What is PHP?', $restored->getContent());
        $this->assertFalse($restored->isMultiModal());
    }

    public function testMessage_MultiModal_RoundTrip(): void {
        $message = new Message(Role::USER, [
            ContentPart::text('What is in this image?'),
            ContentPart::imageUrl('https://example.com/x.png'),
        ]);
        $restored = Message::fromArray($message->toArray());

        $this->assertTrue($restored->isMultiModal());
        $this->assertCount(2, $restored->getContentParts());
        $this->assertSame(ContentPart::TYPE_TEXT, $restored->getContentParts()[0]->getType());
        $this->assertSame(ContentPart::TYPE_IMAGE_URL, $restored->getContentParts()[1]->getType());
        $this->assertTrue($restored->hasImages());
    }

    public function testMessage_WithToolCalls_RoundTrip(): void {
        $message = Message::assistant('', [
            new ToolCall('call_1', 'search', ['q' => 'php']),
            new ToolCall('call_2', 'fetch', ['url' => 'x']),
        ]);
        $restored = Message::fromArray($message->toArray());

        $this->assertTrue($restored->hasToolCalls());
        $this->assertCount(2, $restored->getToolCalls());
        $this->assertSame('call_1', $restored->getToolCalls()[0]->getId());
        $this->assertSame('fetch', $restored->getToolCalls()[1]->getName());
    }

    public function testMessage_WithToolResult_RoundTrip(): void {
        $message = Message::tool(new ToolResult('call_1', 'result data', 'search'));
        $restored = Message::fromArray($message->toArray());

        $this->assertSame('tool', $restored->getRole());
        $this->assertNotNull($restored->getToolResult());
        $this->assertSame('call_1', $restored->getToolResult()->getToolCallId());
        $this->assertSame('result data', $restored->getToolResult()->getContent());
    }

    public function testMessage_WithRawSteps_RoundTrip(): void {
        $message = new Message(Role::ASSISTANT, 'answer');
        $message->setRawSteps([['type' => 'text', 'text' => 'thinking'], ['type' => 'function_call']]);
        $restored = Message::fromArray($message->toArray());

        $this->assertSame(
            [['type' => 'text', 'text' => 'thinking'], ['type' => 'function_call']],
            $restored->getRawSteps()
        );
    }

    public function testMessage_NoRawSteps_NotEmittedButRestoresNull(): void {
        $message = new Message(Role::USER, 'hi');

        $this->assertArrayNotHasKey('raw_steps', $message->toArray());
        $this->assertNull(Message::fromArray($message->toArray())->getRawSteps());
    }

    // =========================================================================
    // ChatResponse (full nested round-trip)
    // =========================================================================

    public function testChatResponse_RoundTrip(): void {
        $response = new ChatResponse(
            new Message(Role::ASSISTANT, 'PHP is a scripting language.'),
            'gpt-4o',
            new Usage(15, 8),
            'stop',
            'req_abc'
        );
        $restored = ChatResponse::fromArray($response->toArray());

        $this->assertSame('PHP is a scripting language.', $restored->getMessage()->getContent());
        $this->assertSame('gpt-4o', $restored->getModel());
        $this->assertSame(15, $restored->getUsage()->getPromptTokens());
        $this->assertSame(8, $restored->getUsage()->getCompletionTokens());
        $this->assertSame('stop', $restored->getFinishReason());
        $this->assertSame('req_abc', $restored->getRequestId());
    }

    public function testChatResponse_NullUsage_RoundTrip(): void {
        $response = new ChatResponse(new Message(Role::ASSISTANT, 'x'), 'model-x');
        $restored = ChatResponse::fromArray($response->toArray());

        $this->assertNull($restored->getUsage());
        $this->assertNull($restored->getFinishReason());
        $this->assertNull($restored->getRequestId());
    }

    public function testChatResponse_CostExcludedFromSerialization(): void {
        $response = new ChatResponse(new Message(Role::ASSISTANT, 'x'), 'model-x', new Usage(1, 1));

        $this->assertArrayNotHasKey('cost', $response->toArray());
    }

    public function testChatResponse_DeepNested_ToolCallsAndResult_RoundTrip(): void {
        $assistant = Message::assistant('', [new ToolCall('c1', 'search', ['q' => 'x'])]);
        $assistant->getToolCalls()[0]->setRawPart(['sig' => 'z']);

        $response = new ChatResponse($assistant, 'gemini-2.5-pro', new Usage(5, 3), 'tool_calls', 'req_1');
        $restored = ChatResponse::fromArray($response->toArray());

        $this->assertTrue($restored->hasToolCalls());
        $this->assertSame('search', $restored->getMessage()->getToolCalls()[0]->getName());
        $this->assertSame(['sig' => 'z'], $restored->getMessage()->getToolCalls()[0]->getRawPart());
        $this->assertSame('tool_calls', $restored->getFinishReason());
    }

    public function testChatResponse_MultiModalMessage_RoundTrip(): void {
        $response = new ChatResponse(
            new Message(Role::USER, [
                ContentPart::text('describe'),
                ContentPart::imageBase64('aW1n', 'image/jpeg'),
            ]),
            'gpt-4o',
            new Usage(20, 0)
        );
        $restored = ChatResponse::fromArray($response->toArray());

        $this->assertTrue($restored->getMessage()->isMultiModal());
        $this->assertCount(2, $restored->getMessage()->getContentParts());
        $this->assertSame('image/jpeg', $restored->getMessage()->getContentParts()[1]->getMimeType());
    }
}
