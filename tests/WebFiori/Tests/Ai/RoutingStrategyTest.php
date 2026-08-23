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
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Routing\ModelRouter;
use WebFiori\Ai\Routing\RouteResult;
use WebFiori\Ai\Routing\Strategy\AlwaysStrategy;
use WebFiori\Ai\Routing\Strategy\KeywordStrategy;
use WebFiori\Ai\Routing\Strategy\TaskComplexityStrategy;
use WebFiori\Ai\Routing\Strategy\TokenLengthStrategy;
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for #94: RoutingStrategyInterface and built-in strategies.
 */
class RoutingStrategyTest extends TestCase {
    // =========================================================================
    // AlwaysStrategy
    // =========================================================================

    public function testAlwaysStrategyRoutesToFixedTier(): void {
        $strategy = new AlwaysStrategy('fast');
        $this->assertEquals('fast', $strategy->route([new Message('user', 'Anything')], []));
        $this->assertEquals('fast', $strategy->getTier());
    }

    public function testAlwaysStrategyIgnoresMessages(): void {
        $strategy = new AlwaysStrategy('smart');
        $this->assertEquals('smart', $strategy->route([new Message('user', str_repeat('x', 1000))], []));
    }

    // =========================================================================
    // TokenLengthStrategy
    // =========================================================================

    public function testTokenLengthStrategyBelowThresholdUsesFast(): void {
        $strategy = new TokenLengthStrategy('fast', 'smart', 500);
        $messages = [new Message('user', 'Short question.')];

        $this->assertEquals('fast', $strategy->route($messages, []));
    }

    public function testTokenLengthStrategyAtThresholdUsesComplex(): void {
        $strategy = new TokenLengthStrategy('fast', 'smart', 10);
        $messages = [new Message('user', str_repeat('x', 10))]; // exactly at threshold

        $this->assertEquals('smart', $strategy->route($messages, []));
    }

    public function testTokenLengthStrategyAboveThresholdUsesComplex(): void {
        $strategy = new TokenLengthStrategy('fast', 'smart', 100);
        $messages = [new Message('user', str_repeat('word ', 30))]; // 150 chars

        $this->assertEquals('smart', $strategy->route($messages, []));
    }

    public function testTokenLengthStrategyOnlyCountsUserMessages(): void {
        $strategy = new TokenLengthStrategy('fast', 'smart', 100);
        $messages = [
            new Message('system', str_repeat('system text ', 100)), // large but not user
            new Message('user', 'Short.'),
        ];

        $this->assertEquals('fast', $strategy->route($messages, []));
    }

    public function testTokenLengthStrategyGetters(): void {
        $strategy = new TokenLengthStrategy('fast', 'smart', 250);
        $this->assertEquals('fast', $strategy->getFastTier());
        $this->assertEquals('smart', $strategy->getComplexTier());
        $this->assertEquals(250, $strategy->getThreshold());
    }

    public function testTokenLengthStrategyMinimumThresholdIs1(): void {
        $strategy = new TokenLengthStrategy('fast', 'smart', 0);
        $this->assertEquals(1, $strategy->getThreshold());
    }

    // =========================================================================
    // KeywordStrategy
    // =========================================================================

    public function testKeywordStrategyMatchesFirstKeyword(): void {
        $strategy = new KeywordStrategy([
            'coding'   => ['code', 'function', 'debug'],
            'creative' => ['story', 'poem'],
        ], default: 'fast');

        $messages = [new Message('user', 'Write a function to sort a list')];
        $this->assertEquals('coding', $strategy->route($messages, []));
    }

    public function testKeywordStrategyMatchesSecondTier(): void {
        $strategy = new KeywordStrategy([
            'coding'   => ['code', 'debug'],
            'creative' => ['story', 'poem'],
        ], default: 'fast');

        $messages = [new Message('user', 'Write a poem about PHP')];
        $this->assertEquals('creative', $strategy->route($messages, []));
    }

    public function testKeywordStrategyReturnsDefaultWhenNoMatch(): void {
        $strategy = new KeywordStrategy([
            'coding' => ['code', 'debug'],
        ], default: 'fast');

        $messages = [new Message('user', 'What is the weather today?')];
        $this->assertEquals('fast', $strategy->route($messages, []));
    }

    public function testKeywordStrategyIsCaseInsensitive(): void {
        $strategy = new KeywordStrategy(['coding' => ['CODE']], default: 'fast');
        $messages = [new Message('user', 'Write some code please')];
        $this->assertEquals('coding', $strategy->route($messages, []));
    }

