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
 * Configurable pricing table for AI provider models.
 *
 * Prices are expressed in USD per 1,000,000 tokens (per-million rate).
 * This is the standard unit used by all major providers.
 *
 * Prices change frequently — configure your own table rather than relying
 * on hardcoded values. Check provider documentation for current rates.
 *
 * ```php
 * $pricing = new PricingConfig([
 *     'gpt-4o'      => ['input' => 2.50, 'output' => 10.00],
 *     'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
 *     'gemini-2.5-flash' => ['input' => 0.075, 'output' => 0.30],
 * ]);
 *
 * $provider->setPricing($pricing);
 * ```
 *
 * @author Ibrahim
 */
class PricingConfig {
    /**
     * Currency code for all prices.
     *
     * @var string
     */
    private string $currency;

    /**
     * Pricing table: model → ['input' => float, 'output' => float]
     * Prices are in USD per 1,000,000 tokens.
     *
     * @var array<string, array{input: float, output: float}>
     */
    private array $models;

    /**
     * Creates a new PricingConfig instance.
     *
     * @param array<string, array{input: float, output: float}> $models
     *        Map of model name to pricing. Each entry must have 'input' and
     *        'output' keys with prices per 1,000,000 tokens in the given currency.
     * @param string $currency The currency for all prices. Default is 'USD'.
     */
    public function __construct(array $models = [], string $currency = 'USD') {
        $this->models = $models;
        $this->currency = $currency;
    }

    /**
     * Returns the currency code.
     *
     * @return string The currency code (e.g., 'USD').
     */
    public function getCurrency(): string {
        return $this->currency;
    }

    /**
     * Returns the input (prompt) price per 1,000,000 tokens for a model.
     *
     * @param string $model The model name.
     *
     * @return float|null The price per million tokens, or null if not configured.
     */
    public function getInputPrice(string $model): ?float {
        return $this->models[$model]['input'] ?? null;
    }

    /**
     * Returns all configured model pricing entries.
     *
     * @return array<string, array{input: float, output: float}> The pricing table.
     */
    public function getModels(): array {
        return $this->models;
    }

    /**
     * Returns the output (completion) price per 1,000,000 tokens for a model.
     *
     * @param string $model The model name.
     *
     * @return float|null The price per million tokens, or null if not configured.
     */
    public function getOutputPrice(string $model): ?float {
        return $this->models[$model]['output'] ?? null;
    }

    /**
     * Returns whether pricing is configured for a given model.
     *
     * @param string $model The model name.
     *
     * @return bool True if input and output prices are defined.
     */
    public function hasModel(string $model): bool {
        return isset($this->models[$model]['input']) && isset($this->models[$model]['output']);
    }

    /**
     * Adds or updates pricing for a model.
     *
     * @param string $model The model name.
     * @param float $inputPrice Input price per 1,000,000 tokens.
     * @param float $outputPrice Output price per 1,000,000 tokens.
     *
     * @return self For method chaining.
     */
    public function setModel(string $model, float $inputPrice, float $outputPrice): self {
        $this->models[$model] = ['input' => $inputPrice, 'output' => $outputPrice];

        return $this;
    }
}
