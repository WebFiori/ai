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
 * A lazy-loading tool that defers instantiation until execution.
 *
 * Use this when tool construction is expensive (database connections,
 * API client initialization, file loading, etc.) and you want to avoid
 * the overhead when the tool might not be called.
 *
 * Only the metadata (name, description, parameters) is available upfront.
 * The actual tool implementation is created via a factory closure only
 * when execute() is called.
 *
 * ```php
 * $tool = new LazyTool(
 *     'search_database',
 *     'Searches the product database',
 *     ['type' => 'object', 'properties' => [...]],
 *     fn() => new DatabaseSearchTool($heavyConnection)
 * );
 *
 * // If AI never calls this tool, DatabaseSearchTool is never created
 * ```
 *
 * @author Ibrahim
 */
class LazyTool implements ToolInterface {
    /**
     * Tool description.
     *
     * @var string
     */
    private string $description;

    /**
     * Factory closure that creates the actual tool.
     *
     * @var \Closure
     */
    private \Closure $factory;

    /**
     * Optional callable handler for simple cases.
     *
     * @var callable|null
     */
    private $handler = null;

    /**
     * Cached tool instance after first execution.
     *
     * @var ToolInterface|null
     */
    private ?ToolInterface $instance = null;
    /**
     * Tool name.
     *
     * @var string
     */
    private string $name;

    /**
     * Tool parameters schema.
     *
     * @var array<string, mixed>
     */
    private array $parameters;

    /**
     * Creates a new LazyTool instance.
     *
     * @param string $name The unique name of the tool.
     * @param string $description A description of what the tool does.
     * @param array<string, mixed> $parameters The JSON Schema for parameters.
     * @param \Closure $factory A closure that returns a ToolInterface instance
     *        or a callable handler. The closure is only invoked when execute()
     *        is called for the first time.
     */
    public function __construct(
        string $name,
        string $description,
        array $parameters,
        \Closure $factory
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->parameters = $parameters;
        $this->factory = $factory;
    }

    /**
     * Executes the tool with the given arguments.
     *
     * On first call, invokes the factory to create the tool instance.
     * Subsequent calls reuse the cached instance.
     *
     * @param array<string, mixed> $arguments The arguments provided by the AI model.
     *
     * @return string The result of the tool execution.
     */
    public function execute(array $arguments): string {
        $this->ensureInitialized();

        if ($this->instance !== null) {
            return $this->instance->execute($arguments);
        }

        if ($this->handler !== null) {
            return ($this->handler)($arguments);
        }

        return '';
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

    /**
     * Forces initialization of the tool.
     *
     * Normally initialization happens on first execute(), but this
     * can be called to pre-initialize if needed.
     */
    public function initialize(): void {
        $this->ensureInitialized();
    }

    /**
     * Checks if the tool has been initialized.
     *
     * @return bool True if the factory has been invoked.
     */
    public function isInitialized(): bool {
        return $this->instance !== null || $this->handler !== null;
    }

    /**
     * Invokes the factory if not already done.
     */
    private function ensureInitialized(): void {
        if ($this->instance !== null || $this->handler !== null) {
            return;
        }

        $result = ($this->factory)();

        if ($result instanceof ToolInterface) {
            $this->instance = $result;
        } elseif (is_callable($result)) {
            $this->handler = $result;
        } else {
            throw new \RuntimeException(
                "LazyTool factory must return a ToolInterface or callable, got ".gettype($result)
            );
        }
    }
}
