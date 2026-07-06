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
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Usage;

/**
 * Unit tests for the ChatResponse class.
 *
 * @author Ibrahim
 */
class ChatResponseTest extends TestCase {
    /**
     * @test
     */
    public function testBasicResponse() {
        $message = new Message('assistant', 'PHP is a server-side language.');
        $usage = new Usage(10, 8);
        $response = new ChatResponse($message, 'gpt-4o', $usage, 'stop');

        $this->assertEquals('PHP is a server-side language.', $response->getMessage()->getContent());
        $this->assertEquals('assistant', $response->getMessage()->getRole());
        $this->assertEquals('gpt-4o', $response->getModel());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertFalse($response->hasToolCalls());
    }

    /**
     * @test
     */
    public function testResponseWithNullOptionals() {
        $message = new Message('assistant', 'Hello');
        $response = new ChatResponse($message, 'gemini-1.5-pro');

        $this->assertNull($response->getUsage());
        $this->assertNull($response->getFinishReason());
    }

    /**
     * @test
     */
    public function testResponseWithToolCalls() {
        $toolCall = new ToolCall('call-1', 'search', ['query' => 'PHP']);
        $message = new Message('assistant', '', [$toolCall]);
        $response = new ChatResponse($message, 'gpt-4o', null, 'tool_calls');

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('tool_calls', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testUsage() {
        $usage = new Usage(150, 89);

        $this->assertEquals(150, $usage->getPromptTokens());
        $this->assertEquals(89, $usage->getCompletionTokens());
        $this->assertEquals(239, $usage->getTotalTokens());
    }
}
