<?php

namespace WebFiori\Tests\Ai\Tool\FileProcessing;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Tool\FileProcessing\ConversionOptions;
use WebFiori\Ai\Tool\FileProcessing\ConversionResult;
use WebFiori\Ai\Tool\FileProcessing\Converter\DocumentConverter;
use WebFiori\Ai\Tool\FileProcessing\Converter\PresentationConverter;
use WebFiori\Ai\Tool\FileProcessing\Converter\SpreadsheetConverter;
use WebFiori\Ai\Tool\FileProcessing\Converter\TextConverter;
use WebFiori\Ai\Tool\FileProcessing\ConverterInterface;
use WebFiori\Ai\Tool\FileProcessing\ConverterRegistry;
use WebFiori\Ai\Tool\FileProcessing\FileContentExtractor;
use WebFiori\Ai\Tool\FileProcessing\FileTypeDetector;

/**
 * Comprehensive tests for FileContentExtractor and supporting classes.
 */
class FileContentExtractorTest extends TestCase {
    private string $fixtures;

    protected function setUp(): void {
        $this->fixtures = __DIR__ . '/fixtures';
    }

    // =========================================================================
    // ConversionOptions
    // =========================================================================

    public function testConversionOptionsDefaults(): void {
        $opts = new ConversionOptions();

        $this->assertEquals('auto', $opts->getOutputFormat());
        $this->assertEquals(50000, $opts->getMaxOutput());
        $this->assertEquals([], $opts->getExtras());
    }

    public function testConversionOptionsCustomValues(): void {
        $opts = new ConversionOptions('csv', 10000, ['sheet_name' => 'Q1']);

        $this->assertEquals('csv', $opts->getOutputFormat());
        $this->assertEquals(10000, $opts->getMaxOutput());
        $this->assertEquals('Q1', $opts->getExtra('sheet_name'));
        $this->assertNull($opts->getExtra('nonexistent'));
        $this->assertEquals('default', $opts->getExtra('nonexistent', 'default'));
    }

    // =========================================================================
    // ConversionResult
    // =========================================================================

    public function testConversionResultGetters(): void {
        $result = new ConversionResult('content', 'csv', 'text/csv', false, 7, ['rows' => 3]);

        $this->assertEquals('content', $result->getContent());
        $this->assertEquals('csv', $result->getFormat());
        $this->assertEquals('text/csv', $result->getMimeType());
        $this->assertFalse($result->isTruncated());
        $this->assertEquals(7, $result->getOriginalSize());
        $this->assertEquals(['rows' => 3], $result->getMetadata());
    }

    public function testConversionResultTruncated(): void {
        $result = new ConversionResult('short', 'plain_text', 'text/plain', true, 100000);

        $this->assertTrue($result->isTruncated());
        $this->assertEquals(100000, $result->getOriginalSize());
    }

    // =========================================================================
    // FileTypeDetector
    // =========================================================================

    public function testDetectsUrl(): void {
        $detector = new FileTypeDetector();

        $this->assertTrue($detector->isUrl('https://example.com/file.pdf'));
        $this->assertTrue($detector->isUrl('http://example.com/file.xlsx'));
        $this->assertFalse($detector->isUrl('/var/files/doc.xlsx'));
        $this->assertFalse($detector->isUrl('relative/path/file.txt'));
    }

    public function testDetectsTextFiles(): void {
        $detector = new FileTypeDetector();

        $result = $detector->detect($this->fixtures . '/test.csv');
        $this->assertEquals('csv', $result['extension']);
        $this->assertTrue($result['isText']);

        $result = $detector->detect($this->fixtures . '/test.json');
        $this->assertEquals('json', $result['extension']);
        $this->assertTrue($result['isText']);

        $result = $detector->detect($this->fixtures . '/test.txt');
        $this->assertTrue($result['isText']);
    }

    public function testDetectsXlsxByExtension(): void {
        $detector = new FileTypeDetector();

        $result = $detector->detect($this->fixtures . '/test.xlsx');
        $this->assertEquals('xlsx', $result['extension']);
        $this->assertFalse($result['isText']);
        $this->assertStringContainsString('spreadsheetml', $result['mime']);
    }

