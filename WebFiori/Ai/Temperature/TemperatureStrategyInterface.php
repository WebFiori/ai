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

/**
 * Contract for temperature selection strategies.
 *
 * Implementations decide what temperature value to use for a given chat
 * context. Strategies can return a fixed value, analyze the conversation
 * content, or apply any custom logic to determine the optimal temperature.
 *
 * @author Ibrahim
 */
interface TemperatureStrategyInterface {
    /**
     * Determines the temperature for the given chat context.
     *
     * @param ChatContext $context The chat context containing messages and options.
     *
     * @return float The temperature value (0.0 to 2.0).
     */
    public function temperature(ChatContext $context): float;
}
