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
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\ContextUsage;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Usage;

/**
 * Tests for ContextUsage DTO and AbstractClient::getContextUsage().
 */
class ContextUsageTest extends TestCase {
    // =========================================================================
    // ContextUsage DTO — with known ceiling
    // =========================================================================

    public function testDtoWithKnownCeiling(): void {
        $usage = new ContextUsage(usedTokens: 6200, maxTokens: 8000, reservedTokens: 2000, estimated: true);

        $this->assertSame(6200, $usage->getUsedTokens());
        $this->assertSame(8000, $usage->getMaxTokens());
        $this->assertSame(2000, $usage->getReservedTokens());
        $this->assertSame(6000, $usage->getAvailableTokens());   // 8000 - 2000
        $this->assertSame(0, $usage->getRemainingTokens());       // max(0, 6000 - 6200)
        $this->assertSame(77.5, $usage->getUsedPercentage());     // 6200 / 8000 * 100
        $this->assertTrue($usage->isOverBudget());                // 6200 > 6000
        $this->assertTrue($usage->isEstimated());
    }

    public function testDtoWithinBudget(): void {
        $usage = new ContextUsage(usedTokens: 3000, maxTokens: 8000, reservedTokens: 2000);

        $this->assertSame(3000, $usage->getRemainingTokens());    // 6000 - 3000
        $this->assertSame(37.5, $usage->getUsedPercentage());
        $this->assertFalse($usage->isOverBudget());
        $this->assertTrue($usage->isEstimated());                 // default
    }

    public function testDtoExactlyAtAvailableBudget(): void {
        $usage = new ContextUsage(usedTokens: 6000, maxTokens: 8000, reservedTokens: 2000);

        $this->assertSame(0, $usage->getRemainingTokens());
        $this->assertFalse($usage->isOverBudget());               // 6000 > 6000 is false
    }

    public function testDtoPercentageCanExceed100(): void {
        $usage = new ContextUsage(usedTokens: 9000, maxTokens: 8000, reservedTokens: 0);

        $this->assertSame(112.5, $usage->getUsedPercentage());
        $this->assertTrue($usage->isOverBudget());
        $this->assertSame(0, $usage->getRemainingTokens());
    }

    // =========================================================================
    // ContextUsage DTO — unknown ceiling
    // =========================================================================

    public function testDtoWithUnknownCeiling(): void {
        $usage = new ContextUsage(usedTokens: 5000);

        $this->assertSame(5000, $usage->getUsedTokens());
        $this->assertNull($usage->getMaxTokens());
        $this->assertSame(0, $usage->getReservedTokens());
        $this->assertNull($usage->getAvailableTokens());
        $this->assertNull($usage->getRemainingTokens());
        $this->assertNull($usage->getUsedPercentage());
        $this->assertFalse($usage->isOverBudget());               // unknown ceiling → false
    }

    public function testDtoZeroMaxTokensPercentageIsNull(): void {
        $usage = new ContextUsage(usedTokens: 100, maxTokens: 0);

        $this->assertNull($usage->getUsedPercentage());           // avoid div-by-zero
    }

    // =========================================================================
    // ContextUsage DTO — normalization & flags
    // =========================================================================

    public function testDtoNegativeUsedClampedToZero(): void {
        $usage = new ContextUsage(usedTokens: -50, maxTokens: 1000);

        $this->assertSame(0, $usage->getUsedTokens());
    }

    public function testDtoNegativeReservedClampedToZero(): void {
        $usage = new ContextUsage(usedTokens: 100, maxTokens: 1000, reservedTokens: -10);

        $this->assertSame(0, $usage->getReservedTokens());
        $this->assertSame(1000, $usage->getAvailableTokens());
    }

    public function testDtoReservedExceedsMaxAvailableFlooredToZero(): void {
        $usage = new ContextUsage(usedTokens: 100, maxTokens: 1000, reservedTokens: 5000);

        $this->assertSame(0, $usage->getAvailableTokens());       // max(0, 1000 - 5000)
        $this->assertSame(0, $usage->getRemainingTokens());
        $this->assertTrue($usage->isOverBudget());                // 100 > 0
    }

    public function testDtoActualFlag(): void {
        $usage = new ContextUsage(usedTokens: 100, maxTokens: 1000, reservedTokens: 0, estimated: false);

        $this->assertFalse($usage->isEstimated());
    }

    public function testDtoToArray(): void {
        $usage = new ContextUsage(usedTokens: 6200, maxTokens: 8000, reservedTokens: 2000, estimated: true);

        $this->assertSame([
            'used' => 6200,
            'max' => 8000,
            'reserved' => 2000,
            'available' => 6000,
            'remaining' => 0,
            'percentage' => 77.5,
            'estimated' => true,
            'over_budget' => true,
        ], $usage->toArray());
    }

