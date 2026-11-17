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
 * Actual cost of a completed API request, calculated from real token usage.
 *
 * Attached to ChatResponse when a PricingConfig is configured on the provider.
 *
 * ```php
 * $response = $provider->chat($messages);
 * $cost = $response->getCost();
 *
 * if ($cost !== null) {
 *     echo "Input cost:  $" . number_format($cost->getInputCost(), 6);
 *     echo "Output cost: $" . number_format($cost->getOutputCost(), 6);
 *     echo "Total:       $" . number_format($cost->getTotal(), 6);
 * }
 * ```
 *
 * @author Ibrahim
 */
class CostResult {
    /**
     * Cost of input tokens served from the provider's cache.
     * Included in the input cost.
     *
     * @var float
     */
    private float $cachedInputCost;
    /**
     * Cost of input tokens written to the provider's cache.
     * Included in the input cost.
     *
     * @var float
     */
    private float $cacheWriteCost;

    /**
     * Currency code.
     *
     * @var string
     */
    private string $currency;

    /**
     * Total cost of input (prompt) tokens, including cached and cache-write tokens.
     *
     * @var float
     */
    private float $inputCost;

    /**
     * Model name this cost was calculated for.
     *
     * @var string
     */
    private string $model;

    /**
     * Cost of output (completion) tokens.
     *
     * @var float
     */
    private float $outputCost;

    /**
     * Creates a new CostResult instance.
     *
     * @param float $inputCost Total cost of input tokens, including the cached
     *        and cache-write parts.
     * @param float $outputCost Cost of output tokens.
     * @param string $model The model name.
     * @param string $currency The currency code. Default is 'USD'.
     * @param float $cachedInputCost The part of the input cost that is for
     *        tokens served from cache. Default is 0.0.
     * @param float $cacheWriteCost The part of the input cost that is for
     *        tokens written to cache. Default is 0.0.
     */
    public function __construct(
        float $inputCost,
        float $outputCost,
        string $model,
        string $currency = 'USD',
        float $cachedInputCost = 0.0,
        float $cacheWriteCost = 0.0
    ) {
        $this->inputCost = $inputCost;
        $this->outputCost = $outputCost;
        $this->model = $model;
        $this->currency = $currency;
        $this->cachedInputCost = $cachedInputCost;
        $this->cacheWriteCost = $cacheWriteCost;
    }

    /**
     * Returns the part of the input cost for tokens served from the provider's cache.
     *
     * @return float The cached input cost. It is included in getInputCost().
     */
    public function getCachedInputCost(): float {
        return $this->cachedInputCost;
    }

    /**
     * Returns the part of the input cost for tokens written to the provider's cache.
     *
     * @return float The cache-write cost. It is included in getInputCost().
     */
    public function getCacheWriteCost(): float {
        return $this->cacheWriteCost;
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
     * Returns the total cost of input (prompt) tokens, including the cached
     * and cache-write parts.
     *
     * @return float The input token cost.
     */
    public function getInputCost(): float {
        return $this->inputCost;
    }

    /**
     * Returns the model name this cost was calculated for.
     *
     * @return string The model name.
     */
    public function getModel(): string {
        return $this->model;
    }

    /**
     * Returns the cost of output (completion) tokens.
     *
     * @return float The output token cost.
     */
    public function getOutputCost(): float {
        return $this->outputCost;
    }

    /**
     * Returns the total cost (input + output).
     *
     * @return float The total cost.
     */
    public function getTotal(): float {
        return $this->inputCost + $this->outputCost;
    }
}
