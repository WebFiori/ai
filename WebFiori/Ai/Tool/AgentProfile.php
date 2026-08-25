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

use RuntimeException;

/**
 * A structured agent profile for onboarding an AI agent with identity,
 * skills, instructions, constraints, and tools.
 *
 * This class provides multiple factory methods to create profiles from
 * JSON files, URLs, arrays, or plain strings. It can render itself into
 * a system prompt suitable for AI model consumption.
 *
 * @author Ibrahim
 */
class AgentProfile {
    /**
     * Limitations or boundaries the agent must respect.
     *
     * @var string[]
     */
    private array $constraints;

    /**
     * Background knowledge or context for the agent.
     *
     * @var string|null
     */
    private ?string $context;

    /**
     * Few-shot examples, each with 'input' and 'output' keys.
     *
     * @var array<int, array{input: string, output: string}>
     */
    private array $examples;

    /**
     * The core identity description of the agent.
     *
     * @var string
     */
    private string $identity;

    /**
     * Behavioral instructions for the agent.
     *
     * @var string[]
     */
    private array $instructions;

    /**
     * Version info and other metadata not sent to the model.
     *
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * Expected output format description.
     *
     * @var string|null
     */
    private ?string $outputFormat;

    /**
     * List of skill descriptions.
     *
     * @var string[]
     */
    private array $skills;

    /**
     * Unresolved tool name references from JSON.
     *
     * @var string[]
     */
    private array $toolRefs;

    /**
     * Resolved tool instances available to the agent.
     *
     * @var ToolInterface[]
     */
    private array $tools;

    /**
     * Creates a new AgentProfile instance.
     *
     * @param string $identity The core identity description of the agent.
     * @param string[] $skills List of skill descriptions.
     * @param string[] $instructions Behavioral instructions.
     * @param string[] $constraints Limitations or boundaries.
     * @param string|null $outputFormat Expected output format description.
     * @param string|null $context Background knowledge or context.
     * @param array<int, array{input: string, output: string}> $examples Few-shot examples.
     * @param array<string, mixed> $metadata Version info, not sent to model.
     * @param ToolInterface[] $tools Resolved tool instances.
     */
    public function __construct(
        string $identity,
        array $skills = [],
        array $instructions = [],
        array $constraints = [],
        ?string $outputFormat = null,
        ?string $context = null,
        array $examples = [],
        array $metadata = [],
        array $tools = [],
    ) {
        $this->identity = $identity;
        $this->skills = $skills;
        $this->instructions = $instructions;
        $this->constraints = $constraints;
        $this->outputFormat = $outputFormat;
        $this->context = $context;
        $this->examples = $examples;
        $this->metadata = $metadata;
        $this->tools = $tools;
        $this->toolRefs = [];
    }

    /**
     * Creates a profile from an associative array.
     *
     * Expected keys (all optional except 'identity'): identity, skills,
     * instructions, constraints, output_format, context, examples,
     * metadata, tools (as string[] tool name references).
     *
     * @param array<string, mixed> $data The profile data with snake_case keys.
     *
     * @return self The constructed profile.
     */
    public static function fromArray(array $data): self {
        $profile = new self(
            identity: $data['identity'] ?? '',
            skills: $data['skills'] ?? [],
            instructions: $data['instructions'] ?? [],
            constraints: $data['constraints'] ?? [],
            outputFormat: $data['output_format'] ?? null,
            context: $data['context'] ?? null,
            examples: $data['examples'] ?? [],
            metadata: $data['metadata'] ?? [],
        );

        if (isset($data['tools']) && is_array($data['tools'])) {
            $profile->toolRefs = $data['tools'];
        }

        return $profile;
    }

    /**
     * Creates a profile from a JSON file.
     *
     * @param string $path Path to the JSON file.
     *
     * @return self The constructed profile.
     *
     * @throws RuntimeException If the file does not exist or contains invalid JSON.
     */
    public static function fromFile(string $path): self {
        if (!file_exists($path)) {
            throw new RuntimeException('Profile file not found: ' . $path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Failed to read profile file: ' . $path);
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON in profile file: ' . $path);
        }

        return self::fromArray($data);
    }

    /**
     * Creates a minimal profile from a plain system prompt string.
     *
     * @param string $systemPrompt The system prompt to use as the identity.
     *
     * @return self The constructed profile with only identity set.
     */
    public static function fromString(string $systemPrompt): self {
        return new self(identity: $systemPrompt);
    }

    /**
     * Creates a profile by fetching a JSON document from a URL.
     *
     * @param string $url The URL to fetch the JSON profile from.
     * @param array<string, string> $headers Additional HTTP headers to send.
     *
     * @return self The constructed profile.
     *
     * @throws RuntimeException If the request fails or returns invalid JSON.
     */
    public static function fromUrl(string $url, array $headers = []): self {
        $httpHeaders = "Accept: application/json\r\n";

        foreach ($headers as $name => $value) {
            $httpHeaders .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $httpHeaders,
                'timeout' => 10,
            ],
        ]);

        $contents = @file_get_contents($url, false, $context);

