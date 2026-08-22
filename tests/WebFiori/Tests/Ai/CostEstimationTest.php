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
use WebFiori\Ai\CostEstimate;
use WebFiori\Ai\CostResult;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\PricingConfig;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Tests for #33: Cost Estimation and tracking.
 */
class CostEstimationTest extends TestCase {
    // =========================================================================
    // PricingConfig
    // =========================================================================

    public function testPricingConfigDefaults(): void {
        $config = new PricingConfig();
        $this->assertEquals('USD', $config->getCurrency());
        $this->assertEmpty($config->getModels());
    }

    public function testPricingConfigCustomCurrency(): void {
        $config = new PricingConfig([], 'EUR');
        $this->assertEquals('EUR', $config->getCurrency());
    }

    public function testPricingConfigHasModel(): void {
        $config = new PricingConfig([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        ]);

        $this->assertTrue($config->hasModel('gpt-4o'));
        $this->assertFalse($config->hasModel('gpt-3.5'));
    }

    public function testPricingConfigGetInputPrice(): void {
        $config = new PricingConfig([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        ]);

        $this->assertEquals(2.50, $config->getInputPrice('gpt-4o'));
        $this->assertNull($config->getInputPrice('unknown-model'));
    }

    public function testPricingConfigGetOutputPrice(): void {
        $config = new PricingConfig([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        ]);

        $this->assertEquals(10.00, $config->getOutputPrice('gpt-4o'));
        $this->assertNull($config->getOutputPrice('unknown-model'));
    }

    public function testPricingConfigSetModel(): void {
        $config = new PricingConfig();
        $result = $config->setModel('gpt-4o', 2.50, 10.00);

        $this->assertSame($config, $result); // fluent
        $this->assertTrue($config->hasModel('gpt-4o'));
        $this->assertEquals(2.50, $config->getInputPrice('gpt-4o'));
        $this->assertEquals(10.00, $config->getOutputPrice('gpt-4o'));
    }

    public function testPricingConfigSetModelOverwrites(): void {
        $config = new PricingConfig(['gpt-4o' => ['input' => 2.50, 'output' => 10.00]]);
        $config->setModel('gpt-4o', 3.00, 12.00);

        $this->assertEquals(3.00, $config->getInputPrice('gpt-4o'));
    }

    public function testPricingConfigGetModels(): void {
        $models = ['gpt-4o' => ['input' => 2.50, 'output' => 10.00]];
        $config = new PricingConfig($models);

        $this->assertEquals($models, $config->getModels());
    }

    public function testPricingConfigHasModelReturnsFalseIfPartial(): void {
        // Only has input, missing output
        $config = new PricingConfig(['partial' => ['input' => 1.0]]);
        $this->assertFalse($config->hasModel('partial'));
    }

    // =========================================================================
    // CostResult
    // =========================================================================

    public function testCostResultGetters(): void {
        $cost = new CostResult(0.001, 0.005, 'gpt-4o', 'USD');

        $this->assertEquals(0.001, $cost->getInputCost());
        $this->assertEquals(0.005, $cost->getOutputCost());
        $this->assertEquals(0.006, $cost->getTotal());
        $this->assertEquals('gpt-4o', $cost->getModel());
        $this->assertEquals('USD', $cost->getCurrency());
    }

    public function testCostResultTotal(): void {
        $cost = new CostResult(0.0025, 0.0100, 'gpt-4o');
        $this->assertEqualsWithDelta(0.0125, $cost->getTotal(), 0.000001);
    }

    public function testCostResultDefaultCurrency(): void {
        $cost = new CostResult(0.001, 0.005, 'gpt-4o');
        $this->assertEquals('USD', $cost->getCurrency());
    }

    // =========================================================================
    // CostEstimate
    // =========================================================================

    public function testCostEstimateGetters(): void {
        $estimate = new CostEstimate(100, 512, 0.00025, 0.00538, 'gpt-4o', 'USD');

        $this->assertEquals(100, $estimate->getPromptTokens());
        $this->assertEquals(512, $estimate->getMaxTokens());
        $this->assertEquals(0.00025, $estimate->getMinCost());
        $this->assertEquals(0.00538, $estimate->getMaxCost());
        $this->assertEquals('gpt-4o', $estimate->getModel());
        $this->assertEquals('USD', $estimate->getCurrency());
    }

    public function testCostEstimateDefaultCurrency(): void {
        $estimate = new CostEstimate(100, 512, 0.001, 0.01, 'gpt-4o');
        $this->assertEquals('USD', $estimate->getCurrency());
    }

    // =========================================================================
    // Integration: cost attached to ChatResponse
    // =========================================================================

    public function testCostAttachedToResponseWhenPricingConfigured(): void {
        $client = $this->createOpenAIClient();
        $client->setPricing(new PricingConfig([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        ]));

        $fakeHttp = $this->fakeOpenAIResponse('gpt-4o', 1000, 500);
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);

        $cost = $response->getCost();
        $this->assertNotNull($cost);
        $this->assertEquals('gpt-4o', $cost->getModel());
        $this->assertEquals('USD', $cost->getCurrency());

        // input: 1000 tokens * $2.50/1M = $0.0025
        $this->assertEqualsWithDelta(0.0025, $cost->getInputCost(), 0.000001);

        // output: 500 tokens * $10.00/1M = $0.005
        $this->assertEqualsWithDelta(0.005, $cost->getOutputCost(), 0.000001);

