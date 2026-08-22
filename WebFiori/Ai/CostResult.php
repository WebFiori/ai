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
     * Currency code.
     *
     * @var string
     */
    private string $currency;

    /**
     * Cost of input (prompt) tokens.
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
     * @param float $inputCost Cost of input tokens.
     * @param float $outputCost Cost of output tokens.
     * @param string $model The model name.
     * @param string $currency The currency code. Default is 'USD'.
     */
    public function __construct(
        float $inputCost,
        float $outputCost,
        string $model,
        string $currency = 'USD'
    ) {
        $this->inputCost = $inputCost;
        $this->outputCost = $outputCost;
        $this->model = $model;
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
     * Returns the cost of input (prompt) tokens.
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
