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
use ReflectionMethod;
use WebFiori\Ai\CostResult;
use WebFiori\Ai\PricingConfig;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Usage;

/**
 * Tests for cached input token tracking in Usage, PricingConfig, CostResult
 * and cost calculation (ADR-0062, issue #197).
 */
class CachedTokenUsageTest extends TestCase {
    public function testCostFallsBackToInputPriceWhenCachedPricesNotSet(): void {
        // Same cost as a request with no caching at all.
        $pricing = new PricingConfig(['m' => ['input' => 2.0, 'output' => 8.0]]);

        $withCache = $this->calculateCost($pricing, new Usage(1_000_000, 0, 600_000, 100_000));
        $withoutCache = $this->calculateCost($pricing, new Usage(1_000_000, 0));

        $this->assertEqualsWithDelta($withoutCache->getInputCost(), $withCache->getInputCost(), 1e-9);
        // The breakdown still reports each part at the fallback price.
        $this->assertEqualsWithDelta(1.2, $withCache->getCachedInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.2, $withCache->getCacheWriteCost(), 1e-9);
    }

    // =========================================================================
    // CostResult
    // =========================================================================

    public function testCostResultBreakdownDefaultsToZero(): void {
        $cost = new CostResult(0.001, 0.005, 'm');

        $this->assertSame(0.0, $cost->getCachedInputCost());
        $this->assertSame(0.0, $cost->getCacheWriteCost());
    }

    public function testCostResultBreakdownDoesNotChangeTotals(): void {
        $cost = new CostResult(0.010, 0.020, 'm', 'USD', 0.003, 0.002);

        $this->assertSame(0.003, $cost->getCachedInputCost());
        $this->assertSame(0.002, $cost->getCacheWriteCost());
        $this->assertEqualsWithDelta(0.010, $cost->getInputCost(), 1e-12);
        $this->assertEqualsWithDelta(0.030, $cost->getTotal(), 1e-12);
    }

    public function testCostWithAllThreeInputKinds(): void {
        // 1M prompt: 500k cached at 0.2, 100k writes at 2.5, 400k uncached at 2.0
        $cost = $this->calculateCost(
            new PricingConfig(['m' => [
                'input' => 2.0, 'output' => 8.0, 'cached_input' => 0.2, 'cache_write' => 2.5,
            ]]),
            new Usage(1_000_000, 250_000, 500_000, 100_000)
        );

        $this->assertEqualsWithDelta(0.8 + 0.1 + 0.25, $cost->getInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.1, $cost->getCachedInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.25, $cost->getCacheWriteCost(), 1e-9);
        $this->assertEqualsWithDelta(2.0, $cost->getOutputCost(), 1e-9);
        $this->assertEqualsWithDelta(1.15 + 2.0, $cost->getTotal(), 1e-9);
    }

    public function testCostWithCachedPriceDiscountsCachedTokens(): void {
        // 1M prompt, 600k cached at 0.2: 400k * 2.0 + 600k * 0.2 = 0.8 + 0.12
        $cost = $this->calculateCost(
            new PricingConfig(['m' => ['input' => 2.0, 'output' => 8.0, 'cached_input' => 0.2]]),
            new Usage(1_000_000, 0, 600_000)
        );

        $this->assertEqualsWithDelta(0.92, $cost->getInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.12, $cost->getCachedInputCost(), 1e-9);
        $this->assertSame(0.0, $cost->getCacheWriteCost());
    }

    public function testCostWithCacheWritePrice(): void {
        // 1M prompt, 100k writes at 2.5, 900k uncached at 2.0
        $cost = $this->calculateCost(
            new PricingConfig(['m' => ['input' => 2.0, 'output' => 8.0, 'cache_write' => 2.5]]),
            new Usage(1_000_000, 0, 0, 100_000)
        );

        $this->assertEqualsWithDelta(1.8 + 0.25, $cost->getInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.25, $cost->getCacheWriteCost(), 1e-9);
    }

    public function testCostWithClampedUsageNeverGoesNegative(): void {
        $cost = $this->calculateCost(
            new PricingConfig(['m' => ['input' => 2.0, 'output' => 8.0, 'cached_input' => 0.2]]),
            new Usage(1_000_000, 0, 5_000_000, 5_000_000)
        );

        $this->assertEqualsWithDelta(0.2, $cost->getInputCost(), 1e-9);
    }

    // =========================================================================
    // Cost calculation (AbstractClient::calculateCost)
    // =========================================================================

    public function testCostWithoutCachedTokensIsUnchanged(): void {
        $cost = $this->calculateCost(
            new PricingConfig(['m' => ['input' => 2.0, 'output' => 8.0]]),
            new Usage(1_000_000, 500_000)
        );

        $this->assertEqualsWithDelta(2.0, $cost->getInputCost(), 1e-9);
        $this->assertEqualsWithDelta(4.0, $cost->getOutputCost(), 1e-9);
        $this->assertSame(0.0, $cost->getCachedInputCost());
        $this->assertSame(0.0, $cost->getCacheWriteCost());
    }

