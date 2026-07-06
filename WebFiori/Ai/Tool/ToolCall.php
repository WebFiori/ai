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
 * Represents a tool invocation requested by an AI model.
 *
 * When the AI determines it needs to call a tool, it returns one or more
 * ToolCall objects indicating which tool to invoke and with what arguments.
 *
 * @author Ibrahim
 */
class ToolCall {
    /**
     * The arguments to pass to the tool, decoded from the AI response.
     *
     * @var array<string, mixed>
     */
    private array $arguments;

    /**
     * The unique identifier for this tool call (assigned by the provider).
     *
     * @var string
     */
    private string $id;

    /**
     * The name of the tool to invoke.
     *
     * @var string
     */
    private string $name;

    /**
     * Creates a new ToolCall instance.
     *
     * @param string $id The unique identifier for this tool call.
     * @param string $name The name of the tool to invoke.
     * @param array<string, mixed> $arguments The arguments to pass to the tool.
     */
    public function __construct(string $id, string $name, array $arguments = []) {
        $this->id = $id;
        $this->name = $name;
        $this->arguments = $arguments;
    }

    /**
     * Returns the arguments to pass to the tool.
     *
     * @return array<string, mixed> The tool call arguments.
     */
    public function getArguments(): array {
        return $this->arguments;
    }

    /**
     * Returns the unique identifier for this tool call.
     *
     * @return string The tool call ID.
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Returns the name of the tool to invoke.
     *
     * @return string The tool name.
     */
    public function getName(): string {
        return $this->name;
    }
}
