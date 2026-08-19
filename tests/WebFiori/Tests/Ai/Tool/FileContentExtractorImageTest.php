<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Tool;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Tool\FileProcessing\FileContentExtractor;
use WebFiori\Ai\Tool\ToolResponse;

/**
 * Tests for #98: Extract embedded images from Office files.
 */
class FileContentExtractorImageTest extends TestCase {
    private string $tmpDir;

    protected function setUp(): void {
        $this->tmpDir = sys_get_temp_dir().'/fce_image_test_'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void {
        $this->removeDir($this->tmpDir);
    }

    // =========================================================================
    // extractEmbeddedImages via execute() — using real ZIP files
    // =========================================================================

    public function testDocxWithImageReturnsToolResponse(): void {
        $docxPath = $this->createFakeDocxWithImage('test.docx', 'hello.png', str_repeat("\x89PNG", 10));

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $docxPath]);

        $this->assertInstanceOf(ToolResponse::class, $result);
        $this->assertTrue($result->isMultimodal());
        $this->assertCount(1, $result->getParts());
        $this->assertStringContainsString('image/png', $result->getParts()[0]->getMimeType() ?? '');
    }

    public function testXlsxWithImageReturnsToolResponse(): void {
        $xlsxPath = $this->createFakeOfficeFileWithImage(
            'test.xlsx',
            'xl/media/chart1.png',
            str_repeat('PNGDATA', 5)
        );

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $xlsxPath]);

        $this->assertInstanceOf(ToolResponse::class, $result);
        $this->assertTrue($result->isMultimodal());
    }

    public function testPptxWithImageReturnsToolResponse(): void {
        $pptxPath = $this->createFakeOfficeFileWithImage(
            'test.pptx',
            'ppt/media/image1.jpeg',
            str_repeat('JPEGDATA', 5)
        );

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $pptxPath]);

        $this->assertInstanceOf(ToolResponse::class, $result);
        $this->assertTrue($result->isMultimodal());
        $this->assertEquals('image/jpeg', $result->getParts()[0]->getMimeType());
    }

    public function testDocxWithoutImageReturnsString(): void {
        $docxPath = $this->createFakeDocxWithoutImages('no_images.docx');

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $docxPath]);

        $this->assertIsString($result);
    }

    public function testMaxImagesLimitsExtraction(): void {
        // Create docx with 5 images but limit to 2
        $docxPath = $this->createFakeDocxWithMultipleImages('multi.docx', 5);

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $docxPath, 'max_images' => 2]);

        $this->assertInstanceOf(ToolResponse::class, $result);
        $this->assertCount(2, $result->getParts());
    }

    public function testMaxImagesZeroDisablesExtraction(): void {
        $docxPath = $this->createFakeDocxWithImage('test.docx', 'img.png', str_repeat('X', 100));

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        // max_images=0 disables image extraction
        $result = $extractor->execute(['file_path' => $docxPath, 'max_images' => 0]);

        $this->assertIsString($result);
    }

    public function testImageLargerThan1MbIsSkipped(): void {
        // Create a >1MB image entry
        $largeData = str_repeat('X', 1_100_000);
        $docxPath = $this->createFakeDocxWithImage('large.docx', 'big.png', $largeData);

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $docxPath]);

        // Large image should be skipped — no images extracted, returns string
        $this->assertIsString($result);
    }

    public function testNonOfficeFileDoesNotExtractImages(): void {
        // Text file — no ZIP structure, no images
        $txtPath = $this->tmpDir.'/test.txt';
        file_put_contents($txtPath, 'Hello world');

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $txtPath]);

        $this->assertIsString($result);
    }

    public function testUnsupportedImageExtensionIsSkipped(): void {
        // Office file with a .tga image (not supported)
        $docxPath = $this->createFakeOfficeFileWithImage(
            'test.docx',
            'word/media/image.tga',
            'TGADATA'
        );

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        // tga not in supported list → skipped → returns string
        $result = $extractor->execute(['file_path' => $docxPath]);

        $this->assertIsString($result);
    }

    public function testImagesFromNonMediaDirAreIgnored(): void {
        // Image in wrong directory (not word/media/)
        $docxPath = $this->createFakeOfficeFileWithImage(
            'test.docx',
            'word/other/image.png',
            'PNGDATA'
        );

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $docxPath]);

        // Not in word/media/ → not extracted
        $this->assertIsString($result);
    }

    public function testToolResponseTextContainsFileJson(): void {
        $docxPath = $this->createFakeDocxWithImage('test.docx', 'chart.png', str_repeat('P', 100));

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        $result = $extractor->execute(['file_path' => $docxPath]);

        $this->assertInstanceOf(ToolResponse::class, $result);
        // Text portion should contain the JSON-encoded file content
        $json = json_decode($result->getText(), true);
        $this->assertArrayHasKey('file_name', $json);
        $this->assertEquals('test.docx', $json['file_name']);
    }

    public function testSupportedImageTypes(): void {
        // Only types supported by ContentPart::imageBase64()
        $types = [
            ['file' => 'img.jpg', 'mime' => 'image/jpeg'],
            ['file' => 'img.gif', 'mime' => 'image/gif'],
            ['file' => 'img.webp', 'mime' => 'image/webp'],
        ];

        foreach ($types as $i => $type) {
            // Always use .docx as the outer filename
            $docxPath = $this->createFakeDocxWithImage(
                "test_{$i}.docx",
                $type['file'],
                str_repeat('X', 200)
            );

            $extractor = new FileContentExtractor();
            $extractor->setAllowedPaths([$this->tmpDir]);

            $result = $extractor->execute(['file_path' => $docxPath]);

            $this->assertInstanceOf(ToolResponse::class, $result, "Failed for {$type['file']}");
            $this->assertEquals($type['mime'], $result->getParts()[0]->getMimeType(), "Wrong MIME for {$type['file']}");
        }
    }

    public function testUnsupportedByContentPartMimeTypeIsSkipped(): void {
        // bmp and tiff are in our extractor's list but not in ContentPart::imageBase64()
        // They should be silently skipped (caught by try/catch in extractEmbeddedImages)
        $docxPath = $this->createFakeDocxWithImage('test.docx', 'img.bmp', str_repeat('B', 200));

        $extractor = new FileContentExtractor();
        $extractor->setAllowedPaths([$this->tmpDir]);

        // bmp gets silently skipped → no images → returns string
        $result = $extractor->execute(['file_path' => $docxPath]);

        $this->assertIsString($result);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createFakeDocxWithImage(string $name, string $imageName, string $imageData): string {
        return $this->createFakeOfficeFileWithImage($name, 'word/media/'.$imageName, $imageData);
    }

    private function createFakeOfficeFileWithImage(string $name, string $entryPath, string $data): string {
        $path = $this->tmpDir.'/'.$name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        // Add minimal document content so converters don't fail
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString($entryPath, $data);
        $zip->close();

        return $path;
    }

    private function createFakeDocxWithoutImages(string $name): string {
        $path = $this->tmpDir.'/'.$name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('word/document.xml', '<w:document>Hello</w:document>');
        $zip->close();

        return $path;
    }

    private function createFakeDocxWithMultipleImages(string $name, int $count): string {
        $path = $this->tmpDir.'/'.$name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types></Types>');

        for ($i = 1; $i <= $count; $i++) {
            $zip->addFromString("word/media/image{$i}.png", str_repeat("PNG{$i}", 20));
        }

        $zip->close();

        return $path;
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir.'/'.$file;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