    public function testDetectsDocxByExtension(): void {
        $detector = new FileTypeDetector();

        $result = $detector->detect($this->fixtures . '/test.docx');
        $this->assertEquals('docx', $result['extension']);
        $this->assertFalse($result['isText']);
        $this->assertStringContainsString('wordprocessingml', $result['mime']);
    }

    public function testDetectsPptxByExtension(): void {
        $detector = new FileTypeDetector();

        $result = $detector->detect($this->fixtures . '/test.pptx');
        $this->assertEquals('pptx', $result['extension']);
        $this->assertFalse($result['isText']);
        $this->assertStringContainsString('presentationml', $result['mime']);
    }

    public function testDetectsUrlAsUrl(): void {
        $detector = new FileTypeDetector();

        $result = $detector->detect('https://example.com/data.xlsx');
        $this->assertTrue($result['isUrl']);
        $this->assertEquals('xlsx', $result['extension']);
    }

    // =========================================================================
    // ConverterRegistry
    // =========================================================================

    public function testRegistryResolvesByExtension(): void {
        $registry = new ConverterRegistry();
        $converter = new TextConverter();
        $registry->register($converter, priority: 0);

        $resolved = $registry->resolve('text/plain', 'txt');
        $this->assertSame($converter, $resolved);
    }

    public function testRegistryHigherPriorityWins(): void {
        $registry = new ConverterRegistry();

        $lowConverter  = new TextConverter();
        $highConverter = new TextConverter(); // different instance

        $registry->register($lowConverter, priority: 0);
        $registry->register($highConverter, priority: 10);

        $resolved = $registry->resolve('text/plain', 'txt');
        $this->assertSame($highConverter, $resolved);
    }

    public function testRegistryReturnsNullWhenNoMatch(): void {
        $registry = new ConverterRegistry();
        $registry->register(new TextConverter(), priority: 0);

        $resolved = $registry->resolve('application/x-custom', 'xyz');
        $this->assertNull($resolved);
    }

    public function testRegistryExtensionWinsOverMimeAtSamePriority(): void {
        $registry = new ConverterRegistry();

        $byMime = new class extends TextConverter {
            public function getSupportedExtensions(): array { return []; }
            public function getSupportedMimeTypes(): array { return ['text/csv']; }
        };

        $byExt = new class extends TextConverter {
            public function getSupportedExtensions(): array { return ['csv']; }
            public function getSupportedMimeTypes(): array { return []; }
        };

        $registry->register($byMime, priority: 5);
        $registry->register($byExt, priority: 5);

        $resolved = $registry->resolve('text/csv', 'csv');
        $this->assertSame($byExt, $resolved);
    }

    public function testRegistryCount(): void {
        $registry = new ConverterRegistry();
        $this->assertEquals(0, $registry->count());

        $registry->register(new TextConverter());
        $this->assertEquals(1, $registry->count());
    }

    // =========================================================================
    // TextConverter
    // =========================================================================

    public function testTextConverterReturnsContent(): void {
        $converter = new TextConverter();
        $options   = new ConversionOptions();

        $result = $converter->convert('Hello, world!', $options);

        $this->assertEquals('Hello, world!', $result->getContent());
        $this->assertEquals('plain_text', $result->getFormat());
        $this->assertFalse($result->isTruncated());
    }

    public function testTextConverterTruncates(): void {
        $converter = new TextConverter();
        $options   = new ConversionOptions(maxOutput: 10);
        $content   = 'This is a longer text that exceeds the limit.';

        $result = $converter->convert($content, $options);

        $this->assertTrue($result->isTruncated());
        $this->assertStringContainsString('[...content truncated', $result->getContent());
        $this->assertEquals(mb_strlen($content), $result->getOriginalSize());
    }

    public function testTextConverterUsesRequestedFormat(): void {
        $converter = new TextConverter();
        $options   = new ConversionOptions(outputFormat: 'csv');

        $result = $converter->convert('a,b,c', $options);

        $this->assertEquals('csv', $result->getFormat());
    }

