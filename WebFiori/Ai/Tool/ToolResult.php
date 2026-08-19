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
 * Represents the result of executing a tool.
 *
 * After a tool is executed, a ToolResult is created and included in the
 * conversation so the AI model can use the output in its response.
 * When a tool returns a ToolResponse with images or other content parts,
 * those are carried here so providers can format multimodal function responses.
 *
 * @author Ibrahim
 */
class ToolResult {
    /**
     * The content/output produced by the tool execution.
     *
     * @var string
     */
    private string $content;

    /**
     * The name of the tool that produced this result.
     *
     * @var string
     */
    private string $name;

    /**
     * Additional content parts for multimodal tool results (images, etc.).
     *
     * @var ContentPart[]
     */
    private array $parts;

    /**
     * The ID of the tool call this result corresponds to.
     *
     * @var string
     */
    private string $toolCallId;

    /**
     * Creates a new ToolResult instance.
     *
     * @param string $toolCallId The ID of the tool call this result corresponds to.
     * @param string $content The content/output produced by the tool execution.
     * @param string $name The name of the tool. Defaults to empty string for
     *        backward compatibility.
     * @param ContentPart[] $parts Optional content parts for multimodal results.
     */
    public function __construct(string $toolCallId, string $content, string $name = '', array $parts = []) {
        $this->toolCallId = $toolCallId;
        $this->content = $content;
        $this->name = $name;
        $this->parts = $parts;
    }

    /**
     * Returns the content/output produced by the tool execution.
     *
     * @return string The tool execution result.
     */
    public function getContent(): string {
        return $this->content;
    }

    /**
     * Returns the name of the tool.
     *
     * @return string The tool name.
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Returns additional content parts for multimodal tool results.
     *
     * @return ContentPart[] The content parts, or empty array if text-only.
     */
    public function getParts(): array {
        return $this->parts;
    }

    /**
     * Returns the ID of the tool call this result corresponds to.
     *
     * @return string The tool call ID.
     */
    public function getToolCallId(): string {
        return $this->toolCallId;
    }

    /**
     * Returns whether this result carries multimodal content parts.
     *
     * @return bool True if there are content parts beyond plain text.
     */
    public function isMultimodal(): bool {
        return !empty($this->parts);
    }
}
