<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai;

use InvalidArgumentException;

/**
 * Represents a content part within a multi-modal message.
 *
 * ContentPart allows messages to contain mixed content types such as text,
 * images, documents, audio, and video. This enables multi-modal capabilities
 * with models that support such input (GPT-4o, Gemini, Claude 3, etc.).
 *
 * Usage:
 * ```php
 * // Text and image
 * $message = new Message('user', [
 *     ContentPart::text('What is in this image?'),
 *     ContentPart::imageUrl('https://example.com/photo.jpg'),
 * ]);
 *
 * // Analyze a PDF
 * $message = new Message('user', [
 *     ContentPart::text('Summarize this document:'),
 *     ContentPart::file('/path/to/document.pdf'),
 * ]);
 *
 * // Any file with explicit MIME type
 * $message = new Message('user', [
 *     ContentPart::text('What does this code do?'),
 *     ContentPart::document($base64Data, 'text/plain'),
 * ]);
 * ```
 *
 * @author Ibrahim
 */
class ContentPart {
    /**
     * Content type constant for text.
     */
    public const TYPE_TEXT = 'text';

    /**
     * Content type constant for image URL.
     */
    public const TYPE_IMAGE_URL = 'image_url';

    /**
     * Content type constant for base64-encoded image.
     */
    public const TYPE_IMAGE_BASE64 = 'image_base64';

    /**
     * Content type constant for Google Cloud Storage URI.
     */
    public const TYPE_FILE_GCS = 'file_gcs';

    /**
     * Content type constant for base64-encoded document (PDF, audio, video, etc.).
     */
    public const TYPE_DOCUMENT = 'document';

