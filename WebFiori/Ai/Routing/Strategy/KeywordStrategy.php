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
 * Routes based on keyword patterns found in user messages.
 *
 * Each pattern maps to a tier. Patterns are checked in the order defined;
 * the first match wins.
 *
 * ```php
 * $router->setStrategy(new KeywordStrategy([
 *     'coding'   => ['code', 'function', 'debug', 'class', 'implement'],
 *     'creative' => ['story', 'poem', 'write', 'creative'],
 *     'analysis' => ['analyze', 'compare', 'summarize', 'explain'],
 * ], default: 'fast'));
 * ```
 *
 * @author Ibrahim
 */
class KeywordStrategy implements RoutingStrategyInterface {
    /**
     * @var string
     */
    private string $default;

    /**
     * Map of tier → keywords.
     *
     * @var array<string, string[]>
     */
    private array $patterns;

    /**
     * Creates a new KeywordStrategy instance.
     *
     * @param array<string, string[]> $patterns Map of tier name → list of keywords.
     *        Matching is case-insensitive substring search on user message content.
     * @param string $default Tier to use when no keyword matches.
     */
    public function __construct(array $patterns, string $default) {
        $this->patterns = $patterns;
        $this->default = $default;
    }

    /**
     * Returns the default tier.
     *
     * @return string
     */
    public function getDefault(): string {
        return $this->default;
    }

    /**
     * Returns all registered patterns.
     *
     * @return array<string, string[]>
     */
    public function getPatterns(): array {
        return $this->patterns;
    }

    /**
     * {@inheritdoc}
     */
    public function route(array $messages, array $options): ?string {
        $text = '';

        foreach ($messages as $message) {
            if ($message->getRole() === 'user') {
                $text .= ' '.$message->getContent();
            }
        }

        $text = strtolower($text);

        foreach ($this->patterns as $tier => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, strtolower($keyword))) {
                    return $tier;
                }
            }
        }

        return $this->default;
    }
}
