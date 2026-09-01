<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Tool\FileProcessing;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Tool\FileProcessing\ConversionOptions;
use WebFiori\Ai\Tool\FileProcessing\Converter\DocumentConverter;
use WebFiori\Ai\Tool\FileProcessing\Converter\PresentationConverter;
use WebFiori\Ai\Tool\FileProcessing\Converter\SpreadsheetConverter;

/**
 * Direct tests for the Office converters, exercising the alternate output
 * formats and extras that FileContentExtractorTest does not reach.
 *
 * Uses the existing binary fixtures in ./fixtures. Skips gracefully if the
 * zip extension is unavailable.
 */
class OfficeConvertersTest extends TestCase {
    private string $fixtures;

    protected function setUp(): void {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required for Office converters.');
        }
        $this->fixtures = __DIR__.'/fixtures';
    }

    // =========================================================================
    // SpreadsheetConverter — alternate output formats + extras
    // =========================================================================

    public function testSpreadsheet_CsvDefault(): void {
        $converter = new SpreadsheetConverter();
        $result = $converter->convert(
            file_get_contents($this->fixtures.'/test.xlsx'),
            new ConversionOptions(outputFormat: 'csv')
        );

        $this->assertSame('csv', $result->getFormat());
        $this->assertNotSame('', $result->getContent());
        $this->assertArrayHasKey('sheet_names', $result->getMetadata());
    }

    public function testSpreadsheet_MarkdownTable(): void {
        $converter = new SpreadsheetConverter();
        $result = $converter->convert(
            file_get_contents($this->fixtures.'/test.xlsx'),
            new ConversionOptions(outputFormat: 'markdown_table')
        );

        $this->assertSame('markdown_table', $result->getFormat());
        // Markdown tables contain pipe separators.
        $this->assertStringContainsString('|', $result->getContent());
    }

    public function testSpreadsheet_Json(): void {
        $converter = new SpreadsheetConverter();
        $result = $converter->convert(
            file_get_contents($this->fixtures.'/test.xlsx'),
            new ConversionOptions(outputFormat: 'json')
        );

        $this->assertSame('json', $result->getFormat());
        $this->assertIsArray(json_decode($result->getContent(), true));
    }

    public function testSpreadsheet_PlainText(): void {
        $converter = new SpreadsheetConverter();
        $result = $converter->convert(
            file_get_contents($this->fixtures.'/test.xlsx'),
            new ConversionOptions(outputFormat: 'plain_text')
        );

        $this->assertSame('plain_text', $result->getFormat());
    }

    public function testSpreadsheet_MaxRowsExtraRecordedInMetadata(): void {
        $converter = new SpreadsheetConverter();
        $result = $converter->convert(
            file_get_contents($this->fixtures.'/test.xlsx'),
            new ConversionOptions(outputFormat: 'csv', extras: ['max_rows' => 1])
        );

        $this->assertSame(1, $result->getMetadata()['max_rows_applied']);
        $this->assertLessThanOrEqual(1, $result->getMetadata()['rows_extracted']);
    }

    public function testSpreadsheet_SupportedTypes(): void {
        $converter = new SpreadsheetConverter();
        $this->assertContains('xlsx', $converter->getSupportedExtensions());
        $this->assertSame('csv', $converter->getDefaultOutputFormat());
        $this->assertNotEmpty($converter->getSupportedMimeTypes());
    }

    // =========================================================================
    // DocumentConverter
    // =========================================================================

    public function testDocument_ExtractsTextAndMetadata(): void {
        $converter = new DocumentConverter();
        $result = $converter->convert(
            file_get_contents($this->fixtures.'/test.docx'),
            new ConversionOptions(outputFormat: 'plain_text', extras: ['include_metadata' => true])
        );

        $metadata = $result->getMetadata();
        $this->assertArrayHasKey('word_count', $metadata);
        $this->assertArrayHasKey('paragraph_count', $metadata);
        $this->assertArrayHasKey('char_count', $metadata);
    }

    public function testDocument_WithoutMetadata(): void {
        $converter = new DocumentConverter();
        $result = $converter->convert(
            file_get_contents($this->fixtures.'/test.docx'),
            new ConversionOptions(extras: ['include_metadata' => false])
        );

        // Structural metadata is always present regardless of include_metadata.
        $this->assertArrayHasKey('char_count', $result->getMetadata());
    }

    public function testDocument_SupportedTypes(): void {
        $converter = new DocumentConverter();
        $this->assertContains('docx', $converter->getSupportedExtensions());
        $this->assertSame('plain_text', $converter->getDefaultOutputFormat());
        $this->assertNotEmpty($converter->getSupportedMimeTypes());
    }

    // =========================================================================
    // PresentationConverter
    // =========================================================================

    public function testPresentation_ExtractsSlides(): void {
        $converter = new PresentationConverter();
        $result = $converter->convert(
            file_get_contents($this->fixtures.'/test.pptx'),
            new ConversionOptions(outputFormat: 'plain_text')
        );

        $this->assertNotNull($result->getContent());
        $this->assertNotEmpty($converter->getSupportedExtensions());
        $this->assertNotEmpty($converter->getSupportedMimeTypes());
    }
}
