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

use RuntimeException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Tool\FileProcessing\Converter\DocumentConverter;
use WebFiori\Ai\Tool\FileProcessing\Converter\PresentationConverter;
use WebFiori\Ai\Tool\FileProcessing\Converter\SpreadsheetConverter;
use WebFiori\Ai\Tool\FileProcessing\Converter\TextConverter;
use WebFiori\Ai\Tool\ToolInterface;

/**
 * Universal file content extraction tool for AI models.
 *
 * Converts files of any supported format to readable text so that AI models
 * can process their content. The model invokes this tool when it encounters
 * a file it cannot process directly.
 *
 * Built-in support: text files, XLSX, DOCX, PPTX, ODS, ODT.
 * Custom formats: register a ConverterInterface implementation.
 *
 * Usage:
 * ```php
 * $extractor = new FileContentExtractor();
 * $extractor->setAllowedPaths(['/var/app/uploads']);
 *
 * $response = $client->chat($messages, [
 *     'tools'             => [$extractor],
 *     'auto_execute_tools' => true,
 * ]);
 * ```
 *
 * @author Ibrahim
 */
class FileContentExtractor implements ToolInterface {
    /**
     * Allowed base paths for security. Empty = no restriction.
     *
     * @var string[]
     */
    private array $allowedPaths = [];

    /**
     * Developer-locked output format. Overrides everything else.
     *
     * @var string|null
     */
    private ?string $defaultOutputFormat = null;

    /**
     * File type detector.
     *
     * @var FileTypeDetector
     */
    private FileTypeDetector $detector;

    /**
     * Global max output in characters.
     *
     * @var int
     */
    private int $maxOutput = 50000;

    /**
     * Converter registry.
     *
     * @var ConverterRegistry
     */
    private ConverterRegistry $registry;

    /**
     * Creates a new FileContentExtractor instance with built-in converters.
     */
    public function __construct() {
        $this->registry = new ConverterRegistry();
        $this->detector = new FileTypeDetector();

        // Register built-in converters (priority 0)
        $this->registry->register(new TextConverter(), priority: 0);
        $this->registry->register(new SpreadsheetConverter(), priority: 0);
        $this->registry->register(new DocumentConverter(), priority: 0);
        $this->registry->register(new PresentationConverter(), priority: 0);
    }

    /**
     * Executes the file content extraction.
     *
     * @param array<string, mixed> $arguments Tool arguments from the model.
     *
     * @return string JSON-encoded result or error.
     */
    public function execute(array $arguments): string {
        $filePath = $arguments['file_path'] ?? '';

        if ($filePath === '') {
            return json_encode(['error' => 'file_path is required.']);
        }

        try {
            // Security: validate path
            if (!$this->detector->isUrl($filePath)) {
                $this->validatePath($filePath);
            }

            // Detect file type
            $detected = $this->detector->detect($filePath);

            // Fetch content
            $rawContent = $this->fetchContent($filePath, $detected['isUrl']);

            // Resolve converter
            $converter = $this->registry->resolve($detected['mime'], $detected['extension']);

            if ($converter === null && !$detected['isText']) {
                throw new UnsupportedFeatureException(
                    "file_type:{$detected['mime']}",
                    'FileContentExtractor'
                );
            }

            // Use text converter for text files with no specific converter
            if ($converter === null) {
                $converter = new TextConverter();
            }

            // Build options (priority: model arg → global → default 50000)
            $maxOutput = isset($arguments['max_output'])
                ? (int) $arguments['max_output']
                : $this->maxOutput;

            $outputFormat = $this->defaultOutputFormat
                ?? $arguments['output_format']
                ?? 'auto';

            $extras = is_array($arguments['options'] ?? null) ? $arguments['options'] : [];

            $options = new ConversionOptions(
                outputFormat: $outputFormat,
                maxOutput: $maxOutput,
                extras: $extras,
            );

            // Convert
            $result = $converter->convert($rawContent, $options);

            $response = [
                'content' => $result->getContent(),
                'format' => $result->getFormat(),
                'mime_type' => $result->getMimeType(),
                'file_name' => basename($filePath),
                'truncated' => $result->isTruncated(),
            ];

            if ($result->isTruncated()) {
                $response['original_size'] = $result->getOriginalSize();
            }

            if (!empty($result->getMetadata())) {
                $response['metadata'] = $result->getMetadata();
            }

            return json_encode($response, JSON_UNESCAPED_UNICODE);
        } catch (UnsupportedFeatureException $e) {
            return json_encode([
                'error' => $e->getMessage(),
                'file_name' => basename($filePath),
                'hint' => 'Register a custom converter for this file type using registerConverter().',
            ]);
        } catch (RuntimeException $e) {
            return json_encode([
                'error' => $e->getMessage(),
                'file_name' => basename($filePath),
            ]);
        }
    }

