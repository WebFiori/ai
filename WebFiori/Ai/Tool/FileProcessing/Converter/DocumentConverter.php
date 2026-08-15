<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool\FileProcessing\Converter;

use RuntimeException;
use WebFiori\Ai\Tool\FileProcessing\AbstractConverter;
use WebFiori\Ai\Tool\FileProcessing\ConversionOptions;
use WebFiori\Ai\Tool\FileProcessing\ConversionResult;
use ZipArchive;

/**
 * Converter for word processing documents (DOCX, ODT).
 *
 * Uses PHP's built-in ZipArchive to extract and parse the Office Open XML
 * or ODF XML content. No external dependencies required.
 *
 * Extras: include_metadata (bool, default false) — include author, title, etc.
 *
 * @author Ibrahim
 */
class DocumentConverter extends AbstractConverter {
    /**
     * Returns the default output format.
     *
     * @return string
     */
    public function getDefaultOutputFormat(): string {
        return 'plain_text';
    }

    /**
     * Returns supported file extensions.
     *
     * @return string[]
     */
    public function getSupportedExtensions(): array {
        return ['docx', 'odt'];
    }

    /**
     * Returns supported MIME types.
     *
     * @return string[]
     */
    public function getSupportedMimeTypes(): array {
        return [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
        ];
    }

    /**
     * Converts document content to plain text.
     *
     * @param string $content Raw file bytes.
     * @param ConversionOptions $options Conversion options.
     *
     * @return ConversionResult
     */
    public function convert(string $content, ConversionOptions $options): ConversionResult {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('The "zip" PHP extension is required for DocumentConverter.');
        }
        $format = $this->resolveFormat($options->getOutputFormat());
        $includeMetadata = (bool) $options->getExtra('include_metadata', false);

        $tmpFile = tempnam(sys_get_temp_dir(), 'wf_doc_');
        file_put_contents($tmpFile, $content);

        try {
            [$text, $metadata] = $this->extractText($tmpFile, $includeMetadata);
        } finally {
            unlink($tmpFile);
        }

        return $this->makeResult(
            content: $text,
            maxOutput: $options->getMaxOutput(),
            mimeType: $this->getSupportedMimeTypes()[0],
            format: $format,
            metadata: $metadata,
        );
    }

    /**
     * Extracts text and optional metadata from a document file.
     *
     * @param string $filePath
     * @param bool $includeMetadata
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function extractText(string $filePath, bool $includeMetadata): array {
        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Failed to open document as ZIP archive.');
        }

        try {
            // Try DOCX first
            $docXml = $zip->getFromName('word/document.xml');

            if ($docXml !== false) {
                return $this->parseDocx($zip, $docXml, $includeMetadata);
            }

            // Try ODT
            $contentXml = $zip->getFromName('content.xml');

            if ($contentXml !== false) {
                return $this->parseOdt($contentXml);
            }
        } finally {
            $zip->close();
        }

        return ['', []];
    }

    /**
     * Parses a DOCX document.xml and extracts text.
     *
     * @param ZipArchive $zip
     * @param string $docXml
     * @param bool $includeMetadata
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parseDocx(ZipArchive $zip, string $docXml, bool $includeMetadata): array {
        $doc = simplexml_load_string($docXml);

        if ($doc === false) {
            return ['', []];
        }

        $doc->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];

        // Extract text from all paragraphs
        foreach ($doc->xpath('//w:p') as $para) {
            $paraText = '';

            foreach ($para->xpath('.//w:t') as $textNode) {
                $paraText .= (string) $textNode;
            }

            if (trim($paraText) !== '') {
                $paragraphs[] = $paraText;
            }
        }

        $metadata = [];

        if ($includeMetadata) {
            $coreXml = $zip->getFromName('docProps/core.xml');

            if ($coreXml !== false) {
                $core = simplexml_load_string($coreXml);

                if ($core !== false) {
                    $core->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                    $core->registerXPathNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');

                    $metadata['title']   = (string) ($core->xpath('//dc:title')[0] ?? '');
                    $metadata['author']  = (string) ($core->xpath('//dc:creator')[0] ?? '');
                    $metadata['created'] = (string) ($core->xpath('//cp:created')[0] ?? '');
                }
            }
        }

        return [implode("\n\n", $paragraphs), $metadata];
    }

    /**
     * Parses an ODT content.xml and extracts text.
     *
     * @param string $contentXml
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parseOdt(string $contentXml): array {
        $doc = simplexml_load_string($contentXml);

        if ($doc === false) {
            return ['', []];
        }

        $doc->registerXPathNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');

        $paragraphs = [];

        foreach ($doc->xpath('//text:p') as $para) {
            $text = trim((string) $para);

            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        return [implode("\n\n", $paragraphs), []];
    }
}
