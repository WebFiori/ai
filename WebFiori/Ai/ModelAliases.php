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

/**
 * Registry of logical model aliases mapped to provider-specific model names.
 *
 * Allows using friendly names like 'fast' or 'smart' instead of verbose,
 * version-specific model identifiers (e.g., 'gpt-4o-2024-08-06').
 * Each alias maps to a different model name per provider, so a single
 * alias resolves correctly regardless of which provider handles the request.
 *
 * ```php
 * $aliases = new ModelAliases([
 *     'fast' => [
 *         'openai'    => 'gpt-4o-mini',
 *         'anthropic' => 'claude-haiku-4-5@20251001',
 *         'google'    => 'gemini-2.5-flash',
 *         'bedrock'   => 'us.amazon.nova-lite-v1:0',
 *     ],
 *     'smart' => [
 *         'openai'    => 'gpt-4o',
 *         'anthropic' => 'claude-sonnet-4-20250514',
 *         'google'    => 'gemini-2.5-pro',
 *     ],
 * ]);
 *
 * $client->setModelAliases($aliases);
 * $response = $client->chat($messages, ['model' => 'fast']);
 * // → resolves to the provider-specific name for this client
 * ```
 *
 * @author Ibrahim
 */
class ModelAliases {
    /**
     * The alias map.
     *
     * Structure: ['alias' => ['providerName' => 'modelId', ...], ...]
     *
     * @var array<string, array<string, string>>
     */
    private array $aliases;

    /**
     * Creates a new ModelAliases instance.
     *
     * @param array<string, array<string, string>|string> $aliases The alias map.
     *        Keys are alias names. Values are either:
     *        - An array mapping provider name → model ID
     *        - A string (same model ID for all providers)
     */
    public function __construct(array $aliases = []) {
        $this->aliases = [];

        foreach ($aliases as $alias => $mapping) {
            if (is_string($mapping)) {
                // Same model ID for all providers
                $this->aliases[$alias] = ['*' => $mapping];
            } else {
                $this->aliases[$alias] = $mapping;
            }
        }
    }

    /**
     * Adds or updates an alias.
     *
     * @param string $alias The alias name (e.g., 'fast').
     * @param array<string, string>|string $mapping Provider → model mapping,
     *        or a string to use the same model ID for all providers.
     *
     * @return self For method chaining.
     */
    public function add(string $alias, array|string $mapping): self {
        if (is_string($mapping)) {
            $this->aliases[$alias] = ['*' => $mapping];
        } else {
            $this->aliases[$alias] = $mapping;
        }

        return $this;
    }

    /**
     * Returns all registered aliases.
     *
     * @return array<string, array<string, string>> The alias map.
     */
    public function getAll(): array {
        return $this->aliases;
    }

    /**
     * Returns whether an alias is registered.
     *
     * @param string $alias The alias name to check.
     *
     * @return bool True if the alias exists.
     */
    public function has(string $alias): bool {
        return isset($this->aliases[$alias]);
    }

    /**
     * Removes an alias.
     *
     * @param string $alias The alias name to remove.
     *
     * @return self For method chaining.
     */
    public function remove(string $alias): self {
        unset($this->aliases[$alias]);

        return $this;
    }

    /**
     * Resolves an alias for a specific provider.
     *
     * Resolution order:
     * 1. Exact provider name match (e.g., 'openai')
     * 2. Wildcard match ('*') — same model for all providers
     * 3. Literal fallback — returns the alias string unchanged
     *
     * @param string $alias The alias or model name to resolve.
     * @param string $providerName The provider's name (from getName()).
     *
     * @return string The resolved model ID, or the alias unchanged if not found.
     */
    public function resolve(string $alias, string $providerName): string {
        if (!isset($this->aliases[$alias])) {
            // Not an alias — return as-is (literal model name)
            return $alias;
        }

        $mapping = $this->aliases[$alias];

        // Exact provider match
        if (isset($mapping[$providerName])) {
            return $mapping[$providerName];
        }

        // Wildcard — same model for all providers
        if (isset($mapping['*'])) {
            return $mapping['*'];
        }

        // No mapping for this provider — return alias as-is
        return $alias;
    }
}