    /**
     * Supported image MIME types.
     */
    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Supported document MIME types.
     */
    private const DOCUMENT_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/html',
        'text/css',
        'text/csv',
        'text/markdown',
        'text/xml',
        'text/rtf',
        'application/json',
        'application/xml',
    ];

    /**
     * Supported audio MIME types.
     */
    private const AUDIO_MIME_TYPES = [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/ogg',
        'audio/webm',
        'audio/aac',
        'audio/flac',
    ];

    /**
     * Supported video MIME types.
     */
    private const VIDEO_MIME_TYPES = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-msvideo',
        'video/mpeg',
    ];

    /**
     * File extension to MIME type mapping.
     */
    private const EXTENSION_TO_MIME = [
        // Images
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        // Documents
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'json' => 'application/json',
        'xml' => 'application/xml',
        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'webm' => 'audio/webm',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        // Video
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mpeg' => 'video/mpeg',
        // Text/Code (everything else falls back to text/plain)
    ];

    /**
     * The content type.
     *
     * @var string
     */
    private string $type;

    /**
     * The content data.
     *
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * Creates a new ContentPart instance.
     *
     * @param string $type The content type.
     * @param array<string, mixed> $data The content data.
     */
    private function __construct(string $type, array $data) {
        $this->type = $type;
        $this->data = $data;
    }

    /**
     * Creates a text content part.
     *
     * @param string $text The text content.
     *
     * @return self A new text content part.
     */
    public static function text(string $text): self {
        return new self(self::TYPE_TEXT, ['text' => $text]);
    }

    /**
     * Creates an image content part from a URL.
     *
     * @param string $url The image URL (http:// or https://).
     *
     * @return self A new image URL content part.
     *
     * @throws InvalidArgumentException If the URL is invalid.
     */
    public static function imageUrl(string $url): self {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid image URL: {$url}");
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException("Image URL must use http:// or https:// scheme: {$url}");
        }

        return new self(self::TYPE_IMAGE_URL, ['url' => $url]);
    }

    /**
     * Creates an image content part from base64-encoded data.
     *
     * @param string $data The base64-encoded image data.
     * @param string $mimeType The image MIME type (e.g., 'image/png').
     *
     * @return self A new base64 image content part.
     *
     * @throws InvalidArgumentException If the MIME type is not a supported image type.
     */
    public static function imageBase64(string $data, string $mimeType): self {
        if (!in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            $supported = implode(', ', self::IMAGE_MIME_TYPES);

            throw new InvalidArgumentException(
                "Unsupported image MIME type: {$mimeType}. Supported types: {$supported}"
            );
        }

        return new self(self::TYPE_IMAGE_BASE64, [
            'data' => $data,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Creates a content part from a local file.
     *
     * The file is read and base64-encoded. MIME type is auto-detected
     * from the file extension. Supports images, PDFs, Office documents,
     * audio, video, and text/code files.
     *
     * For unknown extensions, the file is treated as text/plain.
     *
     * @param string $path The path to the file.
     *
     * @return self A new content part appropriate for the file type.
     *
     * @throws InvalidArgumentException If the file does not exist or is not readable.
     */
    public static function file(string $path): self {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException("File is not readable: {$path}");
        }

        $mimeType = self::detectMimeType($path);
        $data = base64_encode(file_get_contents($path));

        // Use appropriate type based on MIME category
        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            return new self(self::TYPE_IMAGE_BASE64, [
                'data' => $data,
                'mime_type' => $mimeType,
            ]);
        }

        return new self(self::TYPE_DOCUMENT, [
            'data' => $data,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Creates a content part from base64-encoded data with explicit MIME type.
     *
     * Use this for documents, audio, video, or any file type that isn't an image URL.
     * For images, prefer imageBase64() for clarity.
     *
     * @param string $data The base64-encoded file data.
     * @param string $mimeType The MIME type (e.g., 'application/pdf', 'audio/mp3').
     *
     * @return self A new document content part.
     *
     * @throws InvalidArgumentException If the MIME type is not supported.
     */
    public static function document(string $data, string $mimeType): self {
        $allSupported = array_merge(
            self::IMAGE_MIME_TYPES,
            self::DOCUMENT_MIME_TYPES,
            self::AUDIO_MIME_TYPES,
            self::VIDEO_MIME_TYPES
        );

        if (!in_array($mimeType, $allSupported, true)) {
            throw new InvalidArgumentException(
                "Unsupported MIME type: {$mimeType}. Use a supported image, document, audio, or video MIME type."
            );
        }

        // Route images to the image type for consistent handling
        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            return new self(self::TYPE_IMAGE_BASE64, [
                'data' => $data,
                'mime_type' => $mimeType,
            ]);
        }

        return new self(self::TYPE_DOCUMENT, [
            'data' => $data,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Creates a content part from a Google Cloud Storage URI.
     *
     * This is primarily supported by Google Vertex AI provider.
     * Works with any supported file type (images, documents, audio, video).
     *
     * @param string $uri The GCS URI (gs://bucket/path/to/file).
     * @param string $mimeType The file MIME type.
     *
     * @return self A new GCS file content part.
     *
     * @throws InvalidArgumentException If the URI format is invalid.
     */
    public static function gcsUri(string $uri, string $mimeType): self {
        if (!str_starts_with($uri, 'gs://')) {
            throw new InvalidArgumentException("GCS URI must start with gs://: {$uri}");
        }

        return new self(self::TYPE_FILE_GCS, [
            'uri' => $uri,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Returns the content type.
     *
     * @return string One of the TYPE_* constants.
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * Returns the content data.
     *
     * The structure depends on the content type:
     * - TYPE_TEXT: ['text' => string]
     * - TYPE_IMAGE_URL: ['url' => string]
     * - TYPE_IMAGE_BASE64: ['data' => string, 'mime_type' => string]
     * - TYPE_DOCUMENT: ['data' => string, 'mime_type' => string]
     * - TYPE_FILE_GCS: ['uri' => string, 'mime_type' => string]
     *
     * @return array<string, mixed> The content data.
     */
    public function getData(): array {
        return $this->data;
    }

    /**
     * Checks if this content part is text.
     *
     * @return bool True if this is a text content part.
     */
    public function isText(): bool {
        return $this->type === self::TYPE_TEXT;
    }

    /**
     * Checks if this content part is an image.
     *
     * @return bool True if this is any type of image content part.
     */
    public function isImage(): bool {
        if ($this->type === self::TYPE_IMAGE_URL || $this->type === self::TYPE_IMAGE_BASE64) {
            return true;
        }

        if ($this->type === self::TYPE_FILE_GCS || $this->type === self::TYPE_DOCUMENT) {
            $mimeType = $this->data['mime_type'] ?? '';

            return in_array($mimeType, self::IMAGE_MIME_TYPES, true);
        }

        return false;
    }

    /**
     * Checks if this content part is a document (PDF, Office, text, etc.).
     *
     * @return bool True if this is a document content part.
     */
    public function isDocument(): bool {
        if ($this->type === self::TYPE_DOCUMENT || $this->type === self::TYPE_FILE_GCS) {
            $mimeType = $this->data['mime_type'] ?? '';

            return in_array($mimeType, self::DOCUMENT_MIME_TYPES, true);
        }

        return false;
    }

    /**
     * Checks if this content part is audio.
     *
     * @return bool True if this is an audio content part.
     */
    public function isAudio(): bool {
        if ($this->type === self::TYPE_DOCUMENT || $this->type === self::TYPE_FILE_GCS) {
            $mimeType = $this->data['mime_type'] ?? '';

            return in_array($mimeType, self::AUDIO_MIME_TYPES, true);
        }

        return false;
    }

    /**
     * Checks if this content part is video.
     *
     * @return bool True if this is a video content part.
     */
    public function isVideo(): bool {
        if ($this->type === self::TYPE_DOCUMENT || $this->type === self::TYPE_FILE_GCS) {
            $mimeType = $this->data['mime_type'] ?? '';

            return in_array($mimeType, self::VIDEO_MIME_TYPES, true);
        }

        return false;
    }

    /**
     * Checks if this content part is a media file (image, audio, or video).
     *
     * @return bool True if this is a media content part.
     */
    public function isMedia(): bool {
        return $this->isImage() || $this->isAudio() || $this->isVideo();
    }

    /**
     * Returns the text content if this is a text part.
     *
     * @return string|null The text content, or null if not a text part.
     */
    public function getText(): ?string {
        return $this->data['text'] ?? null;
    }

    /**
     * Returns the MIME type of this content part.
     *
     * @return string|null The MIME type, or null for text and URL types.
     */
    public function getMimeType(): ?string {
        return $this->data['mime_type'] ?? null;
    }

    /**
     * Returns all supported MIME types.
     *
     * @return string[] List of all supported MIME types.
     */
    public static function getSupportedMimeTypes(): array {
        return array_merge(
            self::IMAGE_MIME_TYPES,
            self::DOCUMENT_MIME_TYPES,
            self::AUDIO_MIME_TYPES,
            self::VIDEO_MIME_TYPES
        );
    }

    /**
     * Detects the MIME type of a file based on its extension.
     *
     * For unknown extensions, returns 'text/plain' as a fallback,
     * treating the file as a text/code file.
     *
     * @param string $path The file path.
     *
     * @return string The detected MIME type.
     */
    private static function detectMimeType(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Known extensions
        if (isset(self::EXTENSION_TO_MIME[$extension])) {
            return self::EXTENSION_TO_MIME[$extension];
        }

        // Fallback: treat as text/plain (code files, config files, etc.)
        return 'text/plain';
    }
}
