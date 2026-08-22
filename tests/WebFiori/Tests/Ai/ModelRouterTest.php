<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\ModelAliases;
use WebFiori\Ai\Provider\ProviderInterface;
use WebFiori\Ai\Routing\ModelRouter;
use WebFiori\Ai\Routing\RouteResult;
use WebFiori\Ai\Routing\RoutingRule;

/**
 * Tests for #84: ModelRouter with rule-based routing.
 */
class ModelRouterTest extends TestCase {
    // =========================================================================
    // RoutingRule
    // =========================================================================

    public function testRoutingRuleGetters(): void {
        $rule = new RoutingRule(
            condition: fn($msgs, $opts) => true,
            tier: 'smart',
            priority: 10,
            description: 'Always smart'
        );

        $this->assertEquals('smart', $rule->getTier());
        $this->assertEquals(10, $rule->getPriority());
        $this->assertEquals('Always smart', $rule->getDescription());
    }

    public function testRoutingRuleDefaultPriority(): void {
        $rule = new RoutingRule(fn($m, $o) => true, 'fast');
        $this->assertEquals(0, $rule->getPriority());
        $this->assertEquals('', $rule->getDescription());
    }

    public function testRoutingRuleMatchesReturnsTrue(): void {
        $rule = new RoutingRule(fn($m, $o) => true, 'smart');
        $this->assertTrue($rule->matches([new Message('user', 'hi')], []));
    }

    public function testRoutingRuleMatchesReturnsFalse(): void {
        $rule = new RoutingRule(fn($m, $o) => false, 'smart');
        $this->assertFalse($rule->matches([new Message('user', 'hi')], []));
    }

    public function testRoutingRuleConditionReceivesMessagesAndOptions(): void {
        $capturedMessages = null;
        $capturedOptions = null;

        $rule = new RoutingRule(
            function ($messages, $options) use (&$capturedMessages, &$capturedOptions) {
                $capturedMessages = $messages;
                $capturedOptions = $options;

                return true;
            },
            'smart'
        );

        $messages = [new Message('user', 'hello')];
        $options = ['temperature' => 0.5];
        $rule->matches($messages, $options);

        $this->assertSame($messages, $capturedMessages);
        $this->assertSame($options, $capturedOptions);
    }

    // =========================================================================
    // RouteResult
    // =========================================================================

    public function testRouteResultGetters(): void {
        $provider = $this->createMockProvider('openai');
        $result = new RouteResult('fast', $provider, 'rule:short message');

        $this->assertEquals('fast', $result->getTier());
        $this->assertSame($provider, $result->getProvider());
        $this->assertEquals('rule:short message', $result->getReason());
    }

    // =========================================================================
    // ModelRouter — constructor
    // =========================================================================

    public function testConstructorRequiresAtLeastOneProvider(): void {
        $this->expectException(\InvalidArgumentException::class);
        new ModelRouter([]);
    }

    public function testConstructorDefaultTierIsFirstProvider(): void {
        $router = new ModelRouter([
            'fast'  => $this->createMockProvider('openai'),
            'smart' => $this->createMockProvider('google'),
        ]);

        $this->assertEquals('fast', $router->getDefault());
    }

    public function testConstructorExplicitDefault(): void {
        $router = new ModelRouter(
            ['fast' => $this->createMockProvider('openai'), 'smart' => $this->createMockProvider('google')],
            'smart'
        );

        $this->assertEquals('smart', $router->getDefault());
    }

    public function testGetName(): void {
        $router = new ModelRouter(['fast' => $this->createMockProvider('openai')]);
        $this->assertEquals('router', $router->getName());
    }

    public function testGetProviders(): void {
        $p1 = $this->createMockProvider('openai');
        $p2 = $this->createMockProvider('google');
        $router = new ModelRouter(['fast' => $p1, 'smart' => $p2]);

        $providers = $router->getProviders();
        $this->assertSame($p1, $providers['fast']);
        $this->assertSame($p2, $providers['smart']);
    }

    // =========================================================================
    // ModelRouter — routing
    // =========================================================================

    public function testRoutesToDefaultWhenNoRules(): void {
        $fast = $this->createMockProvider('openai');
        $smart = $this->createMockProvider('google');
        $router = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');

        $calls = [];
        $fast->setOnChat(function () use (&$calls) {
            $calls[] = 'fast';
            return $this->makeChatResponse('openai');
        });
        $smart->setOnChat(function () use (&$calls) {
            $calls[] = 'smart';
            return $this->makeChatResponse('google');
        });

        $router->chat([new Message('user', 'Hi')]);

        $this->assertEquals(['fast'], $calls);
    }