    /**
     * Returns the tool description.
     *
     * @return string
     */
    public function getDescription(): string {
        return 'Extracts readable text content from a file or URL. '
             .'Use this when you receive a file path or URL and need to read its content. '
             .'Supports text files, spreadsheets (xlsx), documents (docx), presentations (pptx), and more.';
    }

    /**
     * Returns the tool name.
     *
     * @return string
     */
    public function getName(): string {
        return 'extract_file_content';
    }

    /**
     * Returns the JSON Schema for tool parameters.
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Absolute local file path or HTTP(S) URL to the file.',
                ],
                'output_format' => [
                    'type' => 'string',
                    'enum' => ['auto', 'plain_text', 'csv', 'markdown_table', 'json'],
                    'description' => 'Preferred output format. Defaults to auto (converter decides).',
                ],
                'max_output' => [
                    'type' => 'integer',
                    'description' => 'Maximum characters to return. Default: 50000.',
                ],
                'options' => [
                    'type' => 'object',
                    'description' => 'Extra options: sheet_name, max_rows, page_range, include_metadata.',
                ],
            ],
            'required' => ['file_path'],
        ];
    }

    /**
     * Registers a custom converter.
     *
     * Developer converters default to priority 10, overriding built-in
     * converters which use priority 0.
     *
     * @param ConverterInterface $converter The converter to register.
     * @param int $priority Higher priority wins. Default: 10.
     *
     * @return self For method chaining.
     */
    public function registerConverter(ConverterInterface $converter, int $priority = 10): self {
        $this->registry->register($converter, $priority);

        return $this;
    }

    /**
     * Restricts file access to the specified directories.
     *
     * When set, any path outside these directories throws before the file
     * is read. Resolves symlinks to prevent traversal attacks.
     *
     * @param string[] $paths Allowed base directories.
     *
     * @return self For method chaining.
     */
    public function setAllowedPaths(array $paths): self {
        $this->allowedPaths = $paths;

        return $this;
    }

    /**
     * Sets the developer-level default output format.
     *
     * When set, this overrides both the model's output_format parameter
     * and the converter's default format.
     *
     * @param string $format One of: auto, plain_text, csv, markdown_table, json
     *
     * @return self For method chaining.
     */
    public function setDefaultOutputFormat(string $format): self {
        $this->defaultOutputFormat = $format;

        return $this;
    }

    /**
     * Sets the global maximum output in characters.
     *
     * The model's max_output parameter overrides this value per call.
     *
     * @param int $chars Maximum characters.
     *
     * @return self For method chaining.
     */
    public function setMaxOutput(int $chars): self {
        $this->maxOutput = $chars;

        return $this;
    }

    /**
     * Fetches raw file content from a path or URL.
     *
     * @param string $pathOrUrl
     * @param bool $isUrl
     *
     * @return string Raw bytes.
     *
     * @throws RuntimeException If the file cannot be read.
     */
    private function fetchContent(string $pathOrUrl, bool $isUrl): string {
        if ($isUrl) {
            $content = @file_get_contents($pathOrUrl);

            if ($content === false) {
                throw new RuntimeException("Failed to fetch URL: {$pathOrUrl}");
            }

            return $content;
        }

        if (!file_exists($pathOrUrl)) {
            throw new RuntimeException("File not found: {$pathOrUrl}");
        }

        $content = file_get_contents($pathOrUrl);

        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$pathOrUrl}");
        }

        return $content;
    }

    /**
     * Validates that a local path is within the allowed directories.
     *
     * @param string $path
     *
     * @throws RuntimeException If path is outside allowed directories.
     */
    private function validatePath(string $path): void {
        if (empty($this->allowedPaths)) {
            return;
        }

        $real = realpath($path);

        if ($real === false) {
            // File doesn't exist yet — validate the directory
            $real = realpath(dirname($path));

            if ($real === false) {
                throw new RuntimeException("Path not found: {$path}");
            }
        }

        foreach ($this->allowedPaths as $allowed) {
            $realAllowed = realpath($allowed);

            if ($realAllowed !== false && str_starts_with($real, $realAllowed)) {
                return;
            }
        }

        throw new RuntimeException(
            "Access denied: '{$path}' is outside the allowed directories. ".
            "Call setAllowedPaths() to configure allowed directories."
        );
    }
}