    public function testTextConverterSupportedExtensions(): void {
        $converter = new TextConverter();

        $this->assertContains('txt', $converter->getSupportedExtensions());
        $this->assertContains('csv', $converter->getSupportedExtensions());
        $this->assertContains('json', $converter->getSupportedExtensions());
        $this->assertContains('php', $converter->getSupportedExtensions());
    }

    public function testTextConverterDefaultFormat(): void {
        $this->assertEquals('plain_text', (new TextConverter())->getDefaultOutputFormat());
    }

    // =========================================================================
    // SpreadsheetConverter (requires zip extension)
    // =========================================================================

    /**
     * @requires extension zip
     */
    public function testSpreadsheetConverterCsv(): void {
        $converter = new SpreadsheetConverter();
        $options   = new ConversionOptions(outputFormat: 'csv');
        $content   = file_get_contents($this->fixtures . '/test.xlsx');

        $result = $converter->convert($content, $options);

        $this->assertEquals('csv', $result->getFormat());
        $this->assertStringContainsString('Name', $result->getContent());
        $this->assertStringContainsString('Value', $result->getContent());
        $this->assertStringContainsString('100', $result->getContent());
    }

    /**
     * @requires extension zip
     */
    public function testSpreadsheetConverterMarkdownTable(): void {
        $converter = new SpreadsheetConverter();
        $options   = new ConversionOptions(outputFormat: 'markdown_table');
        $content   = file_get_contents($this->fixtures . '/test.xlsx');

        $result = $converter->convert($content, $options);

        $this->assertEquals('markdown_table', $result->getFormat());
        $this->assertStringContainsString('| Name |', $result->getContent());
        $this->assertStringContainsString('| --- |', $result->getContent());
        $this->assertStringContainsString('| 100 |', $result->getContent());
    }

    /**
     * @requires extension zip
     */
    public function testSpreadsheetConverterJson(): void {
        $converter = new SpreadsheetConverter();
        $options   = new ConversionOptions(outputFormat: 'json');
        $content   = file_get_contents($this->fixtures . '/test.xlsx');

        $result    = $converter->convert($content, $options);
        $decoded   = json_decode($result->getContent(), true);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
        // First row is headers
        $this->assertContains('Name', $decoded[0]);
    }

    /**
     * @requires extension zip
     */
    public function testSpreadsheetConverterPlainText(): void {
        $converter = new SpreadsheetConverter();
        $options   = new ConversionOptions(outputFormat: 'plain_text');
        $content   = file_get_contents($this->fixtures . '/test.xlsx');

        $result = $converter->convert($content, $options);

        $this->assertEquals('plain_text', $result->getFormat());
        $this->assertStringContainsString('Name', $result->getContent());
    }

    /**
     * @requires extension zip
     */
    public function testSpreadsheetConverterMaxRows(): void {
        $converter = new SpreadsheetConverter();
        $options   = new ConversionOptions(extras: ['max_rows' => 1]);
        $content   = file_get_contents($this->fixtures . '/test.xlsx');

        $result = $converter->convert($content, $options);
        $lines  = array_filter(explode("\n", trim($result->getContent())));

        $this->assertCount(1, $lines);
    }

    /**
     * @requires extension zip
     */
    public function testSpreadsheetConverterDefaultFormat(): void {
        $this->assertEquals('csv', (new SpreadsheetConverter())->getDefaultOutputFormat());
    }

    /**
     * @requires extension zip
     */
    public function testSpreadsheetConverterMetadata(): void {
        $converter = new SpreadsheetConverter();
        $options   = new ConversionOptions();
        $content   = file_get_contents($this->fixtures . '/test.xlsx');

        $result = $converter->convert($content, $options);

        $this->assertArrayHasKey('row_count', $result->getMetadata());
        $this->assertGreaterThan(0, $result->getMetadata()['row_count']);
    }

    // =========================================================================
    // DocumentConverter (requires zip extension)
    // =========================================================================

