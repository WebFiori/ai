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
 * Pre-request cost estimate based on token counting and max_tokens.
 *
 * Provides a cost range (min to max) before sending the request.
 * The minimum assumes a very short response; the maximum assumes the
 * response uses all of max_tokens.
 *
 * ```php
 * $estimate = $provider->estimateCost($messages, ['model' => 'gpt-4o', 'max_tokens' => 1024]);
 *
 * echo "Prompt tokens: " . $estimate->getPromptTokens();
 * echo "Min cost: $" . number_format($estimate->getMinCost(), 6);
 * echo "Max cost: $" . number_format($estimate->getMaxCost(), 6);
 * ```
 *
 * @author Ibrahim
 */
class CostEstimate {
    /**
     * Currency code.
     *
     * @var string
     */
    private string $currency;

    /**
     * Maximum estimated cost (assumes max_tokens completion).
     *
     * @var float
     */
    private float $maxCost;

    /**
     * Maximum completion tokens assumed for worst-case estimate.
     *
     * @var int
     */
    private int $maxTokens;

    /**
     * Minimum estimated cost (assumes minimal completion).
     *
     * @var float
     */
    private float $minCost;

    /**
     * Model name the estimate is for.
     *
     * @var string
     */
    private string $model;

    /**
     * Estimated input (prompt) tokens.
     *
     * @var int
     */
    private int $promptTokens;

    /**
     * Creates a new CostEstimate instance.
     *
     * @param int $promptTokens Estimated input token count.
     * @param int $maxTokens Maximum output tokens (worst-case scenario).
     * @param float $minCost Minimum estimated cost.
     * @param float $maxCost Maximum estimated cost.
     * @param string $model The model name.
     * @param string $currency The currency code. Default is 'USD'.
     */
    public function __construct(
        int $promptTokens,
        int $maxTokens,
        float $minCost,
        float $maxCost,
        string $model,
        string $currency = 'USD'
    ) {
        $this->promptTokens = $promptTokens;
        $this->maxTokens = $maxTokens;
        $this->minCost = $minCost;
        $this->maxCost = $maxCost;
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
     * Returns the maximum estimated cost (worst case — full max_tokens used).
     *
     * @return float The maximum estimated cost.
     */
    public function getMaxCost(): float {
        return $this->maxCost;
    }

    /**
     * Returns the maximum completion tokens used for the worst-case estimate.
     *
     * @return int The max tokens.
     */
    public function getMaxTokens(): int {
        return $this->maxTokens;
    }

    /**
     * Returns the minimum estimated cost (best case — minimal completion).
     *
     * @return float The minimum estimated cost.
     */
    public function getMinCost(): float {
        return $this->minCost;
    }

    /**
     * Returns the model name this estimate is for.
     *
     * @return string The model name.
     */
    public function getModel(): string {
        return $this->model;
    }

    /**
     * Returns the estimated prompt (input) token count.
     *
     * @return int The estimated prompt token count.
     */
    public function getPromptTokens(): int {
        return $this->promptTokens;
    }
}
