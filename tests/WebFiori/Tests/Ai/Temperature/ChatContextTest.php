<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Temperature;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Message;
use WebFiori\Ai\Temperature\ChatContext;

/**
 * Tests for ChatContext value object.
 */
class ChatContextTest extends TestCase {
    // =========================================================================
    // Construction
    // =========================================================================

    public function testConstructionWithMessagesAndOptions(): void {
        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hello'),
        ];
        $options = ['json_mode' => true, 'tools' => ['some_tool']];

        $context = new ChatContext($messages, $options);

        $this->assertCount(2, $context->getMessages());
        $this->assertSame($messages, $context->getMessages());
        $this->assertSame($options, $context->getOptions());
    }

    public function testConstructionWithMessagesOnly(): void {
        $messages = [new Message('user', 'Hi')];

        $context = new ChatContext($messages);

        $this->assertCount(1, $context->getMessages());
        $this->assertSame([], $context->getOptions());
    }

    public function testConstructionWithEmptyArrays(): void {
        $context = new ChatContext([], []);

        $this->assertSame([], $context->getMessages());
        $this->assertSame([], $context->getOptions());
    }

    // =========================================================================
    // getMessages()
    // =========================================================================

    public function testGetMessagesReturnsCorrectArray(): void {
        $msg1 = new Message('user', 'First');
        $msg2 = new Message('assistant', 'Second');
        $msg3 = new Message('user', 'Third');

        $context = new ChatContext([$msg1, $msg2, $msg3]);

        $messages = $context->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame($msg1, $messages[0]);
        $this->assertSame($msg2, $messages[1]);
        $this->assertSame($msg3, $messages[2]);
    }

    public function testGetMessagesReturnsEmptyArrayWhenNoMessages(): void {
        $context = new ChatContext([]);
        $this->assertSame([], $context->getMessages());
    }

    // =========================================================================
    // getOptions()
    // =========================================================================

    public function testGetOptionsReturnsCorrectArray(): void {
        $options = [
            'json_mode' => true,
            'tools' => [['name' => 'tool1']],
            'model' => 'gpt-4o',
        ];

        $context = new ChatContext([], $options);

        $this->assertSame($options, $context->getOptions());
        $this->assertTrue($context->getOptions()['json_mode']);
        $this->assertEquals('gpt-4o', $context->getOptions()['model']);
    }

    public function testGetOptionsReturnsEmptyArrayByDefault(): void {
        $context = new ChatContext([new Message('user', 'Hi')]);
        $this->assertSame([], $context->getOptions());
    }
}