    /**
     * @requires extension zip
     */
    public function testDocumentConverterExtractsText(): void {
        $converter = new DocumentConverter();
        $options   = new ConversionOptions();
        $content   = file_get_contents($this->fixtures . '/test.docx');

        $result = $converter->convert($content, $options);

        $this->assertEquals('plain_text', $result->getFormat());
        $this->assertStringContainsString('Hello from a DOCX document', $result->getContent());
        $this->assertStringContainsString('second paragraph', $result->getContent());
    }

    /**
     * @requires extension zip
     */
    public function testDocumentConverterTruncates(): void {
        $converter = new DocumentConverter();
        $options   = new ConversionOptions(maxOutput: 5);
        $content   = file_get_contents($this->fixtures . '/test.docx');

        $result = $converter->convert($content, $options);

        $this->assertTrue($result->isTruncated());
    }

    /**
     * @requires extension zip
     */
    public function testDocumentConverterDefaultFormat(): void {
        $this->assertEquals('plain_text', (new DocumentConverter())->getDefaultOutputFormat());
    }

    // =========================================================================
    // PresentationConverter (requires zip extension)
    // =========================================================================

    /**
     * @requires extension zip
     */
    public function testPresentationConverterExtractsSlides(): void {
        $converter = new PresentationConverter();
        $options   = new ConversionOptions();
        $content   = file_get_contents($this->fixtures . '/test.pptx');

        $result = $converter->convert($content, $options);

        $this->assertStringContainsString('Slide 1', $result->getContent());
        $this->assertStringContainsString('Slide one title', $result->getContent());
        $this->assertStringContainsString('Slide 2', $result->getContent());
        $this->assertStringContainsString('Slide two content', $result->getContent());
    }

    /**
     * @requires extension zip
     */
    public function testPresentationConverterPageRange(): void {
        $converter = new PresentationConverter();
        $options   = new ConversionOptions(extras: ['page_range' => '1']);
        $content   = file_get_contents($this->fixtures . '/test.pptx');

        $result = $converter->convert($content, $options);

        $this->assertStringContainsString('Slide 1', $result->getContent());
        $this->assertStringNotContainsString('Slide 2', $result->getContent());
    }

    /**
     * @requires extension zip
     */
    public function testPresentationConverterMetadata(): void {
        $converter = new PresentationConverter();
        $options   = new ConversionOptions();
        $content   = file_get_contents($this->fixtures . '/test.pptx');

        $result = $converter->convert($content, $options);

        $this->assertArrayHasKey('total_slides', $result->getMetadata());
        $this->assertEquals(2, $result->getMetadata()['total_slides']);
    }

    /**
     * @requires extension zip
     */
    public function testPresentationConverterDefaultFormat(): void {
        $this->assertEquals('plain_text', (new PresentationConverter())->getDefaultOutputFormat());
    }

    // =========================================================================
    // FileContentExtractor — ToolInterface
    // =========================================================================

    public function testToolName(): void {
        $tool = new FileContentExtractor();
        $this->assertEquals('extract_file_content', $tool->getName());
    }

    public function testToolDescription(): void {
        $tool = new FileContentExtractor();
        $this->assertNotEmpty($tool->getDescription());
    }

    public function testToolParameters(): void {
        $tool   = new FileContentExtractor();
        $params = $tool->getParameters();

        $this->assertEquals('object', $params['type']);
        $this->assertArrayHasKey('file_path', $params['properties']);
        $this->assertArrayHasKey('output_format', $params['properties']);
        $this->assertArrayHasKey('max_output', $params['properties']);
        $this->assertArrayHasKey('options', $params['properties']);
        $this->assertEquals(['file_path'], $params['required']);
    }

    // =========================================================================
    // FileContentExtractor — execute() with text files
    // =========================================================================

    public function testExtractTextFile(): void {
        $tool = new FileContentExtractor();

        $output = $tool->execute(['file_path' => $this->fixtures . '/test.txt']);
        $data   = json_decode($output, true);

        $this->assertArrayHasKey('content', $data);
        $this->assertStringContainsString('Hello, this is plain text', $data['content']);
        $this->assertEquals('test.txt', $data['file_name']);
        $this->assertFalse($data['truncated']);
    }

