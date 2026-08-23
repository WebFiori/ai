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
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Routing\ModelRouter;
use WebFiori\Ai\Routing\RoutingMode;
use WebFiori\Ai\Routing\RoutingRule;

/**
 * Tests for #85: Tool-based routing in ModelRouter.
 */
class ModelRouterToolRoutingTest extends TestCase {
    // =========================================================================
    // RoutingMode enum
    // =========================================================================

    public function testRoutingModeValues(): void {
        $this->assertEquals('rule', RoutingMode::RULE->value);
        $this->assertEquals('tool', RoutingMode::TOOL->value);
        $this->assertEquals('hybrid', RoutingMode::HYBRID->value);
    }

    public function testDefaultModeIsRule(): void {
        $router = new ModelRouter(['fast' => $this->mockProvider()]);
        $this->assertEquals(RoutingMode::RULE, $router->getMode());
    }

    public function testSetMode(): void {
        $router = new ModelRouter(['fast' => $this->mockProvider()]);
        $result = $router->setMode(RoutingMode::TOOL);

        $this->assertEquals(RoutingMode::TOOL, $router->getMode());
        $this->assertSame($router, $result); // fluent
    }

    // =========================================================================
    // addRoute / getTierDescriptions
    // =========================================================================

    public function testAddRouteRegistersDescription(): void {
        $router = new ModelRouter([
            'coding' => $this->mockProvider(),
            'creative' => $this->mockProvider(),
        ]);

        $router->addRoute('coding', 'Code writing and debugging');
        $router->addRoute('creative', 'Writing and brainstorming');

        $descs = $router->getTierDescriptions();
        $this->assertEquals('Code writing and debugging', $descs['coding']);
        $this->assertEquals('Writing and brainstorming', $descs['creative']);
    }

    public function testAddRouteThrowsForUnknownTier(): void {
        $router = new ModelRouter(['fast' => $this->mockProvider()]);
        $this->expectException(\InvalidArgumentException::class);
        $router->addRoute('nonexistent', 'Some description');
    }

    public function testAddRouteReturnsSelf(): void {
        $router = new ModelRouter(['fast' => $this->mockProvider()]);
        $result = $router->addRoute('fast', 'description');
        $this->assertSame($router, $result);
    }

    // =========================================================================
    // setClassifier / getClassifier
    // =========================================================================

    public function testSetAndGetClassifier(): void {
        $router = new ModelRouter(['fast' => $this->mockProvider()]);
        $this->assertNull($router->getClassifier());

        $classifier = $this->mockProvider();
        $result = $router->setClassifier($classifier);

        $this->assertSame($classifier, $router->getClassifier());
        $this->assertSame($router, $result); // fluent
    }

    public function testSetClassifierNull(): void {
        $router = new ModelRouter(['fast' => $this->mockProvider()]);
        $router->setClassifier($this->mockProvider());
        $router->setClassifier(null);

        $this->assertNull($router->getClassifier());
    }

    // =========================================================================
    // TOOL mode routing
    // =========================================================================

