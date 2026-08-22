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
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\ModelAliases;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Tests for #32: ModelAliases — model alias registry.
 */
class ModelAliasesTest extends TestCase {
    // =========================================================================
    // Constructor
    // =========================================================================

    public function testEmptyConstructor(): void {
        $aliases = new ModelAliases();
        $this->assertEmpty($aliases->getAll());
    }

    public function testConstructorWithProviderMap(): void {
        $aliases = new ModelAliases([
            'fast' => [
                'openai'    => 'gpt-4o-mini',
                'anthropic' => 'claude-haiku-4-5',
            ],
        ]);

        $this->assertTrue($aliases->has('fast'));
        $this->assertEquals('gpt-4o-mini', $aliases->resolve('fast', 'openai'));
        $this->assertEquals('claude-haiku-4-5', $aliases->resolve('fast', 'anthropic'));
    }

    public function testConstructorWithStringValue(): void {
        // String value = same model for all providers
        $aliases = new ModelAliases([
            'universal' => 'some-model-id',
        ]);

        $this->assertEquals('some-model-id', $aliases->resolve('universal', 'openai'));
        $this->assertEquals('some-model-id', $aliases->resolve('universal', 'anthropic'));
        $this->assertEquals('some-model-id', $aliases->resolve('universal', 'google'));
    }

    // =========================================================================
    // add()
    // =========================================================================

    public function testAddWithProviderMap(): void {
        $aliases = new ModelAliases();
        $aliases->add('smart', ['openai' => 'gpt-4o', 'google' => 'gemini-2.5-pro']);

        $this->assertTrue($aliases->has('smart'));
        $this->assertEquals('gpt-4o', $aliases->resolve('smart', 'openai'));
        $this->assertEquals('gemini-2.5-pro', $aliases->resolve('smart', 'google'));
    }

    public function testAddWithString(): void {
        $aliases = new ModelAliases();
        $aliases->add('universal', 'shared-model');

        $this->assertEquals('shared-model', $aliases->resolve('universal', 'openai'));
        $this->assertEquals('shared-model', $aliases->resolve('universal', 'bedrock'));
    }

    public function testAddReturnsFluentInterface(): void {
        $aliases = new ModelAliases();
        $result = $aliases->add('fast', ['openai' => 'gpt-4o-mini']);

        $this->assertSame($aliases, $result);
    }

    public function testAddOverwritesExistingAlias(): void {
        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);
        $aliases->add('fast', ['openai' => 'gpt-4o']);