    public function testDtoToArrayUnknownCeiling(): void {
        $usage = new ContextUsage(usedTokens: 500);
        $array = $usage->toArray();

        $this->assertSame(500, $array['used']);
        $this->assertNull($array['max']);
        $this->assertNull($array['available']);
        $this->assertNull($array['remaining']);
        $this->assertNull($array['percentage']);
        $this->assertFalse($array['over_budget']);
        $this->assertTrue($array['estimated']);
    }

    // =========================================================================
    // getContextUsage() — ceiling resolution
    // =========================================================================

    private function makeClient(): OpenAIClient {
        return new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
    }

    public function testGetContextUsageExplicitMaxTokens(): void {
        $client = $this->makeClient();
        $messages = [new Message('user', str_repeat('word ', 100))];

        $usage = $client->getContextUsage($messages, maxTokens: 8000);

        $this->assertSame(8000, $usage->getMaxTokens());
        $this->assertGreaterThan(0, $usage->getUsedTokens());
        $this->assertTrue($usage->isEstimated());
        $this->assertNotNull($usage->getUsedPercentage());
    }

    public function testGetContextUsageNoCeilingWhenNoStrategyOrMax(): void {
        // Use a model absent from the default ContextWindowConfig table so no
        // ceiling can be inferred from any source.
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'unlisted-model'));
        $messages = [new Message('user', 'Hello')];

        $usage = $client->getContextUsage($messages);

        $this->assertNull($usage->getMaxTokens());
        $this->assertNull($usage->getRemainingTokens());
        $this->assertNull($usage->getUsedPercentage());
        $this->assertGreaterThan(0, $usage->getUsedTokens());
    }

    public function testGetContextUsageResolvesCeilingFromStrategy(): void {
        $client = $this->makeClient();
        $client->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 8000,
            reserveForCompletion: 2000,
        ));

        $messages = [new Message('user', 'Hello world')];
        $usage = $client->getContextUsage($messages);

        $this->assertSame(8000, $usage->getMaxTokens());
        $this->assertSame(2000, $usage->getReservedTokens());
        $this->assertNotNull($usage->getRemainingTokens());
    }

    public function testGetContextUsageExplicitMaxOverridesStrategy(): void {
        $client = $this->makeClient();
        $client->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 8000,
            reserveForCompletion: 2000,
        ));

        $messages = [new Message('user', 'Hello')];
        $usage = $client->getContextUsage($messages, maxTokens: 16000);

        // Explicit wins for max; reserved still comes from the strategy
        $this->assertSame(16000, $usage->getMaxTokens());
        $this->assertSame(2000, $usage->getReservedTokens());
    }

    // =========================================================================
    // getContextUsage() — estimated vs actual
    // =========================================================================

    public function testGetContextUsageEstimatedByDefault(): void {
        $client = $this->makeClient();
        $messages = [new Message('user', 'Some input text here')];

        $usage = $client->getContextUsage($messages);

        $this->assertTrue($usage->isEstimated());
    }

    public function testGetContextUsageActualFromResponse(): void {
        $client = $this->makeClient();
        $messages = [new Message('user', 'Some input text here')];

        $response = new ChatResponse(
            new Message('assistant', 'reply'),
            'gpt-4o',
            new Usage(promptTokens: 1234, completionTokens: 50),
        );

        $usage = $client->getContextUsage($messages, maxTokens: 8000, response: $response);

        $this->assertFalse($usage->isEstimated());
        $this->assertSame(1234, $usage->getUsedTokens());          // exact prompt tokens
        $this->assertSame(8000, $usage->getMaxTokens());
    }

    public function testGetContextUsageFallsBackToEstimateWhenResponseHasNoUsage(): void {
        $client = $this->makeClient();
        $messages = [new Message('user', 'Some input text here')];

        $response = new ChatResponse(new Message('assistant', 'reply'), 'gpt-4o'); // no usage

        $usage = $client->getContextUsage($messages, response: $response);

        $this->assertTrue($usage->isEstimated());
        $this->assertGreaterThan(0, $usage->getUsedTokens());
    }

    public function testGetContextUsageIncludesToolsInEstimate(): void {
        $client = $this->makeClient();
        $messages = [new Message('user', 'Hi')];

        $tool = new Tool('search', 'Search the web for information about a topic', [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'description' => 'The search query']],
            'required' => ['query'],
        ], fn () => 'ok');

        $withoutTools = $client->getContextUsage($messages)->getUsedTokens();
        $withTools = $client->getContextUsage($messages, tools: [$tool])->getUsedTokens();

        $this->assertGreaterThan($withoutTools, $withTools);
    }

    public function testGetContextUsageOverBudgetDetection(): void {
        $client = $this->makeClient();
        // Large input to exceed a small budget
        $messages = [new Message('user', str_repeat('token ', 2000))];

        $usage = $client->getContextUsage($messages, maxTokens: 500);

        $this->assertTrue($usage->isOverBudget());
        $this->assertSame(0, $usage->getRemainingTokens());
        $this->assertGreaterThan(100.0, $usage->getUsedPercentage());
    }
}
