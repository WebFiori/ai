<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Temperature;

use InvalidArgumentException;

/**
 * A temperature strategy that always returns a fixed value.
 *
 * Useful when the application requires a consistent temperature regardless
 * of the conversation content or request options.
 *
 * @author Ibrahim
 */
class FixedTemperatureStrategy implements TemperatureStrategyInterface {
    /**
     * The fixed temperature value.
     *
     * @var float
     */
    private float $temperature;

    /**
     * Creates a new FixedTemperatureStrategy instance.
     *
     * @param float $temperature The fixed temperature value (0.0 to 2.0).
     *
     * @throws InvalidArgumentException If temperature is outside the valid range.
     */
    public function __construct(float $temperature) {
        if ($temperature < 0.0 || $temperature > 2.0) {
            throw new InvalidArgumentException(
                'Temperature must be between 0.0 and 2.0, got '.$temperature
            );
        }
        $this->temperature = $temperature;
    }

    /**
     * Returns the fixed temperature value.
     *
     * @return float The temperature value.
     */
    public function getTemperature(): float {
        return $this->temperature;
    }

    /**
     * Returns the fixed temperature regardless of context.
     *
     * @param ChatContext $context The chat context (ignored).
     *
     * @return float The fixed temperature value.
     */
    public function temperature(ChatContext $context): float {
        return $this->temperature;
    }
}
