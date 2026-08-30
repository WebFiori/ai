<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Context;

use WebFiori\Ai\Provider\AbstractClient;

/**
 * A configurable table of model context window sizes (in tokens).
 *
 * Ships with sensible defaults for common models across OpenAI, Google,
 * Anthropic, and AWS Bedrock. Values are the total context window (input +
 * output) advertised by each provider.
 *
 * Used by {@see AbstractClient::getContextUsage()} to
 * infer the context window ceiling from the model name when no explicit value
 * or context window strategy provides one.
 *
 * Context window sizes change as providers ship new models — override or
 * extend the table rather than relying solely on the built-in defaults.
 * Unknown models return null.
 *
 * ```php
 * $config = new ContextWindowConfig();
 * $config->getContextWindow('gpt-4o');            // 128000
 * $config->getContextWindow('gemini-2.5-flash');  // 1048576
 * $config->getContextWindow('unknown-model');     // null
 *
 * $config->setContextWindow('my-fine-tune', 32000);
 * $client->setContextWindowConfig($config);
 * ```
 *
 * @author Ibrahim
 */
class ContextWindowConfig {
    /**
     * Default context window sizes in tokens, keyed by model name.
     *
     * @var array<string, int>
     */
    private const DEFAULTS = [
        // OpenAI
        'gpt-4o' => 128000,
        'gpt-4o-mini' => 128000,
        'gpt-4-turbo' => 128000,
        'gpt-4' => 8192,
        'gpt-3.5-turbo' => 16385,
        'o1' => 200000,
        'o1-mini' => 128000,
        'o3-mini' => 200000,

        // Google Gemini
        'gemini-2.5-pro' => 1048576,
        'gemini-2.5-flash' => 1048576,
        'gemini-2.0-flash' => 1048576,
        'gemini-1.5-pro' => 2097152,
        'gemini-1.5-flash' => 1048576,
        'gemini-3.5-flash' => 1048576,

        // Anthropic Claude
        'claude-sonnet-4-20250514' => 200000,
        'claude-opus-4-20250514' => 200000,
        'claude-haiku-4-5-20251001' => 200000,
        'claude-3-5-sonnet-20241022' => 200000,
        'claude-3-5-haiku-20241022' => 200000,
        'claude-3-opus-20240229' => 200000,

        // AWS Bedrock (Amazon Nova)
        'amazon.nova-pro-v1:0' => 300000,
        'amazon.nova-lite-v1:0' => 300000,
        'amazon.nova-micro-v1:0' => 128000,
        'us.amazon.nova-pro-v1:0' => 300000,
        'us.amazon.nova-lite-v1:0' => 300000,
        'us.amazon.nova-micro-v1:0' => 128000,
    ];

    /**
     * Context window table: model name → window size in tokens.
     *
     * @var array<string, int>
     */
    private array $models;

    /**
     * Creates a new ContextWindowConfig instance.
     *
     * @param array<string, int> $models Custom model→window entries. Merged on
     *        top of the built-in defaults (custom values override defaults).
     * @param bool $useDefaults Whether to seed the built-in default table.
     *        Set false to start from an empty table containing only $models.
     */
    public function __construct(array $models = [], bool $useDefaults = true) {
        $this->models = $useDefaults
            ? array_merge(self::DEFAULTS, $models)
            : $models;
    }

    /**
     * Returns the context window size in tokens for a model.
     *
     * @param string $model The model name.
     *
     * @return int|null The window size in tokens, or null if unknown.
     */
    public function getContextWindow(string $model): ?int {
        return $this->models[$model] ?? null;
    }

    /**
     * Returns the built-in default context window table.
     *
     * @return array<string, int> The default model→window map.
     */
    public static function getDefaults(): array {
        return self::DEFAULTS;
    }

    /**
     * Returns all configured model→window entries.
     *
     * @return array<string, int> The context window table.
     */
    public function getModels(): array {
        return $this->models;
    }

    /**
     * Returns whether a context window is configured for a model.
     *
     * @param string $model The model name.
     *
     * @return bool True if the model has a configured window size.
     */
    public function hasModel(string $model): bool {
        return isset($this->models[$model]);
    }

    /**
     * Adds or updates the context window size for a model.
     *
     * @param string $model The model name.
     * @param int $contextWindow The window size in tokens.
     *
     * @return self For method chaining.
     */
    public function setContextWindow(string $model, int $contextWindow): self {
        $this->models[$model] = $contextWindow;

        return $this;
    }
}