    public function testExtractJsonFile(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute(['file_path' => $this->fixtures . '/test.json']);
        $data   = json_decode($output, true);

        $this->assertStringContainsString('value', $data['content']);
        $this->assertEquals('test.json', $data['file_name']);
    }

    public function testExtractCsvFile(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute(['file_path' => $this->fixtures . '/test.csv']);
        $data   = json_decode($output, true);

        $this->assertStringContainsString('name', $data['content']);
        $this->assertStringContainsString('Alice', $data['content']);
    }

    // =========================================================================
    // FileContentExtractor — execute() with XLSX (requires zip)
    // =========================================================================

    /**
     * @requires extension zip
     */
    public function testExtractXlsxFile(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute(['file_path' => $this->fixtures . '/test.xlsx']);
        $data   = json_decode($output, true);

        $this->assertArrayHasKey('content', $data);
        $this->assertEquals('test.xlsx', $data['file_name']);
        $this->assertStringContainsString('Name', $data['content']);
    }

    /**
     * @requires extension zip
     */
    public function testExtractXlsxWithMarkdownFormat(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute([
            'file_path'     => $this->fixtures . '/test.xlsx',
            'output_format' => 'markdown_table',
        ]);
        $data = json_decode($output, true);

        $this->assertEquals('markdown_table', $data['format']);
        $this->assertStringContainsString('|', $data['content']);
    }

    /**
     * @requires extension zip
     */
    public function testExtractDocxFile(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute(['file_path' => $this->fixtures . '/test.docx']);
        $data   = json_decode($output, true);

        $this->assertStringContainsString('Hello from a DOCX document', $data['content']);
        $this->assertEquals('test.docx', $data['file_name']);
    }

    /**
     * @requires extension zip
     */
    public function testExtractPptxFile(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute(['file_path' => $this->fixtures . '/test.pptx']);
        $data   = json_decode($output, true);

        $this->assertStringContainsString('Slide one title', $data['content']);
        $this->assertEquals('test.pptx', $data['file_name']);
    }

    // =========================================================================
    // FileContentExtractor — max_output
    // =========================================================================

    public function testMaxOutputTruncates(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute([
            'file_path'  => $this->fixtures . '/test.txt',
            'max_output' => 5,
        ]);
        $data = json_decode($output, true);

        $this->assertTrue($data['truncated']);
        $this->assertStringContainsString('[...content truncated', $data['content']);
        $this->assertArrayHasKey('original_size', $data);
    }

    public function testGlobalMaxOutputIsRespected(): void {
        $tool = new FileContentExtractor();
        $tool->setMaxOutput(5);

        $output = $tool->execute(['file_path' => $this->fixtures . '/test.txt']);
        $data   = json_decode($output, true);

        $this->assertTrue($data['truncated']);
    }

    public function testModelMaxOutputOverridesGlobal(): void {
        $tool = new FileContentExtractor();
        $tool->setMaxOutput(5); // Very low global

        $output = $tool->execute([
            'file_path'  => $this->fixtures . '/test.txt',
            'max_output' => 100000, // Model requests more
        ]);
        $data = json_decode($output, true);

        $this->assertFalse($data['truncated']);
    }

    // =========================================================================
    // FileContentExtractor — output format priority
    // =========================================================================

    public function testDeveloperFormatOverridesModel(): void {
        $tool = new FileContentExtractor();
        $tool->setDefaultOutputFormat('plain_text');

        $output = $tool->execute([
            'file_path'     => $this->fixtures . '/test.csv',
            'output_format' => 'json', // Model requests json
        ]);
        $data = json_decode($output, true);

        // Developer's plain_text wins
        $this->assertEquals('plain_text', $data['format']);
    }

    public function testModelFormatUsedWhenNoDeveloperFormat(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute([
            'file_path'     => $this->fixtures . '/test.txt',
            'output_format' => 'csv',
        ]);
        $data = json_decode($output, true);

        $this->assertEquals('csv', $data['format']);
    }

    // =========================================================================
    // FileContentExtractor — security
    // =========================================================================

