<?php

namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Message;

class MessageTest extends TestCase {
    public function testCreateMessage() {
        $message = new Message('user', 'Hello, world!');
        $this->assertEquals('user', $message->getRole());
        $this->assertEquals('Hello, world!', $message->getContent());
    }

    public function testSystemMessage() {
        $message = new Message('system', 'You are a helpful assistant.');
        $this->assertEquals('system', $message->getRole());
        $this->assertEquals('You are a helpful assistant.', $message->getContent());
    }

    public function testAssistantMessage() {
        $message = new Message('assistant', 'I can help with that.');
        $this->assertEquals('assistant', $message->getRole());
        $this->assertEquals('I can help with that.', $message->getContent());
    }
}
