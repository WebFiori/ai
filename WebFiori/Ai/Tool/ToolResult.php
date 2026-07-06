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

/**
 * Represents the result of executing a tool.
 *
 * After a tool is executed, a ToolResult is created and included in the
 * conversation so the AI model can use the output in its response.
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
     */
    public function __construct(string $toolCallId, string $content) {
        $this->toolCallId = $toolCallId;
        $this->content = $content;
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
     * Returns the ID of the tool call this result corresponds to.
     *
     * @return string The tool call ID.
     */
    public function getToolCallId(): string {
        return $this->toolCallId;
    }
}