    public function testRoutesToMatchingRuleTier(): void {
        $fast = $this->createMockProvider('openai');
        $smart = $this->createMockProvider('google');

        $fastCalled = false;
        $smartCalled = false;

        $fast->setOnChat(function () use (&$fastCalled) {
            $fastCalled = true;
            return $this->makeChatResponse('openai');
        });
        $smart->setOnChat(function () use (&$smartCalled) {
            $smartCalled = true;
            return $this->makeChatResponse('google');
        });

        $router = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
        $router->addRule(new RoutingRule(
            fn($msgs, $opts) => strlen($msgs[0]->getContent()) > 100,
            'smart',
            priority: 10
        ));

        // Short message → default (fast)
        $router->chat([new Message('user', 'Hi')]);
        $this->assertTrue($fastCalled);
        $this->assertFalse($smartCalled);

        $fastCalled = false;
        $smart->setOnChat(function () use (&$smartCalled) {
            $smartCalled = true;
            return $this->makeChatResponse('google');
        });

        // Long message → smart tier
        $router->chat([new Message('user', str_repeat('word ', 30))]);
        $this->assertFalse($fastCalled);
        $this->assertTrue($smartCalled);
    }

    public function testHigherPriorityRuleWins(): void {
        $tiers = [];
        $fast = $this->createMockProvider('openai');
        $smart = $this->createMockProvider('google');
        $coding = $this->createMockProvider('anthropic');

        $fast->setOnChat(function () use (&$tiers) { $tiers[] = 'fast'; return $this->makeChatResponse('openai'); });
        $smart->setOnChat(function () use (&$tiers) { $tiers[] = 'smart'; return $this->makeChatResponse('google'); });
        $coding->setOnChat(function () use (&$tiers) { $tiers[] = 'coding'; return $this->makeChatResponse('anthropic'); });

        $router = new ModelRouter(['fast' => $fast, 'smart' => $smart, 'coding' => $coding], 'fast');

        // Both rules match — higher priority wins
        $router->addRule(new RoutingRule(fn($m, $o) => true, 'smart', priority: 5));
        $router->addRule(new RoutingRule(fn($m, $o) => true, 'coding', priority: 10));

        $router->chat([new Message('user', 'Write code')]);

        $this->assertEquals(['coding'], $tiers);
    }

    public function testRulesAreStoredSortedByPriority(): void {
        $router = new ModelRouter(['a' => $this->createMockProvider('a'), 'b' => $this->createMockProvider('b'), 'c' => $this->createMockProvider('c')]);

        $router->addRule(new RoutingRule(fn($m, $o) => false, 'a', priority: 5));
        $router->addRule(new RoutingRule(fn($m, $o) => false, 'b', priority: 20));
        $router->addRule(new RoutingRule(fn($m, $o) => false, 'c', priority: 1));

        $rules = $router->getRules();
        $this->assertEquals(20, $rules[0]->getPriority());
        $this->assertEquals(5, $rules[1]->getPriority());
        $this->assertEquals(1, $rules[2]->getPriority());
    }

    public function testAddRuleReturnsSelf(): void {
        $router = new ModelRouter(['fast' => $this->createMockProvider('openai')]);
        $result = $router->addRule(new RoutingRule(fn($m, $o) => true, 'fast'));
        $this->assertSame($router, $result);
    }

    // =========================================================================
    // ModelRouter — force_provider option
    // =========================================================================

    public function testForceProviderOptionOverridesRules(): void {
        $fast = $this->createMockProvider('openai');
        $smart = $this->createMockProvider('google');

        $usedTier = null;
        $fast->setOnChat(fn() => ($usedTier = 'fast') ?? $this->makeChatResponse('openai'));
        $smart->setOnChat(function () use (&$usedTier) {
            $usedTier = 'smart';
            return $this->makeChatResponse('google');
        });

        $router = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
        $router->addRule(new RoutingRule(fn($m, $o) => true, 'fast', priority: 100));

        // Rule says 'fast' but force_provider says 'smart'
        $router->chat([new Message('user', 'Hi')], ['force_provider' => 'smart']);
        $this->assertEquals('smart', $usedTier);
    }