    public function testKeywordStrategyChecksAllUserMessages(): void {
        $strategy = new KeywordStrategy(['coding' => ['debug']], default: 'fast');
        $messages = [
            new Message('user', 'hello'),
            new Message('assistant', 'debug this'),  // assistant not checked
            new Message('user', 'debug this function'),
        ];
        $this->assertEquals('coding', $strategy->route($messages, []));
    }

    public function testKeywordStrategyGetters(): void {
        $patterns = ['coding' => ['code']];
        $strategy = new KeywordStrategy($patterns, 'fast');

        $this->assertEquals('fast', $strategy->getDefault());
        $this->assertEquals($patterns, $strategy->getPatterns());
    }

    // =========================================================================
    // TaskComplexityStrategy
    // =========================================================================

    public function testTaskComplexityStrategySimpleMessage(): void {
        $strategy = new TaskComplexityStrategy('fast', 'smart');
        $messages = [new Message('user', 'Hi')];

        $this->assertEquals('fast', $strategy->route($messages, []));
    }

    public function testTaskComplexityStrategyLongMessageIncreasesScore(): void {
        $strategy = new TaskComplexityStrategy('fast', 'smart', scoreThreshold: 1);
        $messages = [new Message('user', str_repeat('word ', 70))]; // > 300 chars

        $this->assertEquals('smart', $strategy->route($messages, []));
    }

    public function testTaskComplexityStrategyKeywordIncreasesScore(): void {
        $strategy = new TaskComplexityStrategy('fast', 'smart', scoreThreshold: 1);
        $messages = [new Message('user', 'Please analyze this data')];

        $this->assertEquals('smart', $strategy->route($messages, []));
    }

    public function testTaskComplexityStrategyManyToolsIncreasesScore(): void {
        $strategy = new TaskComplexityStrategy('fast', 'smart', scoreThreshold: 1);
        $messages = [new Message('user', 'Do something')];
        $tools = [
            new Tool('a', 'A', ['type' => 'object', 'properties' => []], fn() => ''),
            new Tool('b', 'B', ['type' => 'object', 'properties' => []], fn() => ''),
            new Tool('c', 'C', ['type' => 'object', 'properties' => []], fn() => ''),
        ];

        $this->assertEquals('smart', $strategy->route($messages, ['tools' => $tools]));
    }

    public function testTaskComplexityStrategyLongConversationIncreasesScore(): void {
        $strategy = new TaskComplexityStrategy('fast', 'smart', scoreThreshold: 1);
        $messages = [];

        for ($i = 0; $i < 5; $i++) {
            $messages[] = new Message('user', 'message '.$i);
        }

        $this->assertEquals('smart', $strategy->route($messages, []));
    }

    public function testTaskComplexityStrategyMultimodalIncreasesScore(): void {
        $strategy = new TaskComplexityStrategy('fast', 'smart', scoreThreshold: 1);
        $messages = [
            new Message('user', [
                \WebFiori\Ai\ContentPart::text('Analyze this image'),
                \WebFiori\Ai\ContentPart::imageBase64(base64_encode('img'), 'image/png'),
            ]),
        ];

        $this->assertEquals('smart', $strategy->route($messages, []));
    }

    public function testTaskComplexityStrategyCustomKeywords(): void {
        $strategy = new TaskComplexityStrategy(
            'fast', 'smart',
            scoreThreshold: 1,
            keywords: ['review', 'feedback']
        );
        $messages = [new Message('user', 'Please review this PR')];

        $this->assertEquals('smart', $strategy->route($messages, []));
    }

    public function testTaskComplexityStrategyCustomKeywordsDoNotMatchDefaults(): void {
        $strategy = new TaskComplexityStrategy(
            'fast', 'smart',
            scoreThreshold: 1,
            keywords: ['review']
        );
        // 'analyze' is a default keyword but not in custom list
        $messages = [new Message('user', 'analyze this')];

        $this->assertEquals('fast', $strategy->route($messages, []));
    }

    public function testTaskComplexityStrategyGetters(): void {
        $strategy = new TaskComplexityStrategy('fast', 'smart', 3, ['keyword']);
        $this->assertEquals('fast', $strategy->getFastTier());
        $this->assertEquals('smart', $strategy->getComplexTier());
        $this->assertEquals(3, $strategy->getScoreThreshold());
        $this->assertEquals(['keyword'], $strategy->getKeywords());
    }

    public function testTaskComplexityStrategyMinimumScoreIs1(): void {
        $strategy = new TaskComplexityStrategy('fast', 'smart', 0);
        $this->assertEquals(1, $strategy->getScoreThreshold());
    }

