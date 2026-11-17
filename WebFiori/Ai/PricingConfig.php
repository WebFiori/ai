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
 * Cached input tokens and cache-write tokens can be priced separately with the
 * optional `cached_input` and `cache_write` keys. When one is not set, those
 * tokens are charged at the normal input price:
 *
 * ```php
 * $pricing = new PricingConfig([
 *     'claude-sonnet-4' => [
 *         'input' => 3.00, 'output' => 15.00,
 *         'cached_input' => 0.30, 'cache_write' => 3.75,
 *     ],
 * ]);
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
     * Pricing table: model → ['input' => float, 'output' => float], with
     * optional 'cached_input' and 'cache_write' prices.
     * Prices are in USD per 1,000,000 tokens.
     *
     * @var array<string, array{input: float, output: float, cached_input?: float, cache_write?: float}>
     */
    private array $models;

    /**
     * Creates a new PricingConfig instance.
     *
     * @param array<string, array{input: float, output: float, cached_input?: float, cache_write?: float}> $models
     *        Map of model name to pricing. Each entry must have 'input' and
     *        'output' keys with prices per 1,000,000 tokens in the given currency.
     *        Entries may also have 'cached_input' and 'cache_write' keys.
     * @param string $currency The currency for all prices. Default is 'USD'.
     */
    public function __construct(array $models = [], string $currency = 'USD') {
        $this->models = $models;
        $this->currency = $currency;
    }

    /**
     * Returns the cache-write price per 1,000,000 tokens for a model.
     *
     * @param string $model The model name.
     *
     * @return float|null The price per million tokens, or null if not configured.
     *         When null, cache-write tokens are charged at the input price.
     */
    public function getCacheWritePrice(string $model): ?float {
        return $this->models[$model]['cache_write'] ?? null;
    }

    /**
     * Returns the cached input price per 1,000,000 tokens for a model.
     *
     * @param string $model The model name.
     *
     * @return float|null The price per million tokens, or null if not configured.
     *         When null, cached tokens are charged at the input price.
     */
    public function getCachedInputPrice(string $model): ?float {
        return $this->models[$model]['cached_input'] ?? null;
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
     * @return array<string, array{input: float, output: float, cached_input?: float, cache_write?: float}> The pricing table.
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
     * @param float|null $cachedInputPrice Price per 1,000,000 tokens for input
     *        served from the provider's cache. Null means the input price.
     * @param float|null $cacheWritePrice Price per 1,000,000 tokens for input
     *        written to the provider's cache. Null means the input price.
     *
     * @return self For method chaining.
     */
    public function setModel(
        string $model,
        float $inputPrice,
        float $outputPrice,
        ?float $cachedInputPrice = null,
        ?float $cacheWritePrice = null
    ): self {
        $entry = ['input' => $inputPrice, 'output' => $outputPrice];

        if ($cachedInputPrice !== null) {
            $entry['cached_input'] = $cachedInputPrice;
        }

        if ($cacheWritePrice !== null) {
            $entry['cache_write'] = $cacheWritePrice;
        }

        $this->models[$model] = $entry;

        return $this;
    }
}