        // total: $0.0075
        $this->assertEqualsWithDelta(0.0075, $cost->getTotal(), 0.000001);
    }

    public function testNoCostWhenPricingNotConfigured(): void {
        $client = $this->createOpenAIClient();
        // No pricing set

        $fakeHttp = $this->fakeOpenAIResponse('gpt-4o', 100, 50);
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);

        $this->assertNull($response->getCost());
    }

    public function testNoCostWhenModelNotInPricingTable(): void {
        $client = $this->createOpenAIClient();
        $client->setPricing(new PricingConfig([
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
            // gpt-4o is NOT in the table
        ]));

        $fakeHttp = $this->fakeOpenAIResponse('gpt-4o', 100, 50);
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);

        // No pricing for gpt-4o → null cost
        $this->assertNull($response->getCost());
    }

    public function testNoCostWhenNoUsageInResponse(): void {
        $client = $this->createOpenAIClient();
        $client->setPricing(new PricingConfig([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        ]));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi!'],
                'finish_reason' => 'stop',
            ]],
            // No 'usage' field
        ])));
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);

        $this->assertNull($response->getCost());
    }

    // =========================================================================
    // setPricing / getPricing
    // =========================================================================

    public function testGetPricingReturnsNullByDefault(): void {
        $client = $this->createOpenAIClient();
        $this->assertNull($client->getPricing());
    }

    public function testGetPricingReturnsSetConfig(): void {
        $client = $this->createOpenAIClient();
        $pricing = new PricingConfig(['gpt-4o' => ['input' => 2.50, 'output' => 10.00]]);
        $client->setPricing($pricing);

        $this->assertSame($pricing, $client->getPricing());
    }

    public function testSetPricingNull(): void {
        $client = $this->createOpenAIClient();
        $client->setPricing(new PricingConfig());
        $client->setPricing(null);

        $this->assertNull($client->getPricing());
    }

    // =========================================================================
    // estimateCost()
    // =========================================================================

    public function testEstimateCostReturnsNullWithoutPricing(): void {
        $client = $this->createOpenAIClient();

        $estimate = $client->estimateCost([new Message('user', 'Hello')]);

        $this->assertNull($estimate);
    }

    public function testEstimateCostReturnsNullForUnknownModel(): void {
        $client = $this->createOpenAIClient();
        $client->setPricing(new PricingConfig([
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        ]));

        $estimate = $client->estimateCost(
            [new Message('user', 'Hello')],
            ['model' => 'gpt-4o'] // not in pricing table
        );

        $this->assertNull($estimate);
    }

    public function testEstimateCostCalculatesCorrectly(): void {
        $client = $this->createOpenAIClient();
        $client->setPricing(new PricingConfig([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        ]));

        $estimate = $client->estimateCost(
            [new Message('user', 'Hello world this is a test message')],
            ['max_tokens' => 1000]
        );

        $this->assertNotNull($estimate);
        $this->assertEquals('gpt-4o', $estimate->getModel());
        $this->assertEquals('USD', $estimate->getCurrency());
        $this->assertEquals(1000, $estimate->getMaxTokens());
        $this->assertGreaterThan(0, $estimate->getPromptTokens());
        $this->assertGreaterThan(0, $estimate->getMinCost());
        $this->assertGreaterThan($estimate->getMinCost(), $estimate->getMaxCost());
    }

    public function testEstimateCostUsesDefaultMaxTokens(): void {
        $client = $this->createOpenAIClient();
        $client->setPricing(new PricingConfig([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        ]));

        $estimate = $client->estimateCost([new Message('user', 'Hi')]);

        $this->assertNotNull($estimate);
        $this->assertEquals(1024, $estimate->getMaxTokens()); // default
    }

    public function testEstimateCostResolvesAlias(): void {
        $client = $this->createOpenAIClient();
        $client->setPricing(new PricingConfig([
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        ]));
        $client->setModelAliases(new \WebFiori\Ai\ModelAliases([
            'fast' => ['openai' => 'gpt-4o-mini'],
        ]));

        $estimate = $client->estimateCost(
            [new Message('user', 'Hello')],
            ['model' => 'fast']
        );

        $this->assertNotNull($estimate);
        $this->assertEquals('gpt-4o-mini', $estimate->getModel());
    }

    public function testCostResultSetOnChatResponse(): void {
        $response = new \WebFiori\Ai\ChatResponse(
            new Message('assistant', 'Hi'),
            'gpt-4o'
        );

        $this->assertNull($response->getCost());

        $cost = new CostResult(0.001, 0.002, 'gpt-4o');
        $response->setCost($cost);

        $this->assertSame($cost, $response->getCost());
    }

    public function testCostResultSetNullOnChatResponse(): void {
        $response = new \WebFiori\Ai\ChatResponse(
            new Message('assistant', 'Hi'),
            'gpt-4o'
        );
        $response->setCost(new CostResult(0.001, 0.002, 'gpt-4o'));
        $response->setCost(null);

        $this->assertNull($response->getCost());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createOpenAIClient(): OpenAIClient {
        return new OpenAIClient(new OpenAIClientConfig(
            apiKey: 'test-key',
            model: 'gpt-4o',
        ));
    }

    private function fakeOpenAIResponse(string $model, int $promptTokens, int $completionTokens): FakeHttpClient {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => $model,
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ])));

        return $fakeHttp;
    }
}