    public function testUnknownForceProviderOptionFallsThrough(): void {
        $fast = $this->createMockProvider('openai');
        $usedTier = null;
        $fast->setOnChat(function () use (&$usedTier) {
            $usedTier = 'fast';
            return $this->makeChatResponse('openai');
        });

        $router = new ModelRouter(['fast' => $fast], 'fast');
        $router->chat([new Message('user', 'Hi')], ['force_provider' => 'nonexistent']);

        $this->assertEquals('fast', $usedTier);
    }

    // =========================================================================
    // ModelRouter — forceRoute
    // =========================================================================

    public function testForceRouteOverridesAllRules(): void {
        $fast = $this->createMockProvider('openai');
        $smart = $this->createMockProvider('google');

        $usedTier = null;
        $fast->setOnChat(fn() => ($usedTier = 'fast') ?? $this->makeChatResponse('openai'));
        $smart->setOnChat(function () use (&$usedTier) {
            $usedTier = 'smart';
            return $this->makeChatResponse('google');
        });

        $router = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
        $router->addRule(new RoutingRule(fn($m, $o) => true, 'fast', priority: 100));
        $router->forceRoute('smart');

        $router->chat([new Message('user', 'Hi')]);
        $this->assertEquals('smart', $usedTier);
    }

    public function testForceRouteNullRemovesForcing(): void {
        $fast = $this->createMockProvider('openai');
        $smart = $this->createMockProvider('google');

        $usedTier = null;
        $fast->setOnChat(function () use (&$usedTier) {
            $usedTier = 'fast';
            return $this->makeChatResponse('openai');
        });
        $smart->setOnChat(fn() => ($usedTier = 'smart') ?? $this->makeChatResponse('google'));

        $router = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
        $router->forceRoute('smart');
        $router->forceRoute(null); // remove forcing

        $router->chat([new Message('user', 'Hi')]);
        $this->assertEquals('fast', $usedTier); // back to default
    }

    public function testForceRouteThrowsForUnknownTier(): void {
        $router = new ModelRouter(['fast' => $this->createMockProvider('openai')]);
        $this->expectException(\InvalidArgumentException::class);
        $router->forceRoute('nonexistent');
    }

    public function testForceRouteReturnsSelf(): void {
        $router = new ModelRouter(['fast' => $this->createMockProvider('openai')]);
        $result = $router->forceRoute('fast');
        $this->assertSame($router, $result);
    }

    // =========================================================================
    // ModelRouter — setDefault
    // =========================================================================

    public function testSetDefault(): void {
        $router = new ModelRouter([
            'fast' => $this->createMockProvider('openai'),
            'smart' => $this->createMockProvider('google'),
        ], 'fast');

        $router->setDefault('smart');
        $this->assertEquals('smart', $router->getDefault());
    }

    public function testSetDefaultThrowsForUnknownTier(): void {
        $router = new ModelRouter(['fast' => $this->createMockProvider('openai')]);
        $this->expectException(\InvalidArgumentException::class);
        $router->setDefault('nonexistent');
    }

    public function testSetDefaultReturnsSelf(): void {
        $router = new ModelRouter(['fast' => $this->createMockProvider('openai')]);
        $result = $router->setDefault('fast');
        $this->assertSame($router, $result);
    }

    // =========================================================================
    // ModelRouter — onRoute callback
    // =========================================================================

    public function testOnRouteCallbackInvokedOnChat(): void {
        $result = null;
        $fast = $this->createMockProvider('openai');
        $fast->setOnChat(fn() => $this->makeChatResponse('openai'));

        $router = new ModelRouter(['fast' => $fast]);
        $router->onRoute(function (RouteResult $r) use (&$result) {
            $result = $r;
        });

        $router->chat([new Message('user', 'Hi')]);

        $this->assertNotNull($result);
        $this->assertEquals('fast', $result->getTier());
        $this->assertEquals('default', $result->getReason());
    }

    public function testOnRouteCallbackInvokedOnStreamChat(): void {
        $result = null;
        $fast = $this->createMockProvider('openai');
        $fast->setOnStream(function ($msgs, $onToken, $onComplete) {
            $onToken('hi');
        });

        $router = new ModelRouter(['fast' => $fast]);
        $router->onRoute(function (RouteResult $r) use (&$result) {
            $result = $r;
        });

        $router->streamChat([new Message('user', 'Hi')], fn($t) => null);

        $this->assertNotNull($result);
        $this->assertEquals('fast', $result->getTier());
    }