    // =========================================================================
    // ModelRouter.setStrategy integration
    // =========================================================================

    public function testSetStrategyRouterUsesStrategy(): void {
        $fastProvider = $this->createOpenAIProvider();
        $smartProvider = $this->createOpenAIProvider();

        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        $smartHttp = new FakeHttpClient();
        $smartHttp->addResponse($this->openAIResponse('Smart'));
        $smartProvider->setHttpClient($smartHttp);

        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $smartProvider], 'fast');
        $router->setStrategy(new AlwaysStrategy('smart'));

        $routedTier = null;
        $router->onRoute(function (RouteResult $r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Hi')]);

        $this->assertEquals('smart', $routedTier);
    }

    public function testSetStrategyReturnsNullFallsThrough(): void {
        $fastProvider = $this->createOpenAIProvider();
        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        $router = new ModelRouter(['fast' => $fastProvider], 'fast');

        // Strategy returns null → fall through to default
        $nullStrategy = new class implements \WebFiori\Ai\Routing\RoutingStrategyInterface {
            public function route(array $messages, array $options): ?string { return null; }
        };
        $router->setStrategy($nullStrategy);

        $routedTier = null;
        $router->onRoute(function (RouteResult $r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Hi')]);
        $this->assertEquals('fast', $routedTier);
    }

    public function testSetStrategyNullDisables(): void {
        $fastProvider = $this->createOpenAIProvider();
        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        $router = new ModelRouter(['fast' => $fastProvider], 'fast');
        $router->setStrategy(new AlwaysStrategy('fast'));
        $router->setStrategy(null);

        $this->assertNull($router->getStrategy());
    }

    public function testSetStrategyReturnsSelf(): void {
        $router = new ModelRouter(['fast' => $this->createMock(\WebFiori\Ai\Provider\ProviderInterface::class)]);
        $result = $router->setStrategy(new AlwaysStrategy('fast'));
        $this->assertSame($router, $result);
    }

    public function testStrategyEvaluatedBeforeRules(): void {
        $fastProvider = $this->createOpenAIProvider();
        $smartProvider = $this->createOpenAIProvider();

        $smartHttp = new FakeHttpClient();
        $smartHttp->addResponse($this->openAIResponse('Smart'));
        $smartProvider->setHttpClient($smartHttp);

        $fastHttp = new FakeHttpClient();
        $fastProvider->setHttpClient($fastHttp);

        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $smartProvider], 'fast');
        // Rule says 'fast' — but strategy has higher priority
        $router->addRule(new \WebFiori\Ai\Routing\RoutingRule(fn($m, $o) => true, 'fast', priority: 100));
        $router->setStrategy(new AlwaysStrategy('smart'));

        $routedTier = null;
        $router->onRoute(function (RouteResult $r) use (&$routedTier) { $routedTier = $r->getTier(); });

        $router->chat([new Message('user', 'Hi')]);
        $this->assertEquals('smart', $routedTier);
    }

    public function testTokenLengthStrategyIntegration(): void {
        $fastProvider = $this->createOpenAIProvider();
        $smartProvider = $this->createOpenAIProvider();

        $fastHttp = new FakeHttpClient();
        $fastHttp->addResponse($this->openAIResponse('Fast'));
        $fastProvider->setHttpClient($fastHttp);

        $smartHttp = new FakeHttpClient();
        $smartHttp->addResponse($this->openAIResponse('Smart'));
        $smartProvider->setHttpClient($smartHttp);

        $router = new ModelRouter(['fast' => $fastProvider, 'smart' => $smartProvider], 'fast');
        $router->setStrategy(new TokenLengthStrategy('fast', 'smart', 20));

        $routedTier = null;
        $router->onRoute(function (RouteResult $r) use (&$routedTier) { $routedTier = $r->getTier(); });

        // Short message → fast
        $router->chat([new Message('user', 'Hi')]);
        $this->assertEquals('fast', $routedTier);

        // Long message → smart
        $fastHttp->addResponse($this->openAIResponse('Fast2'));
        $smartHttp->addResponse($this->openAIResponse('Smart2'));
        $router->chat([new Message('user', str_repeat('x ', 15))]);
        $this->assertEquals('smart', $routedTier);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private ?string $lastRouteReason = null;

    private function getReason(): ?string {
        return $this->lastRouteReason;
    }

    private function createOpenAIProvider(): OpenAIClient {
        return new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o-mini'));
    }

    private function openAIResponse(string $content): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1', 'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
        ]));
    }
}
