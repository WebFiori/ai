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
 * ContentPart allows messages to contain mixed content types such as text
 * and images. This enables vision capabilities with models that support
 * multi-modal input (GPT-4o, Gemini, Claude 3, etc.).
 *
 * Usage:
 * ```php
 * $message = new Message('user', [
 *     ContentPart::text('What is in this image?'),
 *     ContentPart::imageUrl('https://example.com/photo.jpg'),
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
    public const TYPE_IMAGE_GCS = 'image_gcs';

    /**
     * Supported image MIME types.
     */
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * MIME type to file extension mapping.
     */
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
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
     * @throws InvalidArgumentException If the MIME type is not supported.
     */
    public static function imageBase64(string $data, string $mimeType): self {
        self::validateMimeType($mimeType);

        return new self(self::TYPE_IMAGE_BASE64, [
            'data' => $data,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Creates an image content part from a local file.
     *
     * The file is read and base64-encoded. MIME type is auto-detected
     * from the file extension.
     *
     * @param string $path The path to the image file.
     *
     * @return self A new base64 image content part.
     *
     * @throws InvalidArgumentException If the file does not exist or has
     *         an unsupported format.
     */
    public static function file(string $path): self {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("Image file not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException("Image file is not readable: {$path}");
        }

        $mimeType = self::detectMimeType($path);
        $data = base64_encode(file_get_contents($path));

        return new self(self::TYPE_IMAGE_BASE64, [
            'data' => $data,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Creates an image content part from a Google Cloud Storage URI.
     *
     * This is only supported by Google Vertex AI provider.
     *
     * @param string $uri The GCS URI (gs://bucket/path/to/image.jpg).
     * @param string $mimeType The image MIME type.
     *
     * @return self A new GCS image content part.
     *
     * @throws InvalidArgumentException If the URI format is invalid or
     *         MIME type is not supported.
     */
    public static function gcsUri(string $uri, string $mimeType): self {
        if (!str_starts_with($uri, 'gs://')) {
            throw new InvalidArgumentException("GCS URI must start with gs://: {$uri}");
        }

        self::validateMimeType($mimeType);

        return new self(self::TYPE_IMAGE_GCS, [
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
     * - TYPE_IMAGE_GCS: ['uri' => string, 'mime_type' => string]
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
        return in_array($this->type, [
            self::TYPE_IMAGE_URL,
            self::TYPE_IMAGE_BASE64,
            self::TYPE_IMAGE_GCS,
        ], true);
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
     * Validates that a MIME type is supported.
     *
     * @param string $mimeType The MIME type to validate.
     *
     * @throws InvalidArgumentException If the MIME type is not supported.
     */
    private static function validateMimeType(string $mimeType): void {
        if (!in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            $supported = implode(', ', self::SUPPORTED_MIME_TYPES);

            throw new InvalidArgumentException(
                "Unsupported image MIME type: {$mimeType}. Supported types: {$supported}"
            );
        }
    }

    /**
     * Detects the MIME type of a file based on its extension.
     *
     * @param string $path The file path.
     *
     * @return string The detected MIME type.
     *
     * @throws InvalidArgumentException If the file extension is not supported.
     */
    private static function detectMimeType(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $extensionToMime = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        if (!isset($extensionToMime[$extension])) {
            $supported = implode(', ', array_keys($extensionToMime));

            throw new InvalidArgumentException(
                "Unsupported image file extension: .{$extension}. Supported extensions: {$supported}"
            );
        }

        return $extensionToMime[$extension];
    }
}
