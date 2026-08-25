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

use WebFiori\Ai\Role;
use WebFiori\Ai\Routing\RoutingStrategyInterface;

/**
 * Routes based on the total character length of all user messages.
 *
 * Short messages → fast tier (cheap, low latency)
 * Long messages  → complex tier (more capable)
 *
 * ```php
 * $router->setStrategy(new TokenLengthStrategy(
 *     fastTier: 'fast',
 *     complexTier: 'smart',
 *     threshold: 500,   // characters above this → complex tier
 * ));
 * ```
 *
 * @author Ibrahim
 */
class TokenLengthStrategy implements RoutingStrategyInterface {
    /**
     * @var string
     */
    private string $complexTier;

    /**
     * @var string
     */
    private string $fastTier;

    /**
     * @var int
     */
    private int $threshold;

    /**
     * Creates a new TokenLengthStrategy instance.
     *
     * @param string $fastTier Tier for short messages (below threshold).
     * @param string $complexTier Tier for long messages (at or above threshold).
     * @param int $threshold Character count threshold. Default is 500.
     */
    public function __construct(
        string $fastTier,
        string $complexTier,
        int $threshold = 500
    ) {
        $this->fastTier = $fastTier;
        $this->complexTier = $complexTier;
        $this->threshold = max(1, $threshold);
    }

    /**
     * Returns the complex tier name.
     *
     * @return string
     */
    public function getComplexTier(): string {
        return $this->complexTier;
    }

    /**
     * Returns the fast tier name.
     *
     * @return string
     */
    public function getFastTier(): string {
        return $this->fastTier;
    }

    /**
     * Returns the character threshold.
     *
     * @return int
     */
    public function getThreshold(): int {
        return $this->threshold;
    }

    /**
     * {@inheritdoc}
     */
    public function route(array $messages, array $options): ?string {
        $totalLength = 0;

        foreach ($messages as $message) {
            if ($message->getRole() === Role::USER->value) {
                $totalLength += strlen($message->getContent());
            }
        }

        return $totalLength >= $this->threshold ? $this->complexTier : $this->fastTier;
    }
}
