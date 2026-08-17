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
 * Converter for spreadsheet formats (XLSX, ODS).
 *
 * Uses PHP's built-in ZipArchive to extract and parse the Office Open XML
 * or ODF XML content. No external dependencies required.
 *
 * Supports output formats: csv (default), markdown_table, json, plain_text.
 * Extras: sheet_name (string), max_rows (int).
 *
 * @author Ibrahim
 */
class SpreadsheetConverter extends AbstractConverter {
    /**
     * Returns the default output format.
     *
     * @return string
     */
    public function getDefaultOutputFormat(): string {
        return 'csv';
    }

    /**
     * Returns supported file extensions.
     *
     * @return string[]
     */
    public function getSupportedExtensions(): array {
        return ['xlsx', 'ods'];
    }

    /**
     * Returns supported MIME types.
     *
     * @return string[]
     */
    public function getSupportedMimeTypes(): array {
        return [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
        ];
    }

    /**
     * Converts spreadsheet content to the requested format.
     *
     * @param string $content Raw file bytes.
     * @param ConversionOptions $options Conversion options.
     *
     * @return ConversionResult
     */
    public function convert(string $content, ConversionOptions $options): ConversionResult {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('The "zip" PHP extension is required for SpreadsheetConverter.');
        }
        $format    = $this->resolveFormat($options->getOutputFormat());
        $maxRows   = (int) $options->getExtra('max_rows', PHP_INT_MAX);
        $sheetName = $options->getExtra('sheet_name', null);

        // Write to temp file for ZipArchive
        $tmpFile = tempnam(sys_get_temp_dir(), 'wf_sheet_');
        file_put_contents($tmpFile, $content);

        try {
            $allSheetNames = $this->extractSheetNames($tmpFile);
            $rows          = $this->extractRows($tmpFile, $sheetName, $maxRows);
        } finally {
            unlink($tmpFile);
        }

        $extractedRows = count($rows);
        $metadata = [
            'sheet_names'     => $allSheetNames,
            'sheet_count'     => count($allSheetNames),
            'current_sheet'   => $sheetName ?? ($allSheetNames[0] ?? null),
            'rows_extracted'  => $extractedRows,
        ];

        // If max_rows was applied, include it so model knows extraction was capped
        if ($maxRows !== PHP_INT_MAX) {
            $metadata['max_rows_applied'] = $maxRows;
        }

        $text = match ($format) {
            'markdown_table' => $this->toMarkdownTable($rows),
            'json'           => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'plain_text'     => $this->toPlainText($rows),
            default          => $this->toCsv($rows),
        };