    public function testOnRouteNullRemovesCallback(): void {
        $called = false;
        $fast = $this->createMockProvider('openai');
        $fast->setOnChat(fn() => $this->makeChatResponse('openai'));

        $router = new ModelRouter(['fast' => $fast]);
        $router->onRoute(function () use (&$called) { $called = true; });
        $router->onRoute(null);

        $router->chat([new Message('user', 'Hi')]);
        $this->assertFalse($called);
    }

    public function testOnRouteReturnsSelf(): void {
        $router = new ModelRouter(['fast' => $this->createMockProvider('openai')]);
        $result = $router->onRoute(fn($r) => null);
        $this->assertSame($router, $result);
    }

    public function testOnRouteShowsRuleReason(): void {
        $reason = null;
        $fast = $this->createMockProvider('openai');
        $smart = $this->createMockProvider('google');
        $fast->setOnChat(fn() => $this->makeChatResponse('openai'));
        $smart->setOnChat(fn() => $this->makeChatResponse('google'));

        $router = new ModelRouter(['fast' => $fast, 'smart' => $smart], 'fast');
        $router->addRule(new RoutingRule(fn($m, $o) => true, 'smart', description: 'test rule'));
        $router->onRoute(function (RouteResult $r) use (&$reason) { $reason = $r->getReason(); });

        $router->chat([new Message('user', 'Hi')]);

        $this->assertEquals('rule:test rule', $reason);
    }

    // =========================================================================
    // ModelRouter — tier name injected as model option
    // =========================================================================

    public function testTierNameInjectedAsModelOption(): void {
        $fast = $this->createMockProvider('openai');
        $capturedOptions = null;

        $fast->setOnChat(function ($msgs, $opts) use (&$capturedOptions) {
            $capturedOptions = $opts;
            return $this->makeChatResponse('openai');
        });

        $router = new ModelRouter(['fast' => $fast]);
        $router->chat([new Message('user', 'Hi')]);

        $this->assertEquals('fast', $capturedOptions['model']);
    }

    public function testExistingModelOptionIsPreserved(): void {
        $fast = $this->createMockProvider('openai');
        $capturedOptions = null;

        $fast->setOnChat(function ($msgs, $opts) use (&$capturedOptions) {
            $capturedOptions = $opts;
            return $this->makeChatResponse('openai');
        });

        $router = new ModelRouter(['fast' => $fast]);
        $router->chat([new Message('user', 'Hi')], ['model' => 'gpt-4o-mini']);

        // Explicit model option is preserved, not overwritten by tier name
        $this->assertEquals('gpt-4o-mini', $capturedOptions['model']);
    }

    // =========================================================================
    // ModelRouter — ModelAliases integration
    // =========================================================================

    public function testModelAliasesResolvedByProvider(): void {
        $aliases = new ModelAliases([
            'fast' => ['openai' => 'gpt-4o-mini', 'google' => 'gemini-2.5-flash'],
        ]);

        $openai = new \WebFiori\Ai\Provider\OpenAI\OpenAIClient(
            new \WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig(apiKey: 'test-key')
        );
        $openai->setModelAliases($aliases);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => '1', 'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ])));
        $openai->setHttpClient($fakeHttp);

        $router = new ModelRouter(['fast' => $openai]);
        $router->chat([new Message('user', 'Hi')]);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        // Tier 'fast' injected as model → alias resolves → 'gpt-4o-mini'
        $this->assertEquals('gpt-4o-mini', $body['model']);
    }

    // =========================================================================
    // ModelRouter — non-chat operations
    // =========================================================================

    public function testEmbedUsesDefaultTier(): void {
        $fast = $this->createMockProvider('openai');
        $embedCalled = false;
        $fast->setOnEmbed(function () use (&$embedCalled) {
            $embedCalled = true;
            return new EmbeddingResponse([[0.1, 0.2]], 'openai');
        });

        $router = new ModelRouter(['fast' => $fast]);
        $router->embed('hello');

        $this->assertTrue($embedCalled);
    }

    public function testGenerateImageUsesDefaultTier(): void {
        $fast = $this->createMockProvider('openai');
        $imageCalled = false;
        $fast->setOnGenerateImage(function () use (&$imageCalled) {
            $imageCalled = true;
            return new ImageResponse([], 'openai');
        });

        $router = new ModelRouter(['fast' => $fast]);
        $router->generateImage(new ImageRequest('a cat'));

        $this->assertTrue($imageCalled);
    }