        if ($contents === false) {
            throw new RuntimeException('Failed to fetch profile from URL: ' . $url);
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON from URL: ' . $url);
        }

        return self::fromArray($data);
    }

    /**
     * Returns the constraints list.
     *
     * @return string[] The constraints.
     */
    public function getConstraints(): array {
        return $this->constraints;
    }

    /**
     * Returns the background context.
     *
     * @return string|null The context string or null.
     */
    public function getContext(): ?string {
        return $this->context;
    }

    /**
     * Returns the few-shot examples.
     *
     * @return array<int, array{input: string, output: string}> The examples.
     */
    public function getExamples(): array {
        return $this->examples;
    }

    /**
     * Returns the identity description.
     *
     * @return string The identity.
     */
    public function getIdentity(): string {
        return $this->identity;
    }

    /**
     * Returns the behavioral instructions.
     *
     * @return string[] The instructions.
     */
    public function getInstructions(): array {
        return $this->instructions;
    }

    /**
     * Returns the metadata array.
     *
     * @return array<string, mixed> The metadata.
     */
    public function getMetadata(): array {
        return $this->metadata;
    }

    /**
     * Returns the expected output format description.
     *
     * @return string|null The output format or null.
     */
    public function getOutputFormat(): ?string {
        return $this->outputFormat;
    }

    /**
     * Returns the skills list.
     *
     * @return string[] The skills.
     */
    public function getSkills(): array {
        return $this->skills;
    }

    /**
     * Returns the resolved tool instances.
     *
     * @return ToolInterface[] The tools.
     */
    public function getTools(): array {
        return $this->tools;
    }

    /**
     * Returns unresolved tool name references.
     *
     * These are tool names loaded from JSON that have not yet been
     * resolved to ToolInterface instances via {@see resolveTools()}.
     *
     * @return string[] The unresolved tool name references.
     */
    public function getUnresolvedToolRefs(): array {
        return $this->toolRefs;
    }

    /**
     * Renders the profile into a system prompt string.
     *
     * Only non-empty sections are included in the output.
     *
     * @return string The rendered system prompt.
     */
    public function render(): string {
        $parts = [];
        $parts[] = $this->identity;

        if (!empty($this->skills)) {
            $parts[] = "## Skills\n" . implode("\n", array_map(fn (string $s): string => '- ' . $s, $this->skills));
        }

        if (!empty($this->instructions)) {
            $parts[] = "## Instructions\n" . implode("\n", array_map(fn (string $s): string => '- ' . $s, $this->instructions));
        }

        if (!empty($this->constraints)) {
            $parts[] = "## Constraints\n" . implode("\n", array_map(fn (string $s): string => '- ' . $s, $this->constraints));
        }

        if ($this->outputFormat !== null && $this->outputFormat !== '') {
            $parts[] = "## Output Format\n" . $this->outputFormat;
        }

        if ($this->context !== null && $this->context !== '') {
            $parts[] = "## Context\n" . $this->context;
        }

        if (!empty($this->examples)) {
            $exampleLines = [];

            foreach ($this->examples as $example) {
                $exampleLines[] = 'User: ' . $example['input'];
                $exampleLines[] = 'Assistant: ' . $example['output'];
            }

            $parts[] = "## Examples\n" . implode("\n", $exampleLines);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Resolves tool name references to actual ToolInterface instances.
     *
     * @param array<string, ToolInterface> $registry A map of tool name to instance.
     *
     * @throws RuntimeException If a referenced tool is not found in the registry.
     */
    public function resolveTools(array $registry): void {
        $resolved = [];

        foreach ($this->toolRefs as $index => $ref) {
            if (!isset($registry[$ref])) {
                throw new RuntimeException('Tool not found in registry: ' . $ref);
            }

            $resolved[] = $registry[$ref];
            unset($this->toolRefs[$index]);
        }

        $this->toolRefs = array_values($this->toolRefs);
        $this->tools = array_merge($this->tools, $resolved);
    }

    /**
     * Directly sets the tool instances available to the agent.
     *
     * @param ToolInterface[] $tools The tool instances.
     */
    public function setTools(array $tools): void {
        $this->tools = $tools;
    }

    /**
     * Exports the profile to an associative array with snake_case keys.
     *
     * Tools are exported as their name strings. Metadata is included.
     *
     * @return array<string, mixed> The profile data.
     */
    public function toArray(): array {
        $toolNames = array_map(fn (ToolInterface $t): string => $t->getName(), $this->tools);
        $allToolNames = array_merge($toolNames, $this->toolRefs);

        return [
            'identity' => $this->identity,
            'skills' => $this->skills,
            'instructions' => $this->instructions,
            'constraints' => $this->constraints,
            'output_format' => $this->outputFormat,
            'context' => $this->context,
            'examples' => $this->examples,
            'metadata' => $this->metadata,
            'tools' => $allToolNames,
        ];
    }

    /**
     * Exports the profile as a JSON string.
     *
     * @param int $flags JSON encoding flags.
     *
     * @return string The JSON representation of the profile.
     */
    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string {
        return json_encode($this->toArray(), $flags);
    }
}
