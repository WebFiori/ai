<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Routing\Strategy;

use WebFiori\Ai\Routing\RoutingStrategyInterface;

/**
 * Always routes to a fixed tier, regardless of message content.
 *
 * Useful for testing, development, or when you want to lock routing
 * to a specific tier without using forceRoute().
 *
 * ```php
 * $router->setStrategy(new AlwaysStrategy('fast'));
 * ```
 *
 * @author Ibrahim
 */
class AlwaysStrategy implements RoutingStrategyInterface {
    /**
     * @var string
     */
    private string $tier;

    /**
     * Creates a new AlwaysStrategy instance.
     *
     * @param string $tier The tier to always route to.
     */
    public function __construct(string $tier) {
        $this->tier = $tier;
    }

    /**
     * Returns the fixed tier name.
     *
     * @return string The tier name.
     */
    public function getTier(): string {
        return $this->tier;
    }

    /**
     * {@inheritdoc}
     */
    public function route(array $messages, array $options): ?string {
        return $this->tier;
    }
}
