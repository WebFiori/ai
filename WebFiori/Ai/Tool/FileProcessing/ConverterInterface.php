<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool\FileProcessing;

/**
 * Contract for file content converters.
 *
 * Implementations convert raw file bytes to a human-readable string
 * format that AI models can process.
 *
 * @author Ibrahim
 */
interface ConverterInterface {
    /**
     * Converts raw file content to a readable string.
     *
     * @param string $content Raw file bytes.
     * @param ConversionOptions $options Conversion options.
     *
     * @return ConversionResult The conversion result.
     */
    public function convert(string $content, ConversionOptions $options): ConversionResult;

    /**
     * Returns the default output format for this converter.
     *
     * @return string One of: plain_text, csv, markdown_table, json
     */
    public function getDefaultOutputFormat(): string;

    /**
     * Returns file extensions this converter handles.
     *
     * @return string[] Lowercase extensions without leading dot (e.g., ['xlsx', 'xls']).
     */
    public function getSupportedExtensions(): array;

    /**
     * Returns MIME types this converter handles.
     *
     * @return string[]
     */
    public function getSupportedMimeTypes(): array;
}