        return $this->makeResult(
            content: $text,
            maxOutput: $options->getMaxOutput(),
            mimeType: $this->getSupportedMimeTypes()[0],
            format: $format,
            metadata: $metadata,
        );
    }

    /**
     * Extracts all sheet names from an XLSX workbook.
     *
     * @param string $filePath Path to the XLSX file.
     *
     * @return string[] Sheet names in order.
     */
    private function extractSheetNames(string $filePath): array {
        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            return [];
        }

        try {
            $workbook = $zip->getFromName('xl/workbook.xml');

            if ($workbook === false) {
                return [];
            }

            $wbXml = simplexml_load_string($workbook);

            if ($wbXml === false) {
                return [];
            }

            $names = [];

            foreach ($wbXml->sheets->sheet ?? [] as $sheet) {
                $attrs = $sheet->attributes();
                $name  = (string) ($attrs['name'] ?? '');

                if ($name !== '') {
                    $names[] = $name;
                }
            }

            return $names;
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts rows from an XLSX file using ZipArchive.
     *
     * @param string $filePath Path to the XLSX file.
     * @param string|null $sheetName Specific sheet to extract.
     * @param int $maxRows Maximum rows to extract.
     *
     * @return array<int, array<int, string>> Array of rows, each row an array of cell values.
     */
    private function extractRows(string $filePath, ?string $sheetName, int $maxRows): array {
        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Failed to open spreadsheet as ZIP archive.');
        }

        try {
            // Get shared strings (used to look up string cell values)
            $sharedStrings = $this->extractSharedStrings($zip);

            // Find the target sheet
            $sheetXml = $this->findSheet($zip, $sheetName);

            if ($sheetXml === null) {
                return [];
            }

            return $this->parseSheetXml($sheetXml, $sharedStrings, $maxRows);
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts shared strings from the XLSX file.
     *
     * XLSX stores strings in a shared strings table (xl/sharedStrings.xml).
     *
     * @param ZipArchive $zip
     *
     * @return string[] Array indexed by position.
     */
    private function extractSharedStrings(ZipArchive $zip): array {
        $content = $zip->getFromName('xl/sharedStrings.xml');

        if ($content === false) {
            return [];
        }

        $xml = simplexml_load_string($content);

        if ($xml === false) {
            return [];
        }

        $strings = [];

        foreach ($xml->si as $si) {
            // Handle <t> directly and <r><t> (rich text)
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $text = '';

                foreach ($si->r as $r) {
                    $text .= (string) $r->t;
                }

                $strings[] = $text;
            }
        }

        return $strings;
    }

    /**
     * Finds the sheet XML content from the ZIP archive.
     *
     * @param ZipArchive $zip
     * @param string|null $sheetName If null, returns the first sheet.
     *
     * @return string|null The sheet XML content, or null if not found.
     */
    private function findSheet(ZipArchive $zip, ?string $sheetName): ?string {
        // Get workbook to find sheet names
        $workbook = $zip->getFromName('xl/workbook.xml');

        if ($workbook === false) {
            // Try ODS format
            $content = $zip->getFromName('content.xml');

            return $content !== false ? $content : null;
        }

        $wbXml = simplexml_load_string($workbook);

        if ($wbXml === false) {
            return null;
        }

        // Register namespaces
        $ns = $wbXml->getNamespaces(true);
        $wbXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheets = $wbXml->sheets->sheet ?? [];
        $sheetIndex = 1;

        foreach ($sheets as $sheet) {
            $attrs = $sheet->attributes();
            $name = (string) ($attrs['name'] ?? '');

            if ($sheetName === null || $name === $sheetName) {
                $content = $zip->getFromName("xl/worksheets/sheet{$sheetIndex}.xml");

                return $content !== false ? $content : null;
            }

            $sheetIndex++;
        }

        return null;
    }

    /**
     * Parses sheet XML and returns rows of cell values.
     *
     * @param string $xml Sheet XML content.
     * @param string[] $sharedStrings Shared strings lookup.
     * @param int $maxRows Maximum rows to parse.
     *
     * @return array<int, array<int, string>>
     */
    private function parseSheetXml(string $xml, array $sharedStrings, int $maxRows): array {
        $sheet = simplexml_load_string($xml);

        if ($sheet === false) {
            return [];
        }

        $rows = [];
        $rowCount = 0;

        foreach ($sheet->sheetData->row ?? [] as $row) {
            if ($rowCount >= $maxRows) {
                break;
            }

            $cells = [];

            foreach ($row->c as $cell) {
                $attrs = $cell->attributes();
                $type = (string) ($attrs['t'] ?? '');
                $value = (string) ($cell->v ?? '');

                // 's' type = shared string reference
                if ($type === 's' && isset($sharedStrings[(int) $value])) {
                    $value = $sharedStrings[(int) $value];
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                }

                $cells[] = $value;
            }

            $rows[] = $cells;
            $rowCount++;
        }

        return $rows;
    }

    /**
     * Converts rows to CSV format.
     *
     * @param array<int, array<int, string>> $rows
     *
     * @return string
     */
    private function toCsv(array $rows): string {
        $lines = [];

        foreach ($rows as $row) {
            $escaped = array_map(function (string $cell): string {
                if (str_contains($cell, ',') || str_contains($cell, '"') || str_contains($cell, "\n")) {
                    return '"' . str_replace('"', '""', $cell) . '"';
                }

                return $cell;
            }, $row);

            $lines[] = implode(',', $escaped);
        }

        return implode("\n", $lines);
    }

    /**
     * Converts rows to a Markdown table.
     *
     * @param array<int, array<int, string>> $rows
     *
     * @return string
     */
    private function toMarkdownTable(array $rows): string {
        if (empty($rows)) {
            return '';
        }

        $lines = [];
        $header = $rows[0];
        $colCount = count($header);

        // Header row
        $lines[] = '| ' . implode(' | ', $header) . ' |';

        // Separator
        $lines[] = '| ' . implode(' | ', array_fill(0, $colCount, '---')) . ' |';

        // Data rows
        foreach (array_slice($rows, 1) as $row) {
            // Pad row to header column count
            while (count($row) < $colCount) {
                $row[] = '';
            }

            $lines[] = '| ' . implode(' | ', $row) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * Converts rows to plain text (tab-separated).
     *
     * @param array<int, array<int, string>> $rows
     *
     * @return string
     */
    private function toPlainText(array $rows): string {
        return implode("\n", array_map(fn($row) => implode("\t", $row), $rows));
    }
}
