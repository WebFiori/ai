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
     * @var string|array<int, string>|null
     */
    private string|array|null $context;

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
     * @param string|array<int, string>|null $context Background knowledge or context.
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
        string|array|null $context = null,
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
     * If the data contains an 'extends' key and a $basePath is provided,
     * the referenced base profile will be resolved and merged automatically.
     *
     * @param array<string, mixed> $data The profile data with snake_case keys.
     * @param string|null $basePath Directory path for resolving 'extends'. If null, 'extends' is ignored.
     *
     * @return self The constructed profile.
     */
    public static function fromArray(array $data, ?string $basePath = null): self {
        if ($basePath !== null && isset($data['extends']) && $data['extends'] !== '') {
            $baseFile = rtrim($basePath, '/').'/'.$data['extends'].'.json';
            $data = self::resolveInheritance($baseFile, [], $data);
        }

        unset($data['extends'], $data['inheritance_strategy']);

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
     * If the JSON contains an 'extends' key, the referenced base profile is
     * resolved recursively and merged using field-specific strategies.
     *
     * @param string $path Path to the JSON file.
     *
     * @return self The constructed profile.
     *
     * @throws RuntimeException If the file does not exist, contains invalid JSON,
     *                          or has circular inheritance.
     */
    public static function fromFile(string $path): self {
        if (!file_exists($path)) {
            throw new RuntimeException('Profile file not found: '.$path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Failed to read profile file: '.$path);
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON in profile file: '.$path);
        }

        if (isset($data['extends']) && $data['extends'] !== '') {
            $realPath = realpath($path);

            if ($realPath === false) {
                throw new RuntimeException('Profile file not found: '.$path);
            }

            $baseFile = dirname($realPath).'/'.$data['extends'].'.json';
            $strategies = $data['inheritance_strategy'] ?? [];
            unset($data['extends'], $data['inheritance_strategy']);
            $baseData = self::resolveInheritance($baseFile, [$realPath]);
            $data = self::mergeProfileData($baseData, $data, $strategies);
        }

        unset($data['extends'], $data['inheritance_strategy']);

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
            $httpHeaders .= $name.': '.$value."\r\n";
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
            throw new RuntimeException('Failed to fetch profile from URL: '.$url);
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON from URL: '.$url);
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
     * @return string|array<int, string>|null The context string, array of strings, or null.
     */
    public function getContext(): string|array|null {
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
     * Merges a base profile with a child profile using field-specific strategies.
     *
     * Default strategies: arrays concat, scalars replace, metadata merges.
     * Override specific fields via the $strategies parameter.
     *
     * @param self $base The base (parent) profile.
     * @param self $child The child profile to merge on top.
     * @param array<string, string> $strategies Optional per-field strategy overrides.
     *                                          Keys are field names, values are 'concat', 'replace', or 'merge'.
     *
     * @return self The merged profile.
     *
     * @throws RuntimeException If an invalid strategy is specified.
     */
    public static function merge(self $base, self $child, array $strategies = []): self {
        $baseData = $base->toArray();
        $childData = $child->toArray();

        $merged = self::mergeProfileData($baseData, $childData, $strategies);

        return self::fromArray($merged);
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
            $parts[] = "## Skills\n".implode("\n", array_map(fn (string $s): string => '- '.$s, $this->skills));
        }

        if (!empty($this->instructions)) {
            $parts[] = "## Instructions\n".implode("\n", array_map(fn (string $s): string => '- '.$s, $this->instructions));
        }

        if (!empty($this->constraints)) {
            $parts[] = "## Constraints\n".implode("\n", array_map(fn (string $s): string => '- '.$s, $this->constraints));
        }

        if ($this->outputFormat !== null && $this->outputFormat !== '') {
            $parts[] = "## Output Format\n".$this->outputFormat;
        }

        if ($this->context !== null && $this->context !== '' && $this->context !== []) {
            if (is_array($this->context)) {
                $parts[] = "## Context\n".implode("\n", array_map(fn (string $s): string => '- '.$s, $this->context));
            } else {
                $parts[] = "## Context\n".$this->context;
            }
        }

        if (!empty($this->examples)) {
            $exampleLines = [];

            foreach ($this->examples as $example) {
                $exampleLines[] = 'User: '.$example['input'];
                $exampleLines[] = 'Assistant: '.$example['output'];
            }

            $parts[] = "## Examples\n".implode("\n", $exampleLines);
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
                throw new RuntimeException('Tool not found in registry: '.$ref);
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

    /**
     * Merges two profile data arrays using field-specific strategies.
     *
     * @param array<string, mixed> $base The base profile data.
     * @param array<string, mixed> $child The child profile data.
     * @param array<string, string> $strategies Per-field strategy overrides.
     *
     * @return array<string, mixed> The merged data.
     *
     * @throws RuntimeException If an invalid strategy value is provided.
     */
    private static function mergeProfileData(array $base, array $child, array $strategies = []): array {
        $defaults = [
            'identity' => 'replace',
            'output_format' => 'replace',
            'context' => 'concat',
            'skills' => 'concat',
            'instructions' => 'concat',
            'constraints' => 'concat',
            'examples' => 'concat',
            'tools' => 'concat',
            'metadata' => 'merge',
        ];

        $validStrategies = ['concat', 'replace', 'merge'];
        $validFields = array_keys($defaults);
        $scalarFields = ['identity', 'output_format'];

        foreach ($strategies as $field => $strategy) {
            if (!in_array($field, $validFields, true)) {
                throw new RuntimeException(
                    "Invalid field '{$field}' in inheritance_strategy. Valid fields: ".implode(', ', $validFields)
                );
            }

            if (!in_array($strategy, $validStrategies, true)) {
                throw new RuntimeException(
                    "Invalid inheritance strategy '{$strategy}' for field '{$field}'. Valid strategies: ".implode(', ', $validStrategies)
                );
            }

            if (in_array($field, $scalarFields, true) && $strategy !== 'replace') {
                throw new RuntimeException(
                    "Strategy '{$strategy}' cannot be used on scalar field '{$field}'. Only 'replace' is valid for scalar fields."
                );
            }
        }

        $effective = array_merge($defaults, $strategies);
        $result = [];

        foreach ($defaults as $field => $defaultStrategy) {
            $strategy = $effective[$field];

            switch ($strategy) {
                case 'replace':
                    if ($field === 'identity') {
                        $result[$field] = ($child[$field] ?? '') !== ''
                            ? $child[$field]
                            : ($base[$field] ?? '');
                    } else {
                        $result[$field] = array_key_exists($field, $child) && $child[$field] !== null
                            ? $child[$field]
                            : ($base[$field] ?? null);
                    }

                    break;

                case 'concat':
                    $baseVal = $base[$field] ?? [];
                    $childVal = $child[$field] ?? [];

                    // Normalize strings to single-element arrays for context field
                    if (is_string($baseVal) && $baseVal !== '') {
                        $baseVal = [$baseVal];
                    } elseif (!is_array($baseVal)) {
                        $baseVal = [];
                    }

                    if (is_string($childVal) && $childVal !== '') {
                        $childVal = [$childVal];
                    } elseif (!is_array($childVal)) {
                        $childVal = [];
                    }

                    $result[$field] = array_merge($baseVal, $childVal);

                    break;

                case 'merge':
                    $result[$field] = array_merge(
                        $base[$field] ?? [],
                        $child[$field] ?? []
                    );

                    break;

                default:
                    throw new RuntimeException(
                        "Invalid inheritance strategy '{$strategy}' for field '{$field}'"
                    );
            }
        }

        return $result;
    }

    /**
     * Recursively resolves profile inheritance by loading and merging base profiles.
     *
     * @param string $path Path to the base profile file.
     * @param array<int, string> $visited List of already-visited real paths for circular detection.
     * @param array<string, mixed>|null $childData Optional child data to merge after resolution.
     *
     * @return array<string, mixed> The resolved profile data.
     *
     * @throws RuntimeException If the file is missing, contains invalid JSON, or creates a circular reference.
     */
    private static function resolveInheritance(string $path, array $visited, ?array $childData = null): array {
        $realPath = realpath($path);

        if ($realPath === false) {
            throw new RuntimeException('Profile file not found: '.$path);
        }

        if (in_array($realPath, $visited, true)) {
            $chain = implode(' → ', array_map('basename', $visited));

            throw new RuntimeException(
                'Circular profile inheritance detected: '.$chain.' → '.basename($realPath)
            );
        }

        $visited[] = $realPath;
        $contents = file_get_contents($realPath);
        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON in profile file: '.$path);
        }

        if (isset($data['extends']) && $data['extends'] !== '') {
            $baseFile = dirname($realPath).'/'.$data['extends'].'.json';
            $strategies = $data['inheritance_strategy'] ?? [];
            unset($data['extends'], $data['inheritance_strategy']);
            $baseData = self::resolveInheritance($baseFile, $visited);
            $data = self::mergeProfileData($baseData, $data, $strategies);
        } else {
            unset($data['extends'], $data['inheritance_strategy']);
        }

        if ($childData !== null) {
            $strategies = $childData['inheritance_strategy'] ?? [];
            unset($childData['extends'], $childData['inheritance_strategy']);
            $data = self::mergeProfileData($data, $childData, $strategies);
        }

        return $data;
    }
}