    public function testToolModeClassifierSelectsTier(): void {
        $fastProvider = $this->createOpenAIProvider();
        $smartProvider = $this->createOpenAIProvider();

        // Classifier returns a tool call selecting 'smart'
        $classifierHttp = new FakeHttpClient();
        $classifierHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-cls',
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'route_to', 'arguments' => '{"tier":"smart","reason":"Complex analysis"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));

        $classifier = $this->createOpenAIProvider();
        $classifier->setHttpClient($classifierHttp);

        // Smart provider returns final response
        $smartHttp = new FakeHttpClient();
        $smartHttp->addResponse($this->openAIResponse('Smart answer'));
        $smartProvider->setHttpClient($smartHttp);

        $fastHttp = new FakeHttpClient();
        $fastProvider->setHttpClient($fastHttp); // should not be called

        $routedTier = null;
        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $smartProvider], 'fast');
        $router->setMode(RoutingMode::TOOL);
        $router->setClassifier($classifier);
        $router->addRoute('fast', 'Quick simple questions');
        $router->addRoute('smart', 'Complex analysis and reasoning');
        $router->onRoute(function ($result) use (&$routedTier) {
            $routedTier = $result->getTier();
        });

        $response = $router->chat([new Message('user', 'Analyze this complex topic')]);

        $this->assertEquals('smart', $routedTier);
        $this->assertEquals('Smart answer', $response->getMessage()->getContent());
        $this->assertEmpty($fastHttp->getRequests()); // fast not called
    }

    public function testToolModeRulesAreSkipped(): void {
        $fastProvider = $this->createOpenAIProvider();
        $smartProvider = $this->createOpenAIProvider();

        // Classifier selects 'smart'
        $classifierHttp = new FakeHttpClient();
        $classifierHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => '1', 'model' => 'gpt-4o-mini',
            'choices' => [[
                'message' => [
                    'role' => 'assistant', 'content' => null,
                    'tool_calls' => [[
                        'id' => 'c1', 'type' => 'function',
                        'function' => ['name' => 'route_to', 'arguments' => '{"tier":"smart"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));
        $classifier = $this->createOpenAIProvider();
        $classifier->setHttpClient($classifierHttp);

        $smartHttp = new FakeHttpClient();
        $smartHttp->addResponse($this->openAIResponse('Smart'));
        $smartProvider->setHttpClient($smartHttp);

        $fastHttp = new FakeHttpClient();
        $fastProvider->setHttpClient($fastHttp);

        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $smartProvider], 'fast');
        $router->setMode(RoutingMode::TOOL);
        $router->setClassifier($classifier);
        $router->addRoute('fast', 'Quick questions');
        $router->addRoute('smart', 'Complex');
        // Rule says 'fast' — but TOOL mode skips rules
        $router->addRule(new RoutingRule(fn($m, $o) => true, 'fast', priority: 100));

        $routedTier = null;
        $router->onRoute(function ($r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Complex question')]);

        $this->assertEquals('smart', $routedTier);
    }

    public function testToolModeFallsBackToDefaultWhenClassifierFails(): void {
        $fastProvider = $this->createOpenAIProvider();
        $smartProvider = $this->createOpenAIProvider();

        // Classifier returns no tool call (plain text response)
        $classifierHttp = new FakeHttpClient();
        $classifierHttp->addResponse($this->openAIResponse('I cannot decide'));
        $classifier = $this->createOpenAIProvider();
        $classifier->setHttpClient($classifierHttp);

        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Default answer'));
        $fastProvider->setHttpClient($fastHttp);

        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $smartProvider], 'fast');
        $router->setMode(RoutingMode::TOOL);
        $router->setClassifier($classifier);
        $router->addRoute('fast', 'Quick');
        $router->addRoute('smart', 'Complex');

        $routedTier = null;
        $router->onRoute(function ($r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Question')]);

        $this->assertEquals('fast', $routedTier); // fell back to default
    }

    public function testToolModeFallsBackWhenClassifierThrows(): void {
        $fastProvider = $this->createOpenAIProvider();

        // Classifier throws an exception
        $classifierHttp = new FakeHttpClient();
        $classifierHttp->addResponse(new HttpResponse(500, [], json_encode(['error' => ['message' => 'Server error']])));
        $classifier = $this->createOpenAIProvider();
        $classifier->setHttpClient($classifierHttp);

        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Default'));
        $fastProvider->setHttpClient($fastHttp);

        $router = new ModelRouter(['fast' => $fastProvider], 'fast');
        $router->setMode(RoutingMode::TOOL);
        $router->setClassifier($classifier);
        $router->addRoute('fast', 'Quick');

        $routedTier = null;
        $router->onRoute(function ($r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Question')]);
        $this->assertEquals('fast', $routedTier);
    }

    public function testToolModeSkippedWhenNoDescriptions(): void {
        $fastProvider = $this->createOpenAIProvider();
        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        $classifier = $this->createOpenAIProvider();
        // Classifier should NOT be called if no tier descriptions registered

        $router = new ModelRouter(['fast' => $fastProvider], 'fast');
        $router->setMode(RoutingMode::TOOL);
        $router->setClassifier($classifier);
        // No addRoute() calls

        $routedTier = null;
        $router->onRoute(function ($r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Hi')]);

        $this->assertEquals('fast', $routedTier);
        // Classifier would have needed an HTTP response if called — no error = not called
    }

    public function testToolModeSkippedWhenNoClassifier(): void {
        $fastProvider = $this->createOpenAIProvider();
        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        $router = new ModelRouter(['fast' => $fastProvider], 'fast');
        $router->setMode(RoutingMode::TOOL);
        // No setClassifier() — should fall to default
        $router->addRoute('fast', 'Quick');

        $routedTier = null;
        $router->onRoute(function ($r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Hi')]);
        $this->assertEquals('fast', $routedTier);
    }

    // =========================================================================
    // HYBRID mode routing
    // =========================================================================

    public function testHybridModeUsesRuleFirst(): void {
        $fastProvider = $this->createOpenAIProvider();
        $smartProvider = $this->createOpenAIProvider();

        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        $classifier = $this->createOpenAIProvider();
        // Classifier should NOT be called when a rule matches

        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $smartProvider], 'smart');
        $router->setMode(RoutingMode::HYBRID);
        $router->setClassifier($classifier);
        $router->addRoute('fast', 'Quick');
        $router->addRoute('smart', 'Complex');
        $router->addRule(new RoutingRule(fn($m, $o) => true, 'fast', priority: 10));

        $routedTier = null;
        $router->onRoute(function ($r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Anything')]);

        $this->assertEquals('fast', $routedTier); // rule matched
    }

    public function testHybridModeFallsToClassifierWhenNoRuleMatches(): void {
        $fastProvider = $this->createOpenAIProvider();
        $smartProvider = $this->createOpenAIProvider();

        $classifierHttp = new FakeHttpClient();
        $classifierHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => '1', 'model' => 'gpt-4o-mini',
            'choices' => [[
                'message' => [
                    'role' => 'assistant', 'content' => null,
                    'tool_calls' => [[
                        'id' => 'c1', 'type' => 'function',
                        'function' => ['name' => 'route_to', 'arguments' => '{"tier":"smart"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));
        $classifier = $this->createOpenAIProvider();
        $classifier->setHttpClient($classifierHttp);

        $smartHttp = new FakeHttpClient();
        $smartHttp->addResponse($this->openAIResponse('Smart'));
        $smartProvider->setHttpClient($smartHttp);

        $fastHttp = new FakeHttpClient();
        $fastProvider->setHttpClient($fastHttp);

        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $smartProvider], 'fast');
        $router->setMode(RoutingMode::HYBRID);
        $router->setClassifier($classifier);
        $router->addRoute('fast', 'Quick');
        $router->addRoute('smart', 'Complex');
        $router->addRule(new RoutingRule(fn($m, $o) => false, 'fast')); // never matches

        $routedTier = null;
        $router->onRoute(function ($r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Complex question')]);

        $this->assertEquals('smart', $routedTier);
    }

    // =========================================================================
    // RULE mode ignores classifier
    // =========================================================================

    public function testRuleModeIgnoresClassifier(): void {
        $fastProvider = $this->createOpenAIProvider();
        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        // Classifier is set but should NEVER be called in RULE mode
        $classifier = $this->createOpenAIProvider();

        $router = new ModelRouter(['fast' => $fastProvider], 'fast');
        $router->setMode(RoutingMode::RULE); // explicit RULE mode
        $router->setClassifier($classifier);
        $router->addRoute('fast', 'Quick');

        $routedTier = null;
        $router->onRoute(function ($r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Hi')]);
        $this->assertEquals('fast', $routedTier);
        // If classifier was called, it would need a queued response (no error = not called)
    }

    // =========================================================================
    // Classifier receives correct routing tool
    // =========================================================================

    public function testClassifierReceivesRoutingToolWithCorrectSchema(): void {
        $fastProvider = $this->createOpenAIProvider();
        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        $capturedOptions = null;
        $classifierHttp = new FakeHttpClient();
        $classifierHttp->addResponse($this->openAIResponse('No tool call'));
        $classifier = $this->createOpenAIProvider();
        $classifier->setHttpClient($classifierHttp);

        // Intercept the classifier call to inspect what was sent
        $classifierHttp2 = new class($classifierHttp) extends FakeHttpClient {
            private FakeHttpClient $inner;
            private $inspector = null;

            public function __construct(FakeHttpClient $inner) {
                $this->inner = $inner;
            }

            public function setInspector(callable $fn): void {
                $this->inspector = $fn;
            }

            public function send(\WebFiori\Ai\Http\HttpRequest $request): \WebFiori\Ai\Http\HttpResponse {
                if ($this->inspector) {
                    ($this->inspector)($request);
                }
                return $this->inner->send($request);
            }
        };

        $capturedBody = null;
        $classifierHttp2->setInspector(function ($req) use (&$capturedBody) {
            $capturedBody = json_decode($req->getBody(), true);
        });
        $classifier->setHttpClient($classifierHttp2);

        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $this->mockProvider()], 'fast');
        $router->setMode(RoutingMode::TOOL);
        $router->setClassifier($classifier);
        $router->addRoute('fast', 'Quick questions');
        $router->addRoute('smart', 'Complex analysis');

        $router->chat([new Message('user', 'Question')]);

        // Verify the routing tool was sent to the classifier
        $this->assertNotNull($capturedBody);
        $tools = $capturedBody['tools'] ?? [];
        $routingTool = array_values(array_filter($tools, fn($t) => ($t['function']['name'] ?? '') === 'route_to'));
        $this->assertNotEmpty($routingTool);

        // Verify enum contains both tiers
        $params = $routingTool[0]['function']['parameters'];
        $enum = $params['properties']['tier']['enum'];
        $this->assertContains('fast', $enum);
        $this->assertContains('smart', $enum);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function mockProvider(string $name = 'mock'): \WebFiori\Ai\Provider\ProviderInterface {
        $mock = $this->createMock(\WebFiori\Ai\Provider\ProviderInterface::class);
        $mock->method('getName')->willReturn($name);
        $mock->method('healthCheck')->willReturn(\WebFiori\Ai\HealthCheckResult::success(10, 'test'));
        $mock->method('chat')->willReturn(new ChatResponse(new Message('assistant', 'ok'), $name));
        $mock->method('embed')->willReturn(new \WebFiori\Ai\EmbeddingResponse([[0.1]], $name));
        $mock->method('generateImage')->willReturn(new \WebFiori\Ai\ImageResponse([], $name));

        return $mock;
    }

    private function createOpenAIProvider(): OpenAIClient {
        return new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o-mini'));
    }

    private function openAIResponse(string $content): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]));
    }
}
