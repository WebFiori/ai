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

    /**
     * @test
     */
    public function testMultiModalMessageWithTextAndImage() {
        $message = new Message('user', [
            ContentPart::text('What is in this image?'),
            ContentPart::imageUrl('https://example.com/photo.jpg'),
        ]);

        $this->assertEquals('user', $message->getRole());
        $this->assertTrue($message->isMultiModal());
        $this->assertTrue($message->hasImages());
        $this->assertEquals('What is in this image?', $message->getContent());

        $parts = $message->getContentParts();
        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->isText());
        $this->assertTrue($parts[1]->isImage());
    }

    /**
     * @test
     */
    public function testMultiModalMessageWithMultipleTexts() {
        $message = new Message('user', [
            ContentPart::text('First paragraph.'),
            ContentPart::text('Second paragraph.'),
        ]);

        $this->assertTrue($message->isMultiModal());
        $this->assertFalse($message->hasImages());
        $this->assertEquals("First paragraph.\nSecond paragraph.", $message->getContent());
    }

    /**
     * @test
     */
    public function testMultiModalMessageWithBase64Image() {
        $imageData = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $message = new Message('user', [
            ContentPart::text('Analyze this:'),
            ContentPart::imageBase64($imageData, 'image/png'),
        ]);

        $this->assertTrue($message->isMultiModal());
        $this->assertTrue($message->hasImages());

        $parts = $message->getContentParts();
        $this->assertEquals(ContentPart::TYPE_IMAGE_BASE64, $parts[1]->getType());
        $this->assertEquals($imageData, $parts[1]->getData()['data']);
    }

    /**
     * @test
     */
    public function testTextOnlyMessageIsNotMultiModal() {
        $message = new Message('user', 'Just plain text');

        $this->assertFalse($message->isMultiModal());
        $this->assertFalse($message->hasImages());
    }

    /**
     * @test
     */
    public function testTextOnlyMessageGetContentParts() {
        $message = new Message('user', 'Just plain text');

        $parts = $message->getContentParts();
        $this->assertCount(1, $parts);
        $this->assertTrue($parts[0]->isText());
        $this->assertEquals('Just plain text', $parts[0]->getText());
    }

    /**
     * @test
     */
    public function testMultiModalMessageWithGcsUri() {
        $message = new Message('user', [
            ContentPart::text('What do you see?'),
            ContentPart::gcsUri('gs://my-bucket/images/photo.jpg', 'image/jpeg'),
        ]);

        $this->assertTrue($message->isMultiModal());
        $this->assertTrue($message->hasImages());

        $parts = $message->getContentParts();
        $this->assertEquals(ContentPart::TYPE_FILE_GCS, $parts[1]->getType());
        $this->assertEquals('gs://my-bucket/images/photo.jpg', $parts[1]->getData()['uri']);
    }

    /**
     * @test
     */
    public function testMultiModalMessageWithOnlyImage() {
        $message = new Message('user', [
            ContentPart::imageUrl('https://example.com/photo.jpg'),
        ]);

        $this->assertTrue($message->isMultiModal());
        $this->assertTrue($message->hasImages());
        $this->assertEquals('', $message->getContent()); // No text content
    }

    /**
     * @test
     */
    public function testMultiModalMessageWithMultipleImages() {
        $message = new Message('user', [
            ContentPart::text('Compare these images:'),
            ContentPart::imageUrl('https://example.com/photo1.jpg'),
            ContentPart::imageUrl('https://example.com/photo2.jpg'),
        ]);

        $this->assertTrue($message->hasImages());
        $parts = $message->getContentParts();
        $this->assertCount(3, $parts);

        $imageCount = 0;

        foreach ($parts as $part) {
            if ($part->isImage()) {
                $imageCount++;
            }
        }
        $this->assertEquals(2, $imageCount);
    }
}