    public function testPricingCachedPricesFromArray(): void {
        $config = new PricingConfig([
            'm' => ['input' => 3.0, 'output' => 15.0, 'cached_input' => 0.3, 'cache_write' => 3.75],
        ]);

        $this->assertSame(0.3, $config->getCachedInputPrice('m'));
        $this->assertSame(3.75, $config->getCacheWritePrice('m'));
        $this->assertTrue($config->hasModel('m'));
    }

    // =========================================================================
    // PricingConfig
    // =========================================================================

    public function testPricingCachedPricesNullWhenNotConfigured(): void {
        $config = new PricingConfig(['m' => ['input' => 2.0, 'output' => 8.0]]);

        $this->assertNull($config->getCachedInputPrice('m'));
        $this->assertNull($config->getCacheWritePrice('m'));
        $this->assertNull($config->getCachedInputPrice('unknown'));
        $this->assertNull($config->getCacheWritePrice('unknown'));
    }

    public function testPricingSetModelNamedCachedPriceOnly(): void {
        $config = new PricingConfig();
        $config->setModel('m', 2.5, 10.0, cachedInputPrice: 1.25);

        $this->assertSame(1.25, $config->getCachedInputPrice('m'));
        $this->assertNull($config->getCacheWritePrice('m'));
    }

    public function testPricingSetModelOverwriteDropsOldCachedPrices(): void {
        $config = new PricingConfig();
        $config->setModel('m', 2.5, 10.0, 1.25, 3.0);
        $config->setModel('m', 2.5, 10.0);

        $this->assertNull($config->getCachedInputPrice('m'));
        $this->assertNull($config->getCacheWritePrice('m'));
    }

    public function testPricingSetModelWithCachedPrices(): void {
        $config = new PricingConfig();
        $result = $config->setModel('m', 2.5, 10.0, 1.25, 3.0);

        $this->assertSame($config, $result);
        $this->assertSame(1.25, $config->getCachedInputPrice('m'));
        $this->assertSame(3.0, $config->getCacheWritePrice('m'));
    }

    public function testPricingSetModelWithoutCachedPricesKeepsOldShape(): void {
        $config = new PricingConfig();
        $config->setModel('m', 2.5, 10.0);

        $this->assertSame(['m' => ['input' => 2.5, 'output' => 10.0]], $config->getModels());
    }
    // =========================================================================
    // Usage
    // =========================================================================

    public function testUsageCacheCountsDefaultToZero(): void {
        $usage = new Usage(100, 20);

        $this->assertSame(0, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
    }

    public function testUsageClampsCachedToPrompt(): void {
        $usage = new Usage(50, 5, 80, 0);

        $this->assertSame(50, $usage->getPromptTokens());
        $this->assertSame(50, $usage->getCachedTokens());
    }

    public function testUsageClampsNegativeCountsToZero(): void {
        $usage = new Usage(100, 5, -5, -1);

        $this->assertSame(0, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
    }

    public function testUsageClampsWritesAfterCached(): void {
        // cached + writes = 120 > 100. Cached is kept, writes are reduced.
        $usage = new Usage(100, 5, 70, 50);

        $this->assertSame(100, $usage->getPromptTokens());
        $this->assertSame(70, $usage->getCachedTokens());
        $this->assertSame(30, $usage->getCacheWriteTokens());
    }

    public function testUsageFromArrayWithoutCacheKeysDefaultsToZero(): void {
        // Payload written before cache tracking existed.
        $usage = Usage::fromArray(['prompt_tokens' => 10, 'completion_tokens' => 5]);

        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
    }

    public function testUsageRoundTripKeepsCacheCounts(): void {
        $restored = Usage::fromArray((new Usage(100, 20, 60, 10))->toArray());

        $this->assertSame(100, $restored->getPromptTokens());
        $this->assertSame(20, $restored->getCompletionTokens());
        $this->assertSame(60, $restored->getCachedTokens());
        $this->assertSame(10, $restored->getCacheWriteTokens());
    }

    public function testUsageStoresCacheCounts(): void {
        $usage = new Usage(100, 20, 60, 10);

        $this->assertSame(100, $usage->getPromptTokens());
        $this->assertSame(60, $usage->getCachedTokens());
        $this->assertSame(10, $usage->getCacheWriteTokens());
        $this->assertSame(120, $usage->getTotalTokens());
    }

    public function testUsageSubsetInvariantHolds(): void {
        foreach ([[0, 0, 0], [10, 10, 10], [10, 3, 9], [10, 0, 99], [0, 5, 5]] as [$prompt, $cached, $writes]) {
            $usage = new Usage($prompt, 1, $cached, $writes);

            $this->assertLessThanOrEqual(
                $usage->getPromptTokens(),
                $usage->getCachedTokens() + $usage->getCacheWriteTokens()
            );
        }
    }

    public function testUsageToArrayAlwaysIncludesCacheKeys(): void {
        $this->assertSame(
            [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'cached_tokens' => 0,
                'cache_write_tokens' => 0,
            ],
            (new Usage(10, 5))->toArray()
        );
    }

    private function calculateCost(PricingConfig $pricing, Usage $usage): CostResult {
        $client = new OpenAIClient(new OpenAIClientConfig('test-key', 'm'));
        $client->setPricing($pricing);

        $method = new ReflectionMethod($client, 'calculateCost');
        $method->setAccessible(true);

        return $method->invoke($client, 'm', $usage);
    }
}
