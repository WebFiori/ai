<?php

/**
 * Live Test 07: FileContentExtractor — image extraction from Office files
 *
 * Usage:
 *   php live/07-office-images.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\FileProcessing\FileContentExtractor;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolResponse;

section('FileContentExtractor — Office Image Extraction');

$tmpDir = sys_get_temp_dir().'/live_office_test_'.uniqid();
mkdir($tmpDir, 0777, true);

// Helper: create a fake .docx with an embedded PNG
function createTestDocx(string $dir, string $name, string $imageData): string {
    $path = $dir.'/'.$name;
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Sample document with embedded image.</w:t></w:r></w:p></w:body></w:document>');
    $zip->addFromString('word/media/chart.png', $imageData);
    $zip->close();

    return $path;
}

// Tiny valid 1×1 PNG
$TINY_PNG = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
);

// ─── 1. Extract image from .docx ──────────────────────────────────────────────
run('Extract image from .docx returns ToolResponse', function () use ($tmpDir, $TINY_PNG)
{
    $docxPath = createTestDocx($tmpDir, 'test.docx', $TINY_PNG);

    $extractor = new FileContentExtractor();
    $extractor->setAllowedPaths([$tmpDir]);

    $result = $extractor->execute(['file_path' => $docxPath]);

    assert($result instanceof ToolResponse, 'Expected ToolResponse, got '.gettype($result));
    assert($result->isMultimodal(), 'Should be multimodal');
    assert(count($result->getParts()) === 1, 'Expected 1 image part');

    $json = json_decode($result->getText(), true);
    assert($json['file_name'] === 'test.docx', 'Wrong file name in text');

    echo "    → ToolResponse with ".count($result->getParts())." image(s)\n";
    echo "    → Image MIME: ".$result->getParts()[0]->getMimeType()."\n";
    echo "    → Text: ".substr($result->getText(), 0, 60)."...\n";
});

// ─── 2. .docx without images returns string ───────────────────────────────────
run('No images → plain string returned', function () use ($tmpDir)
{
    $path = $tmpDir.'/no_images.txt';
    file_put_contents($path, 'Hello world, no images here.');

    $extractor = new FileContentExtractor();
    $extractor->setAllowedPaths([$tmpDir]);

    $result = $extractor->execute(['file_path' => $path]);

    assert(is_string($result), 'Expected string, got '.gettype($result));
    echo "    → Returned plain string as expected\n";
});

// ─── 3. max_images=0 disables extraction ──────────────────────────────────────
run('max_images=0 disables image extraction', function () use ($tmpDir, $TINY_PNG)
{
    $docxPath = createTestDocx($tmpDir, 'test2.docx', $TINY_PNG);

    $extractor = new FileContentExtractor();
    $extractor->setAllowedPaths([$tmpDir]);

    $result = $extractor->execute(['file_path' => $docxPath, 'max_images' => 0]);

    assert(is_string($result), 'Expected string when max_images=0');
    echo "    → Images disabled → plain string returned\n";
});

// ─── 4. End-to-end: FileContentExtractor as AI tool ──────────────────────────
run('FileContentExtractor used as AI tool with real model', function () use ($tmpDir, $TINY_PNG)
{
    $docxPath = createTestDocx($tmpDir, 'report.docx', $TINY_PNG);

    $tool = new FileContentExtractor();
    $tool->setAllowedPaths([$tmpDir]);

    $response = gemini2Client()->chat(
        [new Message('user', "Read the file at path: {$docxPath}")],
        ['tools' => [$tool], 'auto_execute_tools' => true]
    );

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → AI response: ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// Cleanup
array_map('unlink', glob($tmpDir.'/*'));
rmdir($tmpDir);

echo "\n";
