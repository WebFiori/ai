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

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * Routes chat requests to different providers based on configurable rules.
 *
 * ModelRouter implements ProviderInterface and acts as a drop-in replacement
 * anywhere a provider is used. It evaluates registered rules against each
 * incoming request and forwards to the matching tier's provider.
 *
 * Tiers are logical names (e.g., 'fast', 'smart', 'coding') that map to
 * providers. Each provider can carry a ModelAliases registry to resolve the
 * tier name to its own model ID, keeping routing logic portable across
 * providers.
 *
 * ```php
 * $aliases = new ModelAliases([
 *     'fast'  => ['openai' => 'gpt-4o-mini', 'google' => 'gemini-2.5-flash'],
 *     'smart' => ['openai' => 'gpt-4o',      'google' => 'gemini-2.5-pro'],
 * ]);
 *
 * $openai->setModelAliases($aliases);
 * $google->setModelAliases($aliases);
 *
 * $router = new ModelRouter(
 *     providers: ['fast' => $openai, 'smart' => $google],
 *     default: 'fast',
 * );
 *
 * // Route long/complex messages to 'smart', others to 'fast'
 * $router->addRule(
 *     new RoutingRule(
 *         condition: fn($msgs, $opts) => strlen($msgs[0]->getContent()) > 500,
 *         tier: 'smart',
 *         priority: 10,
 *         description: 'Long messages → smart tier',
 *     )
 * );
 *
 * $response = $router->chat($messages);
 * ```
 *
 * @author Ibrahim
 */
class ModelRouter implements ProviderInterface {
    /**
     * The default tier name when no rule matches.
     *
     * @var string
     */
    private string $default;

    /**
     * Optional forced tier name (overrides all rules).
     *
     * @var string|null
     */
    private ?string $forcedTier = null;

    /**
     * Optional observability callback.
     *
     * @var callable|null
     */
    private $onRouteCallback = null;

    /**
     * Map of tier name → provider.
     *
     * @var array<string, ProviderInterface>
     */
    private array $providers;

    /**
     * Registered routing rules, sorted by priority (descending).
     *
     * @var RoutingRule[]
     */
    private array $rules = [];

    /**
     * Creates a new ModelRouter instance.
     *
     * @param array<string, ProviderInterface> $providers Map of tier name → provider.
     * @param string|null $default The default tier name when no rule matches.
     *        If null, uses the first registered tier.
     *
     * @throws \InvalidArgumentException If no providers are given.
     */
    public function __construct(array $providers, ?string $default = null) {
        if (empty($providers)) {
            throw new \InvalidArgumentException('ModelRouter requires at least one provider.');
        }

        $this->providers = $providers;
        $this->default = $default ?? array_key_first($providers);
    }

