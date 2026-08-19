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
use WebFiori\Ai\Tool\ToolResponse;

/**
 * Tests for #95: ToolResponse class.
 */
class ToolResponseTest extends TestCase {
    // =========================================================================
    // Constructor
    // =========================================================================

    public function testConstructorTextOnly(): void {
        $response = new ToolResponse('Hello world');

        $this->assertEquals('Hello world', $response->getText());
        $this->assertEmpty($response->getParts());
        $this->assertFalse($response->isMultimodal());
    }

    public function testConstructorWithParts(): void {
        $part = ContentPart::text('extra');
        $response = new ToolResponse('text', [$part]);

        $this->assertEquals('text', $response->getText());
        $this->assertCount(1, $response->getParts());
        $this->assertTrue($response->isMultimodal());
    }

    public function testConstructorWithEmptyParts(): void {
        $response = new ToolResponse('text', []);

        $this->assertFalse($response->isMultimodal());
    }

    // =========================================================================
    // Static factories
    // =========================================================================

    public function testTextFactory(): void {
        $response = ToolResponse::text('Result is 42.');

        $this->assertEquals('Result is 42.', $response->getText());
        $this->assertEmpty($response->getParts());
        $this->assertFalse($response->isMultimodal());
    }

    public function testWithImagesFactory(): void {
        $img1 = ContentPart::imageBase64(base64_encode('fake-png-data'), 'image/png');
        $img2 = ContentPart::imageBase64(base64_encode('fake-jpeg-data'), 'image/jpeg');

        $response = ToolResponse::withImages('Chart extracted.', [$img1, $img2]);

        $this->assertEquals('Chart extracted.', $response->getText());
        $this->assertCount(2, $response->getParts());
        $this->assertTrue($response->isMultimodal());
        $this->assertSame($img1, $response->getParts()[0]);
        $this->assertSame($img2, $response->getParts()[1]);
    }

    public function testWithPartsFactory(): void {
        $parts = [
            ContentPart::text('Part one'),
            ContentPart::imageBase64(base64_encode('img'), 'image/png'),
        ];

        $response = ToolResponse::withParts('main text', $parts);

        $this->assertEquals('main text', $response->getText());
        $this->assertCount(2, $response->getParts());
        $this->assertTrue($response->isMultimodal());
    }

    public function testWithImagesEmptyArray(): void {
        $response = ToolResponse::withImages('text', []);

        $this->assertFalse($response->isMultimodal());
    }

    // =========================================================================
    // __toString
    // =========================================================================

    public function testToStringReturnsText(): void {
        $response = ToolResponse::text('The answer is 42.');

        $this->assertEquals('The answer is 42.', (string) $response);
    }

    public function testToStringOnMultimodalReturnsTextOnly(): void {
        $img = ContentPart::imageBase64(base64_encode('data'), 'image/png');
        $response = ToolResponse::withImages('Summary text.', [$img]);

        // String cast should only return text — no image data
        $this->assertEquals('Summary text.', (string) $response);
    }

    public function testToStringUsableInStringContext(): void {
        $response = ToolResponse::text('hello');

        // Can be used anywhere a string is expected
        $result = 'Prefix: ' . $response;
        $this->assertEquals('Prefix: hello', $result);
    }

    public function testEmptyTextToString(): void {
        $response = ToolResponse::text('');
        $this->assertEquals('', (string) $response);
    }

    // =========================================================================
    // Backward compatibility — works like a string in existing code
    // =========================================================================

    public function testBackwardCompatWithStringComparison(): void {
        $response = ToolResponse::text('42');

        // ToolResponse should work the same as a string in most contexts
        $this->assertEquals('42', (string) $response);
        $this->assertEquals(2, strlen((string) $response));
    }

    public function testMultiplePartsReturned(): void {
        $parts = [
            ContentPart::imageBase64(base64_encode('a'), 'image/png'),
            ContentPart::imageBase64(base64_encode('b'), 'image/png'),
            ContentPart::imageBase64(base64_encode('c'), 'image/png'),
        ];
        $response = ToolResponse::withImages('text', $parts);

        $returned = $response->getParts();
        $this->assertCount(3, $returned);
        $this->assertSame($parts[0], $returned[0]);
        $this->assertSame($parts[1], $returned[1]);
        $this->assertSame($parts[2], $returned[2]);
    }
}
