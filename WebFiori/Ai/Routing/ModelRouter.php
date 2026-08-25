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
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;
use WebFiori\Ai\Tool\Tool;

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
     * The provider used to classify tasks in TOOL and HYBRID modes.
     *
     * @var ProviderInterface|null
     */
    private ?ProviderInterface $classifier = null;
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
     * The routing mode.
     *
     * @var RoutingMode
     */
    private RoutingMode $mode = RoutingMode::RULE;

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
     * Optional pluggable routing strategy.
     *
     * @var RoutingStrategyInterface|null
     */
    private ?RoutingStrategyInterface $strategy = null;

    /**
     * Map of tier name → description (for tool-based routing).
     *
     * @var array<string, string>
     */
    private array $tierDescriptions = [];

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
     * Registers a tier with a human-readable description for tool-based routing.
     *
     * The description is sent to the classifier model so it understands
     * what kind of requests belong to this tier.
     *
     * @param string $tier The tier name (must be a registered provider key).
     * @param string $description Human-readable description for the classifier.
     *
     * @return self For method chaining.
     *
     * @throws \InvalidArgumentException If the tier is not registered.
     */
    public function addRoute(string $tier, string $description): self {
        if (!isset($this->providers[$tier])) {
            throw new \InvalidArgumentException(
                "Tier '{$tier}' is not registered. Available tiers: ".implode(', ', array_keys($this->providers))
            );
        }

        $this->tierDescriptions[$tier] = $description;

        return $this;
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
        $options[ChatOption::MODEL] = $options[ChatOption::MODEL] ?? $result->getTier();

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
     * Returns the classifier provider.
     *
     * @return ProviderInterface|null The classifier, or null if not set.
     */
    public function getClassifier(): ?ProviderInterface {
        return $this->classifier;
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
     * Returns the current routing mode.
     *
     * @return RoutingMode The routing mode.
     */
    public function getMode(): RoutingMode {
        return $this->mode;
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
     * Returns the pluggable routing strategy.
     *
     * @return RoutingStrategyInterface|null The strategy, or null if not set.
     */
    public function getStrategy(): ?RoutingStrategyInterface {
        return $this->strategy;
    }

    /**
     * Returns all tier descriptions registered via addRoute().
     *
     * @return array<string, string> Map of tier → description.
     */
    public function getTierDescriptions(): array {
        return $this->tierDescriptions;
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
     * Sets the classifier provider used in TOOL and HYBRID modes.
     *
     * The classifier receives the original messages and a routing tool.
     * It selects the appropriate tier by calling the tool.
     *
     * In HYBRID mode, the classifier is only called when no rule matches.
     * In RULE mode, the classifier is never called regardless of this setting.
     *
     * @param ProviderInterface|null $classifier The classifier provider.
     *
     * @return self For method chaining.
     */
    public function setClassifier(?ProviderInterface $classifier): self {
        $this->classifier = $classifier;

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
     * Sets the routing mode.
     *
     * @param RoutingMode $mode The routing mode.
     *
     * @return self For method chaining.
     */
    public function setMode(RoutingMode $mode): self {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Sets a pluggable routing strategy.
     *
     * When set, the strategy is evaluated after forced overrides but before
     * manual rules. It acts as a high-level routing layer — strategies like
     * TaskComplexityStrategy or KeywordStrategy replace the need to write
     * individual rules for common patterns.
     *
     * @param RoutingStrategyInterface|null $strategy The strategy, or null to disable.
     *
     * @return self For method chaining.
     */
    public function setStrategy(?RoutingStrategyInterface $strategy): self {
        $this->strategy = $strategy;

        return $this;
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

        $options[ChatOption::MODEL] = $options[ChatOption::MODEL] ?? $result->getTier();

        $result->getProvider()->streamChat($messages, $onToken, $onComplete, $onError, $options);
    }

    /**
     * Asks the classifier provider to select a tier via tool call.
     *
     * Sends the original messages plus a routing tool to the classifier.
     * If the classifier calls the routing tool, returns the selected tier.
     * If the classifier doesn't call the tool (or no descriptions registered),
     * returns null.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options The chat options.
     *
     * @return string|null The selected tier name, or null if classification failed.
     */
    private function classifyWithTool(array $messages, array $options): ?string {
        $tierDescriptions = $this->tierDescriptions;

        // Build enum of valid tier names for the tool schema
        $tierNames = array_keys($tierDescriptions);
        $tierEnum = array_values($tierNames);

        // Build description list for the tool
        $descriptionList = implode("\n", array_map(
            fn($tier, $desc) => "- {$tier}: {$desc}",
            array_keys($tierDescriptions),
            $tierDescriptions
        ));

        $routingTool = new Tool(
            'route_to',
            "Select the most appropriate tier for this request.\n\nAvailable tiers:\n{$descriptionList}",
            [
                'type' => 'object',
                'properties' => [
                    'tier' => [
                        'type' => 'string',
                        'enum' => $tierEnum,
                        'description' => 'The tier best suited for this request.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Brief explanation of why this tier was chosen.',
                    ],
                ],
                'required' => ['tier'],
            ],
            fn(array $args) => $args['tier'] // handler never called — we intercept the tool call
        );

        try {
            $classifierOptions = [
                'tools' => [$routingTool],
                'temperature' => 0,       // deterministic classification
                'max_tokens' => 50,       // minimal — just the tool call
            ];

            $response = $this->classifier->chat($messages, $classifierOptions);

            if ($response->hasToolCalls()) {
                $toolCalls = $response->getMessage()->getToolCalls();

                foreach ($toolCalls as $call) {
                    if ($call->getName() === 'route_to') {
                        $tier = $call->getArguments()['tier'] ?? null;

                        if ($tier !== null && isset($this->providers[$tier])) {
                            return $tier;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Classification failed — fall through to default
        }

        return null;
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
     * 3. Rules (if mode is RULE or HYBRID)
     * 4. Tool-based classification (if mode is TOOL or HYBRID and classifier set)
     * 5. Default tier
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
        $forceOption = $options[ChatOption::FORCE_PROVIDER] ?? null;

        if ($forceOption !== null && isset($this->providers[$forceOption])) {
            return new RouteResult(
                $forceOption,
                $this->providers[$forceOption],
                'force_provider_option'
            );
        }

        // 3. Pluggable strategy (evaluated before manual rules)
        if ($this->strategy !== null) {
            $tier = $this->strategy->route($messages, $options);

            if ($tier !== null && isset($this->providers[$tier])) {
                return new RouteResult(
                    $tier,
                    $this->providers[$tier],
                    'strategy:'.get_class($this->strategy)
                );
            }
        }

        // 4. Rule-based routing (RULE or HYBRID mode)
        if ($this->mode !== RoutingMode::TOOL) {
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
        }

        // 4. Tool-based classification (TOOL or HYBRID mode)
        if ($this->mode !== RoutingMode::RULE && $this->classifier !== null && !empty($this->tierDescriptions)) {
            $tier = $this->classifyWithTool($messages, $options);

            if ($tier !== null && isset($this->providers[$tier])) {
                return new RouteResult(
                    $tier,
                    $this->providers[$tier],
                    'tool:classifier'
                );
            }
        }

        // 5. Default tier
        return new RouteResult(
            $this->default,
            $this->providers[$this->default],
            'default'
        );
    }
}
