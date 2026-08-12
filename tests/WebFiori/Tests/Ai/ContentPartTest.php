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

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ContentPart;

/**
 * Tests for the ContentPart class.
 */
class ContentPartTest extends TestCase {
    public function testTextContent(): void {
        $part = ContentPart::text('Hello, world!');

        $this->assertSame(ContentPart::TYPE_TEXT, $part->getType());
        $this->assertTrue($part->isText());
        $this->assertFalse($part->isImage());
        $this->assertSame('Hello, world!', $part->getText());
        $this->assertSame(['text' => 'Hello, world!'], $part->getData());
    }

    public function testImageUrl(): void {
        $url = 'https://example.com/photo.jpg';
        $part = ContentPart::imageUrl($url);

        $this->assertSame(ContentPart::TYPE_IMAGE_URL, $part->getType());
        $this->assertFalse($part->isText());
        $this->assertTrue($part->isImage());
        $this->assertNull($part->getText());
        $this->assertSame(['url' => $url], $part->getData());
    }

    public function testImageUrlInvalidUrl(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image URL');
        ContentPart::imageUrl('not-a-valid-url');
    }

    public function testImageUrlInvalidScheme(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must use http:// or https://');
        ContentPart::imageUrl('ftp://example.com/photo.jpg');
    }

    public function testImageBase64(): void {
        $data = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $mimeType = 'image/png';
        $part = ContentPart::imageBase64($data, $mimeType);

        $this->assertSame(ContentPart::TYPE_IMAGE_BASE64, $part->getType());
        $this->assertTrue($part->isImage());
        $this->assertSame([
            'data' => $data,
            'mime_type' => $mimeType,
        ], $part->getData());
    }

    public function testImageBase64UnsupportedMimeType(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image MIME type: image/tiff');
        ContentPart::imageBase64('somedata', 'image/tiff');
    }

    public function testImageBase64AllSupportedMimeTypes(): void {
        $supported = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        foreach ($supported as $mimeType) {
            $part = ContentPart::imageBase64('data', $mimeType);
            $this->assertSame($mimeType, $part->getData()['mime_type']);
        }
    }

    public function testGcsUri(): void {
        $uri = 'gs://my-bucket/images/photo.jpg';
        $mimeType = 'image/jpeg';
        $part = ContentPart::gcsUri($uri, $mimeType);

        $this->assertSame(ContentPart::TYPE_IMAGE_GCS, $part->getType());
        $this->assertTrue($part->isImage());
        $this->assertSame([
            'uri' => $uri,
            'mime_type' => $mimeType,
        ], $part->getData());
    }

    public function testGcsUriInvalidScheme(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GCS URI must start with gs://');
        ContentPart::gcsUri('https://storage.googleapis.com/bucket/file.jpg', 'image/jpeg');
    }

    public function testGcsUriUnsupportedMimeType(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image MIME type');
        ContentPart::gcsUri('gs://bucket/file.bmp', 'image/bmp');
    }

    public function testFileFromPath(): void {
        // Create a temporary test image
        $tempFile = sys_get_temp_dir().'/test_image_'.uniqid().'.png';
        // 1x1 transparent PNG
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        file_put_contents($tempFile, $pngData);

        try {
            $part = ContentPart::file($tempFile);

            $this->assertSame(ContentPart::TYPE_IMAGE_BASE64, $part->getType());
            $this->assertTrue($part->isImage());
            $this->assertSame('image/png', $part->getData()['mime_type']);
            $this->assertSame(base64_encode($pngData), $part->getData()['data']);
        } finally {
            unlink($tempFile);
        }
    }

    public function testFileNotFound(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file not found');
        ContentPart::file('/nonexistent/path/to/image.jpg');
    }

    public function testFileUnsupportedExtension(): void {
        $tempFile = sys_get_temp_dir().'/test_image_'.uniqid().'.bmp';
        file_put_contents($tempFile, 'fake bmp content');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Unsupported image file extension');
            ContentPart::file($tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    public function testFileAllSupportedExtensions(): void {
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        // Minimal valid image data for each type
        $imageData = [
            'jpg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00",
            'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00",
            'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
            'gif' => "GIF89a\x01\x00\x01\x00\x00\x00\x00!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;",
            'webp' => "RIFF\x00\x00\x00\x00WEBP",
        ];

        foreach ($extensions as $ext) {
            $tempFile = sys_get_temp_dir().'/test_image_'.uniqid().'.'.$ext;
            file_put_contents($tempFile, $imageData[$ext]);

            try {
                $part = ContentPart::file($tempFile);
                $this->assertSame(ContentPart::TYPE_IMAGE_BASE64, $part->getType());
            } finally {
                unlink($tempFile);
            }
        }
    }

    public function testIsImageReturnsTrueForAllImageTypes(): void {
        $urlPart = ContentPart::imageUrl('https://example.com/photo.jpg');
        $base64Part = ContentPart::imageBase64('data', 'image/png');
        $gcsPart = ContentPart::gcsUri('gs://bucket/photo.jpg', 'image/jpeg');

        $this->assertTrue($urlPart->isImage());
        $this->assertTrue($base64Part->isImage());
        $this->assertTrue($gcsPart->isImage());
    }

    public function testIsTextReturnsFalseForImageTypes(): void {
        $urlPart = ContentPart::imageUrl('https://example.com/photo.jpg');

        $this->assertFalse($urlPart->isText());
    }

    public function testGetTextReturnsNullForNonTextParts(): void {
        $imagePart = ContentPart::imageUrl('https://example.com/photo.jpg');

        $this->assertNull($imagePart->getText());
    }
}
