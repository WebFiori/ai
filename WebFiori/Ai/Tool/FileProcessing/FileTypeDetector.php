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
 * Detects MIME type and category for a file path or URL.
 *
 * Detection order:
 * 1. finfo (magic bytes) — most accurate for local files
 * 2. Extension map — fast fallback
 * 3. application/octet-stream — final fallback
 *
 * @author Ibrahim
 */
class FileTypeDetector {
    /**
     * Extension to MIME type map for common types.
     *
     * @var array<string, string>
     */
    private const EXTENSION_MAP = [
        // Text
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'json' => 'application/json',
        'xml'  => 'application/xml',
        'html' => 'text/html',
        'htm'  => 'text/html',
        'md'   => 'text/markdown',
        'yaml' => 'application/x-yaml',
        'yml'  => 'application/x-yaml',
        // Code
        'php'  => 'text/x-php',
        'py'   => 'text/x-python',
        'js'   => 'text/javascript',
        'ts'   => 'text/typescript',
        'css'  => 'text/css',
        'sql'  => 'text/x-sql',
        'sh'   => 'text/x-shellscript',
        // Office Open XML
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // ODF
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        // Legacy Office (binary)
        'xls'  => 'application/vnd.ms-excel',
        'doc'  => 'application/msword',
        'ppt'  => 'application/vnd.ms-powerpoint',
        // Archives
        'zip'  => 'application/zip',
        'tar'  => 'application/x-tar',
        'gz'   => 'application/gzip',
        // Images
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        // Documents
        'pdf'  => 'application/pdf',
        'rtf'  => 'application/rtf',
    ];

    /**
     * MIME types that are natively text-readable (no conversion needed).
     *
     * @var string[]
     */
    private const TEXT_MIME_PREFIXES = [
        'text/',
    ];

    /**
     * MIME types that are text-based but don't start with 'text/'.
     *
     * @var string[]
     */
    private const TEXT_MIME_TYPES = [
        'application/json',
        'application/xml',
        'application/x-yaml',
        'application/javascript',
        'application/x-php',
        'application/x-python',
        'application/x-sql',
        'application/x-shellscript',
        'image/svg+xml',
    ];

    /**
     * Detects MIME type and category for a file.
     *
     * @param string $pathOrUrl Local file path or URL.
     *
     * @return array{mime: string, extension: string, isText: bool, isUrl: bool}
     */
    public function detect(string $pathOrUrl): array {
        $isUrl = $this->isUrl($pathOrUrl);
        $extension = strtolower(pathinfo($pathOrUrl, PATHINFO_EXTENSION));
        $mime = $this->detectMime($pathOrUrl, $extension, $isUrl);
        $isText = $this->isTextMime($mime);

        return [
            'mime'      => $mime,
            'extension' => $extension,
            'isText'    => $isText,
            'isUrl'     => $isUrl,
        ];
    }

    /**
     * Determines if a string is a URL.
     *
     * @param string $input
     *
     * @return bool
     */
    public function isUrl(string $input): bool {
        return str_starts_with($input, 'http://') || str_starts_with($input, 'https://');
    }

    /**
     * Detects the MIME type.
     *
     * @param string $pathOrUrl
     * @param string $extension
     * @param bool $isUrl
     *
     * @return string
     */
    private function detectMime(string $pathOrUrl, string $extension, bool $isUrl): string {
        // For local files try finfo first (magic bytes are most accurate)
        if (!$isUrl && file_exists($pathOrUrl) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $pathOrUrl);
            finfo_close($finfo);

            if ($mime !== false && $mime !== 'application/octet-stream') {
                // finfo returns 'application/zip' for OOXML formats
                // Use extension map to get the more specific type
                if ($mime === 'application/zip' && isset(self::EXTENSION_MAP[$extension])) {
                    return self::EXTENSION_MAP[$extension];
                }

                return $mime;
            }
        }

        // Fall back to extension map
        if (isset(self::EXTENSION_MAP[$extension])) {
            return self::EXTENSION_MAP[$extension];
        }

        return 'application/octet-stream';
    }

    /**
     * Determines if a MIME type is text-based (no conversion needed).
     *
     * @param string $mime
     *
     * @return bool
     */
    private function isTextMime(string $mime): bool {
        foreach (self::TEXT_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return in_array($mime, self::TEXT_MIME_TYPES, true);
    }
}
