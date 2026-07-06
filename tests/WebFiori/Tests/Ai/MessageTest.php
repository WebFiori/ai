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
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Unit tests for the Message class.
 *
 * @author Ibrahim
 */
class MessageTest extends TestCase {
    /**
     * @test
     */
    public function testAssistantMessage() {
        $message = new Message('assistant', 'I can help with that.');
        $this->assertEquals('assistant', $message->getRole());
        $this->assertEquals('I can help with that.', $message->getContent());
        $this->assertFalse($message->hasToolCalls());
        $this->assertEmpty($message->getToolCalls());
        $this->assertNull($message->getToolResult());
    }

    /**
     * @test
     */
    public function testMessageWithToolCalls() {
        $toolCall = new ToolCall('call-1', 'get_weather', ['location' => 'London']);
        $message = new Message('assistant', '', [$toolCall]);

        $this->assertEquals('assistant', $message->getRole());
        $this->assertTrue($message->hasToolCalls());
        $this->assertCount(1, $message->getToolCalls());
        $this->assertEquals('get_weather', $message->getToolCalls()[0]->getName());
        $this->assertEquals(['location' => 'London'], $message->getToolCalls()[0]->getArguments());
        $this->assertEquals('call-1', $message->getToolCalls()[0]->getId());
    }

    /**
     * @test
     */
    public function testSystemMessage() {
        $message = new Message('system', 'You are a helpful assistant.');
        $this->assertEquals('system', $message->getRole());
        $this->assertEquals('You are a helpful assistant.', $message->getContent());
    }

    /**
     * @test
     */
    public function testToolMessage() {
        $toolResult = new ToolResult('call-1', '{"temp": 22, "unit": "celsius"}');
        $message = new Message('tool', '', [], $toolResult);

        $this->assertEquals('tool', $message->getRole());
        $this->assertNotNull($message->getToolResult());
        $this->assertEquals('call-1', $message->getToolResult()->getToolCallId());
        $this->assertEquals('{"temp": 22, "unit": "celsius"}', $message->getToolResult()->getContent());
    }

    /**
     * @test
     */
    public function testUserMessage() {
        $message = new Message('user', 'Hello, world!');
        $this->assertEquals('user', $message->getRole());
        $this->assertEquals('Hello, world!', $message->getContent());
        $this->assertFalse($message->hasToolCalls());
    }
}
