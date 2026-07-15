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
 * A concrete tool definition with a callable handler.
 *
 * This class provides a convenient way to define tools that AI models
 * can invoke during conversation. Tools are defined with a name,
 * description, parameter schema, and a handler function that performs
 * the actual work.
 *
 * @author Ibrahim
 */
class Tool implements ToolInterface {
    /**
     * A description of what the tool does.
     *
     * @var string
     */
    private string $description;

    /**
     * The callable that executes the tool logic.
     *
     * @var callable
     */
    private $handler;

    /**
     * The unique name of the tool.
     *
     * @var string
     */
    private string $name;

    /**
     * The JSON Schema definition for the tool's parameters.
     *
     * @var array<string, mixed>
     */
    private array $parameters;

    /**
     * Creates a new Tool instance.
     *
     * @param string $name The unique name of the tool (e.g., 'get_weather').
     * @param string $description A description of what the tool does, used by
     *        the AI model to decide when to invoke it.
     * @param array<string, mixed> $parameters The JSON Schema for the tool's
     *        parameters. Should include 'type', 'properties', and optionally
     *        'required'. Example:
     *        [
     *            'type' => 'object',
     *            'properties' => [
     *                'location' => ['type' => 'string', 'description' => 'City name'],
     *            ],
     *            'required' => ['location'],
     *        ]
     * @param callable $handler The function that executes the tool logic.
     *        Signature: function(array $arguments): string
     */
    public function __construct(string $name, string $description, array $parameters, callable $handler) {
        $this->name = $name;
        $this->description = $description;
        $this->parameters = $parameters;
        $this->handler = $handler;
    }

    /**
     * Executes the tool with the given arguments.
     *
     * @param array<string, mixed> $arguments The arguments provided by the AI model.
     *
     * @return string The result of the tool execution.
     */
    public function execute(array $arguments): string {
        return ($this->handler)($arguments);
    }

    /**
     * Returns a description of what the tool does.
     *
     * @return string The tool description.
     */
    public function getDescription(): string {
        return $this->description;
    }

    /**
     * Returns the unique name of the tool.
     *
     * @return string The tool name.
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Returns the JSON Schema definition for the tool's parameters.
     *
     * @return array<string, mixed> The parameter schema.
     */
    public function getParameters(): array {
        return $this->parameters;
    }
}
