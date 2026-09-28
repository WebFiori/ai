<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool;

use WebFiori\Ai\ContentPart;

/**
 * Represents a rich tool output that can carry text and multimodal content.
 *
 * Tools currently return plain strings from `execute()`. `ToolResponse` allows
 * tools to optionally return structured content — text combined with images,
 * documents, or other content parts — so the model can visually analyze
 * extracted charts, diagrams, or other embedded media.
 *
 * Backward compatibility is preserved via `__toString()`: providers that do
 * not support multimodal tool results automatically receive only the text.
 * Existing tools returning plain strings are entirely unaffected.
 *
 * Usage:
 * ```php
 * // Text-only (same as returning a plain string)
 * return ToolResponse::text('The result is 42.');
 *
 * // Text + images (model can visually analyze the images)
 * return ToolResponse::withImages(
 *     'Extracted text from spreadsheet...',
 *     [
 *         ContentPart::imageBase64($base64Data, 'image/png'),
 *         ContentPart::imageBase64($chartData, 'image/png'),
 *     ]
 * );
 * ```
 *
 * @author Ibrahim
 */
class ToolResponse {
    /**
     * Whether this response represents a tool execution failure.
     *
     * @var bool
     */
    private bool $isError;
    /**
     * The content parts carrying multimodal content.
     *
     * @var ContentPart[]
     */
    private array $parts;

    /**
     * The primary text content of the tool result.
     *
     * @var string
     */
    private string $text;

    /**
     * Creates a new ToolResponse instance.
     *
     * @param string $text The primary text content of the result.
     * @param ContentPart[] $parts Optional content parts (images, documents, etc.)
     *        for multimodal tool responses.
     * @param bool $isError Whether this response represents a tool failure.
     *        Defaults to false so existing usages remain successful results.
     */
    public function __construct(string $text, array $parts = [], bool $isError = false) {
        $this->text = $text;
        $this->parts = $parts;
        $this->isError = $isError;
    }

    /**
     * Returns the text content for backward-compatible string contexts.
     *
     * Providers that do not support multimodal tool results will use this
     * automatically when casting to string.
     *
     * @return string The text content.
     */
    public function __toString(): string {
        return $this->text;
    }

    /**
     * Creates a ToolResponse that represents a tool execution failure.
     *
     * Use this to signal failure deterministically so the status stream can
     * report the tool as failed ({@see \WebFiori\Ai\Status::TOOL_FAILED}) with
     * an `error_type` of `tool_error`. The message is still passed to the model
     * as the tool output so the conversation can continue.
     *
     * @param string $message A short description of what went wrong.
     * @param ContentPart[] $parts Optional content parts to accompany the error.
     *
     * @return self An error response.
     */
    public static function error(string $message, array $parts = []): self {
        return new self($message, $parts, true);
    }

    /**
     * Returns the content parts carrying multimodal content.
     *
     * @return ContentPart[] The content parts, or empty array if text-only.
     */
    public function getParts(): array {
        return $this->parts;
    }

    /**
     * Returns the primary text content.
     *
     * @return string The text content.
     */
    public function getText(): string {
        return $this->text;
    }

    /**
     * Returns whether this response represents a tool execution failure.
     *
     * Tools may return `ToolResponse::error()` to signal a failure
     * deterministically, rather than relying on the older convention of
     * returning an error string. When true, the status stream reports the
     * tool as failed ({@see \WebFiori\Ai\Status::TOOL_FAILED}).
     *
     * @return bool True if this response is an error result.
     */
    public function isError(): bool {
        return $this->isError;
    }

    /**
     * Returns whether this response carries multimodal content.
     *
     * @return bool True if there are content parts beyond plain text.
     */
    public function isMultimodal(): bool {
        return !empty($this->parts);
    }

    /**
     * Creates a text-only ToolResponse.
     *
     * Equivalent to returning a plain string from a tool, but wrapped in
     * ToolResponse for consistency and forward compatibility.
     *
     * @param string $text The text result.
     *
     * @return self A text-only response.
     */
    public static function text(string $text): self {
        return new self($text);
    }

    /**
     * Creates a ToolResponse with text and image content parts.
     *
     * Allows a tool to return extracted images alongside text so the model
     * can visually analyze charts, diagrams, or other embedded media.
     *
     * @param string $text The text portion of the result.
     * @param ContentPart[] $images An array of ContentPart image objects.
     *
     * @return self A multimodal response with text and images.
     */
    public static function withImages(string $text, array $images): self {
        return new self($text, $images);
    }

    /**
     * Creates a ToolResponse with text and arbitrary content parts.
     *
     * For advanced use cases where the tool returns mixed content (text,
     * images, documents, etc.).
     *
     * @param string $text The text portion of the result.
     * @param ContentPart[] $parts An array of ContentPart objects.
     *
     * @return self A multimodal response.
     */
    public static function withParts(string $text, array $parts): self {
        return new self($text, $parts);
    }
}
