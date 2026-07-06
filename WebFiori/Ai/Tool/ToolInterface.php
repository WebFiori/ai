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
 * Contract for defining a tool that an AI model can invoke during conversation.
 *
 * Tools allow the AI to call external functions to gather information
 * or perform actions (e.g., look up a database, call an API, etc.).
 *
 * @author Ibrahim
 */
interface ToolInterface {
    /**
     * Executes the tool with the given arguments.
     *
     * @param array<string, mixed> $arguments The arguments provided by the AI model,
     *        matching the parameter schema defined in {@see getParameters()}.
     *
     * @return string The result of the tool execution, to be sent back to the AI model.
     */
    public function execute(array $arguments): string;

    /**
     * Returns a human-readable description of what the tool does.
     *
     * This description is sent to the AI model to help it decide
     * when and how to use the tool.
     *
     * @return string A description of the tool's purpose and behavior.
     */
    public function getDescription(): string;

    /**
     * Returns the unique name of the tool.
     *
     * The name is used by the AI model to reference this tool when
     * requesting an invocation.
     *
     * @return string The tool name (e.g., 'get_weather', 'search_database').
     */
    public function getName(): string;

    /**
     * Returns the JSON Schema definition for the tool's parameters.
     *
     * This schema describes what arguments the tool accepts, their types,
     * and which are required. The AI model uses this to generate valid
     * arguments when calling the tool.
     *
     * @return array<string, mixed> A JSON Schema object describing the parameters.
     *         Example:
     *         [
     *             'type' => 'object',
     *             'properties' => [
     *                 'location' => ['type' => 'string', 'description' => 'City name'],
     *                 'unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']],
     *             ],
     *             'required' => ['location'],
     *         ]
     */
    public function getParameters(): array;
}
