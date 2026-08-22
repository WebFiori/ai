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
 * Contract for routing strategy implementations.
 *
 * A strategy encapsulates the logic for deciding which tier to route a
 * request to. Strategies can be reused across different router configurations.
 *
 * When a strategy is set on ModelRouter via setStrategy(), it acts as a
 * high-priority rule evaluated before other rules but after forced overrides.
 *
 * @author Ibrahim
 */
interface RoutingStrategyInterface {
    /**
     * Decides which tier to route this request to.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Chat options (tools, temperature, etc.).
     *
     * @return string|null The tier name to route to, or null to fall through
     *         to lower-priority rules or the default tier.
     */
    public function route(array $messages, array $options): ?string;
}
