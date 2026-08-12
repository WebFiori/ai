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

        $this->assertSame(ContentPart::TYPE_FILE_GCS, $part->getType());
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

    public function testGcsUriWithPdf(): void {
        $uri = 'gs://my-bucket/documents/report.pdf';
        $mimeType = 'application/pdf';
        $part = ContentPart::gcsUri($uri, $mimeType);

        $this->assertSame(ContentPart::TYPE_FILE_GCS, $part->getType());
        $this->assertFalse($part->isImage());
        $this->assertTrue($part->isDocument());
        $this->assertSame($mimeType, $part->getMimeType());
    }

    public function testFileFromImagePath(): void {
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

    public function testFileFromPdfPath(): void {
        $tempFile = sys_get_temp_dir().'/test_doc_'.uniqid().'.pdf';
        $pdfData = '%PDF-1.4 fake pdf content';
        file_put_contents($tempFile, $pdfData);

        try {
            $part = ContentPart::file($tempFile);

            $this->assertSame(ContentPart::TYPE_DOCUMENT, $part->getType());
            $this->assertTrue($part->isDocument());
            $this->assertFalse($part->isImage());
            $this->assertSame('application/pdf', $part->getMimeType());
        } finally {
            unlink($tempFile);
        }
    }

    public function testFileFromTextPath(): void {
        $tempFile = sys_get_temp_dir().'/test_code_'.uniqid().'.py';
        $codeContent = 'print("Hello, World!")';
        file_put_contents($tempFile, $codeContent);

        try {
            $part = ContentPart::file($tempFile);

            $this->assertSame(ContentPart::TYPE_DOCUMENT, $part->getType());
            $this->assertTrue($part->isDocument());
            $this->assertSame('text/plain', $part->getMimeType());
        } finally {
            unlink($tempFile);
        }
    }

    public function testFileFromJsonPath(): void {
        $tempFile = sys_get_temp_dir().'/test_config_'.uniqid().'.json';
        $jsonContent = '{"key": "value"}';
        file_put_contents($tempFile, $jsonContent);

        try {
            $part = ContentPart::file($tempFile);

            $this->assertSame(ContentPart::TYPE_DOCUMENT, $part->getType());
            $this->assertSame('application/json', $part->getMimeType());
        } finally {
            unlink($tempFile);
        }
    }

    public function testFileNotFound(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found');
        ContentPart::file('/nonexistent/path/to/file.txt');
    }

    public function testFileUnknownExtensionTreatedAsText(): void {
        $tempFile = sys_get_temp_dir().'/test_unknown_'.uniqid().'.xyz';
        file_put_contents($tempFile, 'some content');

        try {
            $part = ContentPart::file($tempFile);

            $this->assertSame(ContentPart::TYPE_DOCUMENT, $part->getType());
            $this->assertSame('text/plain', $part->getMimeType());
        } finally {
            unlink($tempFile);
        }
    }

    public function testDocumentWithPdf(): void {
        $data = base64_encode('%PDF-1.4 fake pdf');
        $part = ContentPart::document($data, 'application/pdf');

        $this->assertSame(ContentPart::TYPE_DOCUMENT, $part->getType());
        $this->assertTrue($part->isDocument());
        $this->assertFalse($part->isImage());
        $this->assertSame('application/pdf', $part->getMimeType());
    }

    public function testDocumentWithImage(): void {
        // document() with image MIME type should still work
        $data = base64_encode('fake image data');
        $part = ContentPart::document($data, 'image/png');

        // Should be routed to IMAGE_BASE64 type for consistent handling
        $this->assertSame(ContentPart::TYPE_IMAGE_BASE64, $part->getType());
        $this->assertTrue($part->isImage());
    }

    public function testDocumentWithAudio(): void {
        $data = base64_encode('fake audio data');
        $part = ContentPart::document($data, 'audio/mpeg');

        $this->assertSame(ContentPart::TYPE_DOCUMENT, $part->getType());
        $this->assertTrue($part->isAudio());
        $this->assertFalse($part->isImage());
        $this->assertTrue($part->isMedia());
    }

    public function testDocumentWithVideo(): void {
        $data = base64_encode('fake video data');
        $part = ContentPart::document($data, 'video/mp4');

        $this->assertSame(ContentPart::TYPE_DOCUMENT, $part->getType());
        $this->assertTrue($part->isVideo());
        $this->assertFalse($part->isImage());
        $this->assertTrue($part->isMedia());
    }

    public function testDocumentUnsupportedMimeType(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported MIME type');
        ContentPart::document('data', 'application/octet-stream');
    }

    public function testIsMediaReturnsTrueForImageAudioVideo(): void {
        $image = ContentPart::imageBase64('data', 'image/png');
        $audio = ContentPart::document('data', 'audio/mp3');
        $video = ContentPart::document('data', 'video/mp4');
        $pdf = ContentPart::document('data', 'application/pdf');
        $text = ContentPart::text('hello');

        $this->assertTrue($image->isMedia());
        $this->assertTrue($audio->isMedia());
        $this->assertTrue($video->isMedia());
        $this->assertFalse($pdf->isMedia());
        $this->assertFalse($text->isMedia());
    }

    public function testGetSupportedMimeTypes(): void {
        $supported = ContentPart::getSupportedMimeTypes();

        $this->assertContains('image/jpeg', $supported);
        $this->assertContains('application/pdf', $supported);
        $this->assertContains('audio/mpeg', $supported);
        $this->assertContains('video/mp4', $supported);
        $this->assertContains('text/plain', $supported);
    }

    public function testGetMimeType(): void {
        $text = ContentPart::text('hello');
        $image = ContentPart::imageBase64('data', 'image/png');
        $url = ContentPart::imageUrl('https://example.com/photo.jpg');

        $this->assertNull($text->getMimeType());
        $this->assertSame('image/png', $image->getMimeType());
        $this->assertNull($url->getMimeType());
    }

    public function testFileAllSupportedImageExtensions(): void {
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
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
                $this->assertTrue($part->isImage());
            } finally {
                unlink($tempFile);
            }
        }
    }

    public function testFileOfficeDocuments(): void {
        $officeExtensions = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];

        foreach ($officeExtensions as $ext => $expectedMime) {
            $tempFile = sys_get_temp_dir().'/test_office_'.uniqid().'.'.$ext;
            file_put_contents($tempFile, 'PK fake office content');

            try {
                $part = ContentPart::file($tempFile);
                $this->assertSame(ContentPart::TYPE_DOCUMENT, $part->getType());
                $this->assertSame($expectedMime, $part->getMimeType());
            } finally {
                unlink($tempFile);
            }
        }
    }
}