    public function testAllowedPathsBlocksOutsidePath(): void {
        $tool = new FileContentExtractor();
        $tool->setAllowedPaths(['/var/app/uploads']);

        $output = $tool->execute(['file_path' => $this->fixtures . '/test.txt']);
        $data   = json_decode($output, true);

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Access denied', $data['error']);
    }

    public function testAllowedPathsAllowsInsidePath(): void {
        $tool = new FileContentExtractor();
        $tool->setAllowedPaths([$this->fixtures]);

        $output = $tool->execute(['file_path' => $this->fixtures . '/test.txt']);
        $data   = json_decode($output, true);

        $this->assertArrayNotHasKey('error', $data);
        $this->assertArrayHasKey('content', $data);
    }

    public function testNoAllowedPathsAllowsAnyPath(): void {
        $tool = new FileContentExtractor();
        // No setAllowedPaths() called

        $output = $tool->execute(['file_path' => $this->fixtures . '/test.txt']);
        $data   = json_decode($output, true);

        $this->assertArrayNotHasKey('error', $data);
    }

    // =========================================================================
    // FileContentExtractor — error handling
    // =========================================================================

    public function testMissingFilePathReturnsError(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute([]);
        $data   = json_decode($output, true);

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('file_path is required', $data['error']);
    }

    public function testNonExistentFileReturnsError(): void {
        $tool   = new FileContentExtractor();
        $output = $tool->execute(['file_path' => '/nonexistent/file.txt']);
        $data   = json_decode($output, true);

        $this->assertArrayHasKey('error', $data);
    }

    public function testUnsupportedBinaryReturnsError(): void {
        $tool = new FileContentExtractor();

        // Create a fake binary file with unknown extension
        $binaryFile = sys_get_temp_dir() . '/test-' . uniqid() . '.xyz_unknown';
        file_put_contents($binaryFile, "\x00\x01\x02\x03binary content");

        try {
            $output = $tool->execute(['file_path' => $binaryFile]);
            $data   = json_decode($output, true);

            $this->assertArrayHasKey('error', $data);
            $this->assertArrayHasKey('hint', $data);
        } finally {
            unlink($binaryFile);
        }
    }

    // =========================================================================
    // FileContentExtractor — custom converter
    // =========================================================================

    public function testCustomConverterOverridesBuiltIn(): void {
        $customConverter = new class extends \WebFiori\Ai\Tool\FileProcessing\AbstractConverter {
            public function getDefaultOutputFormat(): string { return 'plain_text'; }
            public function getSupportedExtensions(): array { return ['txt']; }
            public function getSupportedMimeTypes(): array { return ['text/plain']; }
            public function convert(string $content, \WebFiori\Ai\Tool\FileProcessing\ConversionOptions $options): \WebFiori\Ai\Tool\FileProcessing\ConversionResult {
                return $this->makeResult('CUSTOM:' . $content, $options->getMaxOutput(), 'text/plain', 'plain_text');
            }
        };

        $tool = new FileContentExtractor();
        $tool->registerConverter($customConverter, priority: 10);

        $output = $tool->execute(['file_path' => $this->fixtures . '/test.txt']);
        $data   = json_decode($output, true);

        $this->assertStringStartsWith('CUSTOM:', $data['content']);
    }

    public function testRegisterConverterReturnsSelf(): void {
        $tool   = new FileContentExtractor();
        $result = $tool->registerConverter(new TextConverter());

        $this->assertSame($tool, $result);
    }

    public function testSetMethodsReturnSelf(): void {
        $tool = new FileContentExtractor();

        $this->assertSame($tool, $tool->setMaxOutput(10000));
        $this->assertSame($tool, $tool->setDefaultOutputFormat('csv'));
        $this->assertSame($tool, $tool->setAllowedPaths(['/tmp']));
    }

    // =========================================================================
    // AbstractConverter — truncation note content
    // =========================================================================

    public function testTruncationNoteContainsOriginalSize(): void {
        $converter = new TextConverter();
        $content   = str_repeat('a', 100);
        $options   = new ConversionOptions(maxOutput: 10);

        $result = $converter->convert($content, $options);

        $this->assertStringContainsString('100', $result->getContent());
        $this->assertStringContainsString('10', $result->getContent());
    }
}
