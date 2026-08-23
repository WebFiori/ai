<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Routing;

use WebFiori\Ai\Message;

/**
 * A single rule in the ModelRouter rule-based routing system.
 *
 * A rule consists of a condition callable and the tier name to route to
 * when the condition is satisfied. Rules are evaluated in descending
 * priority order; the first matching rule wins.
 *
 * @author Ibrahim
 */
class RoutingRule {
    /**
     * The condition callable.
     *
     * @var callable
     */
    private $condition;

    /**
     * Optional human-readable description for observability.
     *
     * @var string
     */
    private string $description;

    /**
     * Rule priority. Higher values are evaluated first.
     *
     * @var int
     */
    private int $priority;

    /**
     * The tier name to route to when the condition matches.
     *
     * @var string
     */
    private string $tier;

    /**
     * Creates a new RoutingRule instance.
     *
     * @param callable $condition Callable receiving (Message[], array $options): bool.
     *        Returns true if this rule should handle the request.
     * @param string $tier The tier name to route to (must be registered in ModelRouter).
     * @param int $priority Rule priority. Higher values evaluated first. Default is 0.
     * @param string $description Optional description for logging/observability.
     */
    public function __construct(
        callable $condition,
        string $tier,
        int $priority = 0,
        string $description = ''
    ) {
        $this->condition = $condition;
        $this->tier = $tier;
        $this->priority = $priority;
        $this->description = $description;
    }

    /**
     * Returns the optional description.
     *
     * @return string The description.
     */
    public function getDescription(): string {
        return $this->description;
    }

    /**
     * Returns the rule priority.
     *
     * @return int The priority. Higher values are evaluated first.
     */
    public function getPriority(): int {
        return $this->priority;
    }

    /**
     * Returns the tier name this rule routes to.
     *
     * @return string The tier name.
     */
    public function getTier(): string {
        return $this->tier;
    }

    /**
     * Evaluates the condition against the given messages and options.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options The chat options.
     *
     * @return bool True if this rule matches and should handle the request.
     */
    public function matches(array $messages, array $options): bool {
        return (bool) ($this->condition)($messages, $options);
    }
}