        $this->assertEquals('gpt-4o', $aliases->resolve('fast', 'openai'));
    }

    // =========================================================================
    // remove()
    // =========================================================================

    public function testRemoveAlias(): void {
        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);
        $aliases->remove('fast');

        $this->assertFalse($aliases->has('fast'));
    }

    public function testRemoveReturnsFluentInterface(): void {
        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);
        $result = $aliases->remove('fast');

        $this->assertSame($aliases, $result);
    }

    public function testRemoveNonExistentAlias(): void {
        $aliases = new ModelAliases();
        $aliases->remove('nonexistent'); // Should not throw

        $this->assertFalse($aliases->has('nonexistent'));
    }

    // =========================================================================
    // has()
    // =========================================================================

    public function testHasReturnsTrueForRegisteredAlias(): void {
        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);
        $this->assertTrue($aliases->has('fast'));
    }

    public function testHasReturnsFalseForUnknownAlias(): void {
        $aliases = new ModelAliases();
        $this->assertFalse($aliases->has('nonexistent'));
    }

    // =========================================================================
    // getAll()
    // =========================================================================

    public function testGetAllReturnsAllAliases(): void {
        $aliases = new ModelAliases([
            'fast'  => ['openai' => 'gpt-4o-mini'],
            'smart' => ['openai' => 'gpt-4o'],
        ]);

        $all = $aliases->getAll();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('fast', $all);
        $this->assertArrayHasKey('smart', $all);
    }

    // =========================================================================
    // resolve()
    // =========================================================================

    public function testResolveExactProviderMatch(): void {
        $aliases = new ModelAliases([
            'fast' => [
                'openai'    => 'gpt-4o-mini',
                'anthropic' => 'claude-haiku-4-5',
                'google'    => 'gemini-2.5-flash',
            ],
        ]);

        $this->assertEquals('gpt-4o-mini', $aliases->resolve('fast', 'openai'));
        $this->assertEquals('claude-haiku-4-5', $aliases->resolve('fast', 'anthropic'));
        $this->assertEquals('gemini-2.5-flash', $aliases->resolve('fast', 'google'));
    }

    public function testResolveWildcardMatchWhenNoExactProvider(): void {
        $aliases = new ModelAliases(['universal' => 'shared-model-id']);

        // No exact match for 'bedrock' but wildcard '*' exists
        $this->assertEquals('shared-model-id', $aliases->resolve('universal', 'bedrock'));
    }

    public function testResolveLiteralFallbackWhenNoAliasFound(): void {
        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);

        // 'gpt-4o' is not an alias — return as-is
        $this->assertEquals('gpt-4o', $aliases->resolve('gpt-4o', 'openai'));
    }

    public function testResolveLiteralFallbackWhenProviderNotInAlias(): void {
        $aliases = new ModelAliases([
            'fast' => ['openai' => 'gpt-4o-mini'], // only openai mapped
        ]);

        // 'fast' alias exists but 'bedrock' has no mapping → return 'fast' as-is
        $this->assertEquals('fast', $aliases->resolve('fast', 'bedrock'));
    }

    public function testResolvePreservesLiteralModelNames(): void {
        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);

        // Real model name should pass through unchanged
        $this->assertEquals('gpt-4o-2024-08-06', $aliases->resolve('gpt-4o-2024-08-06', 'openai'));
    }

    // =========================================================================
    // Integration with AbstractClient
    // =========================================================================

    public function testClientUsesAliasWhenSet(): void {
        $client = new OpenAIClient(new OpenAIClientConfig(
            apiKey: 'test-key',
            model: 'gpt-4o',
        ));

        $aliases = new ModelAliases([
            'fast' => ['openai' => 'gpt-4o-mini'],
        ]);
        $client->setModelAliases($aliases);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ])));
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')], ['model' => 'fast']);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        // Alias 'fast' resolved to 'gpt-4o-mini' for openai provider
        $this->assertEquals('gpt-4o-mini', $body['model']);
    }

    public function testClientUsesDefaultModelWhenAliasNotFound(): void {
        $client = new OpenAIClient(new OpenAIClientConfig(
            apiKey: 'test-key',
            model: 'gpt-4o',
        ));

        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);
        $client->setModelAliases($aliases);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ])));
        $client->setHttpClient($fakeHttp);

        // Use a literal model name that is not an alias
        $client->chat([new Message('user', 'Hi')], ['model' => 'gpt-4o']);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $this->assertEquals('gpt-4o', $body['model']);
    }

    public function testClientWithoutAliasesWorksNormally(): void {
        $client = new OpenAIClient(new OpenAIClientConfig(
            apiKey: 'test-key',
            model: 'gpt-4o',
        ));

        // No aliases set — should work normally
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ])));
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $this->assertEquals('gpt-4o', $body['model']);
    }

    public function testGetModelAliasesReturnsSetRegistry(): void {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $this->assertNull($client->getModelAliases());

        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);
        $client->setModelAliases($aliases);

        $this->assertSame($aliases, $client->getModelAliases());
    }

    public function testSetModelAliasesNull(): void {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);
        $client->setModelAliases($aliases);
        $client->setModelAliases(null);

        $this->assertNull($client->getModelAliases());
    }

    public function testStreamChatResolvesAlias(): void {
        $client = new OpenAIClient(new OpenAIClientConfig(
            apiKey: 'test-key',
            model: 'gpt-4o',
        ));

        $aliases = new ModelAliases(['fast' => ['openai' => 'gpt-4o-mini']]);
        $client->setModelAliases($aliases);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"id\":\"1\",\"model\":\"gpt-4o-mini\",\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":null}]}\n\n",
            "data: [DONE]\n\n",
        ]);
        $client->setHttpClient($fakeHttp);

        $client->streamChat(
            [new Message('user', 'Hi')],
            fn(string $t) => null,
            options: ['model' => 'fast']
        );

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $this->assertEquals('gpt-4o-mini', $body['model']);
    }

    // =========================================================================
    // FallbackProvider resolves aliases per provider
    // =========================================================================

    public function testFallbackProviderResolvesAliasPerProvider(): void {
        $openaiAliases = new ModelAliases([
            'smart' => ['openai' => 'gpt-4o', 'google' => 'gemini-2.5-pro'],
        ]);

        $openaiClient = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $openaiClient->setModelAliases($openaiAliases);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ])));
        $openaiClient->setHttpClient($fakeHttp);

        $fallback = new \WebFiori\Ai\Provider\Fallback\FallbackProvider([$openaiClient]);
        $fallback->chat([new Message('user', 'Hi')], ['model' => 'smart']);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        // OpenAI resolves 'smart' → 'gpt-4o'
        $this->assertEquals('gpt-4o', $body['model']);
    }
}
