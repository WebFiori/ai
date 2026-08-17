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

use WebFiori\Ai\Tool\FileProcessing\AbstractConverter;
use WebFiori\Ai\Tool\FileProcessing\ConversionOptions;
use WebFiori\Ai\Tool\FileProcessing\ConversionResult;

/**
 * Converter for plain text and text-based formats.
 *
 * Handles all text/* MIME types and common text-based formats (CSV, JSON,
 * XML, HTML, Markdown, code files). Returns content as-is since the model
 * can read these directly.
 *
 * @author Ibrahim
 */
class TextConverter extends AbstractConverter {
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
        return [
            'txt', 'csv', 'json', 'xml', 'html', 'htm', 'md', 'markdown',
            'yaml', 'yml', 'php', 'py', 'js', 'ts', 'css', 'sql', 'sh',
            'bash', 'rb', 'go', 'java', 'c', 'cpp', 'h', 'rs', 'swift',
            'kt', 'r', 'ini', 'toml', 'env', 'log',
        ];
    }

    /**
     * Returns supported MIME types.
     *
     * @return string[]
     */
    public function getSupportedMimeTypes(): array {
        return [
            'text/plain', 'text/csv', 'text/html', 'text/markdown',
            'text/css', 'text/javascript', 'text/x-php', 'text/x-python',
            'text/x-shellscript', 'text/x-sql', 'text/typescript',
            'application/json', 'application/xml', 'application/x-yaml',
            'application/javascript', 'application/x-php',
            'application/x-python', 'application/x-sql',
            'image/svg+xml',
        ];
    }

    /**
     * Returns content as-is (already readable).
     *
     * @param string $content Raw file bytes.
     * @param ConversionOptions $options Conversion options.
     *
     * @return ConversionResult
     */
    public function convert(string $content, ConversionOptions $options): ConversionResult {
        $format = $this->resolveFormat($options->getOutputFormat());

        $lineCount = $content !== '' ? substr_count($content, "\n") + 1 : 0;
        $charCount = mb_strlen($content);
        $wordCount = $content !== '' ? str_word_count($content) : 0;

        $metadata = [
            'line_count' => $lineCount,
            'word_count' => $wordCount,
            'char_count' => $charCount,
        ];

        return $this->makeResult(
            content: $content,
            maxOutput: $options->getMaxOutput(),
            mimeType: 'text/plain',
            format: $format,
            metadata: $metadata,
        );
    }
}
