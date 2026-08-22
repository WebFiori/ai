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

use WebFiori\Ai\Provider\ProviderInterface;

/**
 * The result of a routing decision made by ModelRouter.
 *
 * Passed to the onRoute() callback for observability — contains the
 * resolved tier, provider, and the reason the routing decision was made.
 *
 * @author Ibrahim
 */
class RouteResult {
    /**
     * @var ProviderInterface
     */
    private ProviderInterface $provider;

    /**
     * @var string
     */
    private string $reason;

    /**
     * @var string
     */
    private string $tier;

    /**
     * Creates a new RouteResult instance.
     *
     * @param string $tier The resolved tier name.
     * @param ProviderInterface $provider The provider for that tier.
     * @param string $reason Human-readable explanation of the routing decision.
     */
    public function __construct(string $tier, ProviderInterface $provider, string $reason) {
        $this->tier = $tier;
        $this->provider = $provider;
        $this->reason = $reason;
    }

    /**
     * Returns the resolved provider.
     *
     * @return ProviderInterface The provider.
     */
    public function getProvider(): ProviderInterface {
        return $this->provider;
    }

    /**
     * Returns the human-readable reason for the routing decision.
     *
     * @return string The reason.
     */
    public function getReason(): string {
        return $this->reason;
    }

    /**
     * Returns the resolved tier name.
     *
     * @return string The tier name.
     */
    public function getTier(): string {
        return $this->tier;
    }
}