    /**
     * Adds a routing rule.
     *
     * Rules are evaluated in descending priority order. The first rule
     * whose condition returns true determines the tier.
     *
     * @param RoutingRule $rule The rule to add.
     *
     * @return self For method chaining.
     */
    public function addRule(RoutingRule $rule): self {
        $this->rules[] = $rule;
        usort($this->rules, fn(RoutingRule $a, RoutingRule $b) => $b->getPriority() - $a->getPriority());

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $messages, array $options = []): ChatResponse {
        $result = $this->resolve($messages, $options);
        $this->emitRoute($result);

        // Inject tier name as model option so the provider's ModelAliases can resolve it
        $options['model'] = $options['model'] ?? $result->getTier();

        return $result->getProvider()->chat($messages, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        $tier = $this->forcedTier ?? $this->default;
        $provider = $this->providers[$tier] ?? $this->providers[array_key_first($this->providers)];

        return $provider->embed($input, $options);
    }

    /**
     * Forces all requests to a specific tier, bypassing all rules.
     *
     * Pass null to remove the forced tier.
     *
     * @param string|null $tier The tier name to force, or null to disable.
     *
     * @return self For method chaining.
     *
     * @throws \InvalidArgumentException If the tier is not registered.
     */
    public function forceRoute(?string $tier): self {
        if ($tier !== null && !isset($this->providers[$tier])) {
            throw new \InvalidArgumentException(
                "Tier '{$tier}' is not registered. Available tiers: ".implode(', ', array_keys($this->providers))
            );
        }

        $this->forcedTier = $tier;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function generateImage(ImageRequest $request): ImageResponse {
        $tier = $this->forcedTier ?? $this->default;
        $provider = $this->providers[$tier] ?? $this->providers[array_key_first($this->providers)];

        return $provider->generateImage($request);
    }

    /**
     * Returns the default tier name.
     *
     * @return string The default tier name.
     */
    public function getDefault(): string {
        return $this->default;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'router';
    }

    /**
     * Returns all registered providers.
     *
     * @return array<string, ProviderInterface> Map of tier name → provider.
     */
    public function getProviders(): array {
        return $this->providers;
    }

    /**
     * Returns all registered rules.
     *
     * @return RoutingRule[] The rules in priority order.
     */
    public function getRules(): array {
        return $this->rules;
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(int $timeout = 5): HealthCheckResult {
        $available = 0;
        $errors = [];
        $start = hrtime(true);

        foreach ($this->providers as $tier => $provider) {
            $result = $provider->healthCheck($timeout);

            if ($result->isAvailable()) {
                $available++;
            } else {
                $errors[] = "{$tier}: ".($result->getError() ?? 'unavailable');
            }
        }

        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($available > 0) {
            return new HealthCheckResult(true, $latencyMs, 'router_health_check');
        }

        return new HealthCheckResult(
            false,
            $latencyMs,
            'router_health_check',
            'All tiers unavailable: '.implode('; ', $errors)
        );
    }

    /**
     * Sets an observability callback invoked after every routing decision.
     *
     * @param callable|null $callback Signature: function(RouteResult $result): void
     *
     * @return self For method chaining.
     */
    public function onRoute(?callable $callback): self {
        $this->onRouteCallback = $callback;

        return $this;
    }

    /**
     * Sets the default tier used when no rule matches.
     *
     * @param string $tier The default tier name.
     *
     * @return self For method chaining.
     *
     * @throws \InvalidArgumentException If the tier is not registered.
     */
    public function setDefault(string $tier): self {
        if (!isset($this->providers[$tier])) {
            throw new \InvalidArgumentException(
                "Tier '{$tier}' is not registered. Available tiers: ".implode(', ', array_keys($this->providers))
            );
        }

        $this->default = $tier;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setHttpClient(HttpClientInterface $client): void {
        foreach ($this->providers as $provider) {
            $provider->setHttpClient($client);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setLogCallback(?callable $callback): void {
        foreach ($this->providers as $provider) {
            $provider->setLogCallback($callback);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function streamChat(
        array $messages,
        callable $onToken,
        ?callable $onComplete = null,
        ?callable $onError = null,
        array $options = []
    ): void {
        $result = $this->resolve($messages, $options);
        $this->emitRoute($result);

        $options['model'] = $options['model'] ?? $result->getTier();

        $result->getProvider()->streamChat($messages, $onToken, $onComplete, $onError, $options);
    }

    /**
     * Emits the routing result to the onRoute callback if set.
     *
     * @param RouteResult $result The routing result.
     */
    private function emitRoute(RouteResult $result): void {
        if ($this->onRouteCallback !== null) {
            ($this->onRouteCallback)($result);
        }
    }

    /**
     * Resolves the tier and provider for a request.
     *
     * Evaluation order:
     * 1. forcedTier (if set)
     * 2. force_provider option (tier name)
     * 3. Rules in priority order
     * 4. Default tier
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options The chat options.
     *
     * @return RouteResult The resolved tier, provider, and reason.
     */
    private function resolve(array $messages, array $options): RouteResult {
        // 1. Global forced tier
        if ($this->forcedTier !== null) {
            return new RouteResult(
                $this->forcedTier,
                $this->providers[$this->forcedTier],
                'forced_route'
            );
        }

        // 2. Per-call force_provider option
        $forceOption = $options['force_provider'] ?? null;

        if ($forceOption !== null && isset($this->providers[$forceOption])) {
            return new RouteResult(
                $forceOption,
                $this->providers[$forceOption],
                'force_provider_option'
            );
        }

        // 3. Rule-based routing
        foreach ($this->rules as $rule) {
            if ($rule->matches($messages, $options)) {
                $tier = $rule->getTier();

                if (isset($this->providers[$tier])) {
                    return new RouteResult(
                        $tier,
                        $this->providers[$tier],
                        'rule:'.($rule->getDescription() ?: $tier)
                    );
                }
            }
        }

        // 4. Default tier
        return new RouteResult(
            $this->default,
            $this->providers[$this->default],
            'default'
        );
    }
}
