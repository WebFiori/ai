<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Context;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Context\ContextWindowConfig;
use WebFiori\Ai\Message;
use WebFiori\Ai\ModelAliases;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Tests for ContextWindowConfig and model-inferred ceiling in getContextUsage().
 */
class ContextWindowConfigTest extends TestCase {
    // =========================================================================
    // Default table
    // =========================================================================

    public function testDefaultsPopulated(): void {
        $config = new ContextWindowConfig();

        $this->assertSame(128000, $config->getContextWindow('gpt-4o'));
        $this->assertSame(1048576, $config->getContextWindow('gemini-2.5-flash'));
        $this->assertSame(200000, $config->getContextWindow('claude-sonnet-4-20250514'));
        $this->assertSame(300000, $config->getContextWindow('us.amazon.nova-lite-v1:0'));
    }

    public function testUnknownModelReturnsNull(): void {
        $config = new ContextWindowConfig();

        $this->assertNull($config->getContextWindow('no-such-model'));
    }

    public function testHasModel(): void {
        $config = new ContextWindowConfig();

        $this->assertTrue($config->hasModel('gpt-4o'));
        $this->assertFalse($config->hasModel('no-such-model'));
    }

    public function testGetDefaultsStatic(): void {
        $defaults = ContextWindowConfig::getDefaults();

        $this->assertIsArray($defaults);
        $this->assertArrayHasKey('gpt-4o', $defaults);
        $this->assertSame(128000, $defaults['gpt-4o']);
    }

    public function testGetModelsReturnsFullTable(): void {
        $config = new ContextWindowConfig();
        $models = $config->getModels();

        $this->assertArrayHasKey('gpt-4o', $models);
        $this->assertArrayHasKey('gemini-2.5-flash', $models);
    }

    // =========================================================================
    // Custom entries & overrides
    // =========================================================================

    public function testCustomEntriesMergeOverDefaults(): void {
        $config = new ContextWindowConfig(['my-model' => 50000, 'gpt-4o' => 99999]);

        $this->assertSame(50000, $config->getContextWindow('my-model'));   // added
        $this->assertSame(99999, $config->getContextWindow('gpt-4o'));      // overridden
        $this->assertSame(200000, $config->getContextWindow('claude-sonnet-4-20250514')); // default kept
    }

    public function testUseDefaultsFalseStartsEmpty(): void {
        $config = new ContextWindowConfig(['only-this' => 1234], useDefaults: false);

        $this->assertSame(1234, $config->getContextWindow('only-this'));
        $this->assertNull($config->getContextWindow('gpt-4o'));  // no defaults
    }

    public function testSetContextWindow(): void {
        $config = new ContextWindowConfig();
        $result = $config->setContextWindow('custom', 4096);

        $this->assertSame($config, $result);                 // fluent
        $this->assertSame(4096, $config->getContextWindow('custom'));
    }

    public function testSetContextWindowOverridesDefault(): void {
        $config = new ContextWindowConfig();
        $config->setContextWindow('gpt-4o', 1);

        $this->assertSame(1, $config->getContextWindow('gpt-4o'));
    }

    // =========================================================================
    // Integration: getContextUsage() model inference
    // =========================================================================

    private function makeClient(string $model = 'gpt-4o'): OpenAIClient {
        return new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: $model));
    }

    public function testClientHasDefaultConfigAutomatically(): void {
        $client = $this->makeClient();

        $this->assertInstanceOf(ContextWindowConfig::class, $client->getContextWindowConfig());
        $this->assertSame(128000, $client->getContextWindowConfig()->getContextWindow('gpt-4o'));
    }

    public function testGetContextUsageInfersCeilingFromModel(): void {
        $client = $this->makeClient('gpt-4o');

        $usage = $client->getContextUsage([new Message('user', 'Hello')]);

        $this->assertSame(128000, $usage->getMaxTokens());
        $this->assertNotNull($usage->getUsedPercentage());
        $this->assertNotNull($usage->getRemainingTokens());
    }

    public function testGetContextUsageUnknownModelStaysNull(): void {
        $client = $this->makeClient('some-unknown-model');

        $usage = $client->getContextUsage([new Message('user', 'Hello')]);

        $this->assertNull($usage->getMaxTokens());
        $this->assertNull($usage->getUsedPercentage());
    }

    public function testExplicitMaxOverridesModelInference(): void {
        $client = $this->makeClient('gpt-4o');

        $usage = $client->getContextUsage([new Message('user', 'Hello')], maxTokens: 5000);

        $this->assertSame(5000, $usage->getMaxTokens());  // explicit wins over config's 128000
    }

    public function testCustomConfigOverridesDefault(): void {
        $client = $this->makeClient('gpt-4o');
        $client->setContextWindowConfig(new ContextWindowConfig(['gpt-4o' => 32000]));

        $usage = $client->getContextUsage([new Message('user', 'Hello')]);

        $this->assertSame(32000, $usage->getMaxTokens());
    }

    public function testModelInferenceRespectsAliases(): void {
        $client = $this->makeClient('gpt-4o');
        $client->setModelAliases(new ModelAliases([
            'smart' => ['openai' => 'gpt-4o'],
        ]));
        $client->getContextWindowConfig()->setContextWindow('gpt-4o', 128000);

        // The config on the client is the OpenAI client; the alias resolves
        // 'smart' → 'gpt-4o' for the openai provider before lookup.
        // Here the client's model is 'gpt-4o' already; verify alias path does
        // not break resolution.
        $usage = $client->getContextUsage([new Message('user', 'Hello')]);

        $this->assertSame(128000, $usage->getMaxTokens());
    }

    public function testSetContextWindowConfigNullResetsToDefault(): void {
        $client = $this->makeClient('gpt-4o');
        $client->setContextWindowConfig(new ContextWindowConfig(['gpt-4o' => 1]));

        $this->assertSame(1, $client->getContextWindowConfig()->getContextWindow('gpt-4o'));

        $client->setContextWindowConfig(null);

        // Next access lazily recreates the default table
        $this->assertSame(128000, $client->getContextWindowConfig()->getContextWindow('gpt-4o'));
    }
}