    public function testHealthCheckAggregatesAllTiers(): void {
        $p1 = $this->createMockProvider('openai');
        $p2 = $this->createMockProvider('google');
        $p1->setHealthResult(HealthCheckResult::success(50, 'test'));
        $p2->setHealthResult(HealthCheckResult::failure('down', 100, 'test'));

        $router = new ModelRouter(['fast' => $p1, 'smart' => $p2]);
        $result = $router->healthCheck();

        $this->assertTrue($result->isAvailable()); // at least one healthy
    }

    public function testHealthCheckFailsWhenAllDown(): void {
        $p1 = $this->createMockProvider('openai');
        $p1->setHealthResult(HealthCheckResult::failure('down', 100, 'test'));

        $router = new ModelRouter(['fast' => $p1]);
        $result = $router->healthCheck();

        $this->assertFalse($result->isAvailable());
        $this->assertStringContainsString('unavailable', $result->getError());
    }

    public function testSetHttpClientPropagatesAll(): void {
        $p1 = $this->createMock(ProviderInterface::class);
        $p2 = $this->createMock(ProviderInterface::class);
        $http = $this->createMock(\WebFiori\Ai\Http\HttpClientInterface::class);

        $p1->expects($this->once())->method('setHttpClient')->with($http);
        $p2->expects($this->once())->method('setHttpClient')->with($http);

        $router = new ModelRouter(['fast' => $p1, 'smart' => $p2]);
        $router->setHttpClient($http);
    }

    public function testSetLogCallbackPropagatesAll(): void {
        $p1 = $this->createMock(ProviderInterface::class);
        $p2 = $this->createMock(ProviderInterface::class);
        $cb = fn() => null;

        $p1->expects($this->once())->method('setLogCallback')->with($cb);
        $p2->expects($this->once())->method('setLogCallback')->with($cb);

        $router = new ModelRouter(['fast' => $p1, 'smart' => $p2]);
        $router->setLogCallback($cb);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createMockProvider(string $name): MockRoutingProvider {
        return new MockRoutingProvider($name);
    }

    private function makeChatResponse(string $model): ChatResponse {
        return new ChatResponse(new Message('assistant', 'response'), $model);
    }
}

/**
 * Flexible mock provider for routing tests.
 */
class MockRoutingProvider implements ProviderInterface {
    private string $name;
    private $onChat = null;
    private $onStream = null;
    private $onEmbed = null;
    private $onGenerateImage = null;
    private ?HealthCheckResult $healthResult = null;

    public function __construct(string $name) { $this->name = $name; }

    public function getName(): string { return $this->name; }

    public function chat(array $messages, array $options = []): ChatResponse {
        if ($this->onChat !== null) return ($this->onChat)($messages, $options);
        return new ChatResponse(new Message('assistant', 'ok'), $this->name);
    }

    public function streamChat(array $messages, callable $onToken, ?callable $onComplete = null, ?callable $onError = null, array $options = []): void {
        if ($this->onStream !== null) {
            ($this->onStream)($messages, $onToken, $onComplete);
            return;
        }
        $onToken('ok');
    }

    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        if ($this->onEmbed !== null) return ($this->onEmbed)($input, $options);
        return new EmbeddingResponse([[0.1]], $this->name);
    }

    public function generateImage(\WebFiori\Ai\ImageRequest $request): ImageResponse {
        if ($this->onGenerateImage !== null) return ($this->onGenerateImage)($request);
        return new ImageResponse([], $this->name);
    }

    public function healthCheck(int $timeout = 5): HealthCheckResult {
        return $this->healthResult ?? HealthCheckResult::success(10, 'test');
    }

    public function setHttpClient(\WebFiori\Ai\Http\HttpClientInterface $client): void {}
    public function setLogCallback(?callable $callback): void {}

    public function setOnChat(callable $fn): void { $this->onChat = $fn; }
    public function setOnStream(callable $fn): void { $this->onStream = $fn; }
    public function setOnEmbed(callable $fn): void { $this->onEmbed = $fn; }
    public function setOnGenerateImage(callable $fn): void { $this->onGenerateImage = $fn; }
    public function setHealthResult(HealthCheckResult $r): void { $this->healthResult = $r; }
}
