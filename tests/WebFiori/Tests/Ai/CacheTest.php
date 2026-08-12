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
use WebFiori\Ai\Cache\CachedResponse;
use WebFiori\Ai\Cache\CacheConfig;
use WebFiori\Ai\Cache\CacheInterface;
use WebFiori\Ai\Cache\CacheKeyGenerator;
use WebFiori\Ai\Cache\InMemoryCache;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Usage;

/**
 * Tests for the caching functionality.
 */
class CacheTest extends TestCase {
    // =========================================================================
    // CachedResponse Tests
    // =========================================================================

    /**
     * @test
     */
    public function testCachedResponseConstruction() {
        $data = ['test' => 'data'];
        $response = new CachedResponse($data, 'chat');

        $this->assertSame($data, $response->getData());
        $this->assertSame('chat', $response->getType());
        $this->assertLessThanOrEqual(time(), $response->getCreatedAt());
    }

    /**
     * @test
     */
    public function testCachedResponseWithCustomTimestamp() {
        $timestamp = 1234567890;
        $response = new CachedResponse('data', 'embedding', $timestamp);

        $this->assertSame($timestamp, $response->getCreatedAt());
    }

    /**
     * @test
     */
    public function testCachedResponseTypesChat() {
        $response = new CachedResponse(null, 'chat');
        $this->assertSame('chat', $response->getType());
    }

    /**
     * @test
     */
    public function testCachedResponseTypesEmbedding() {
        $response = new CachedResponse(null, 'embedding');
        $this->assertSame('embedding', $response->getType());
    }

    // =========================================================================
    // CacheConfig Tests
    // =========================================================================

    /**
     * @test
     */
    public function testCacheConfigDefaults() {
        $config = new CacheConfig();

        $this->assertTrue($config->isEnabled());
        $this->assertSame(3600, $config->getDefaultTtl());
        $this->assertSame(86400, $config->getEmbeddingTtl());
        $this->assertSame(0.0, $config->getSkipCacheAboveTemperature());
    }

    /**
     * @test
     */
    public function testCacheConfigCustomValues() {
        $config = new CacheConfig(
            enabled: false,
            defaultTtl: 7200,
            embeddingTtl: 172800,
            skipCacheAboveTemperature: 0.5
        );

        $this->assertFalse($config->isEnabled());
        $this->assertSame(7200, $config->getDefaultTtl());
        $this->assertSame(172800, $config->getEmbeddingTtl());
        $this->assertSame(0.5, $config->getSkipCacheAboveTemperature());
    }

    /**
     * @test
     */
    public function testCacheConfigShouldCacheChatWhenDisabled() {
        $config = new CacheConfig(enabled: false);

        $this->assertFalse($config->shouldCacheChat(0.0));
        $this->assertFalse($config->shouldCacheChat(1.0));
    }

    /**
     * @test
     */
    public function testCacheConfigShouldCacheChatWithTemperatureZero() {
        $config = new CacheConfig(skipCacheAboveTemperature: 0.0);

        $this->assertTrue($config->shouldCacheChat(0.0));
        $this->assertFalse($config->shouldCacheChat(0.1));
        $this->assertFalse($config->shouldCacheChat(1.0));
    }

    /**
     * @test
     */
    public function testCacheConfigShouldCacheChatWithHigherThreshold() {
        $config = new CacheConfig(skipCacheAboveTemperature: 0.7);

        $this->assertTrue($config->shouldCacheChat(0.0));
        $this->assertTrue($config->shouldCacheChat(0.5));
        $this->assertTrue($config->shouldCacheChat(0.7));
        $this->assertFalse($config->shouldCacheChat(0.8));
        $this->assertFalse($config->shouldCacheChat(1.0));
    }

    /**
     * @test
     */
    public function testCacheConfigShouldCacheChatWithNullThreshold() {
        $config = new CacheConfig(skipCacheAboveTemperature: null);

        $this->assertTrue($config->shouldCacheChat(0.0));
        $this->assertTrue($config->shouldCacheChat(0.5));
        $this->assertTrue($config->shouldCacheChat(1.0));
        $this->assertTrue($config->shouldCacheChat(2.0));
    }

    /**
     * @test
     */
    public function testCacheConfigShouldCacheChatWithNullTemperature() {
        // When temperature is not provided, assume default (1.0)
        $config = new CacheConfig(skipCacheAboveTemperature: 0.0);
        $this->assertFalse($config->shouldCacheChat(null));

        $config2 = new CacheConfig(skipCacheAboveTemperature: 1.0);
        $this->assertTrue($config2->shouldCacheChat(null));
    }

    /**
     * @test
     */
    public function testCacheConfigShouldCacheEmbedding() {
        $config = new CacheConfig(enabled: true);
        $this->assertTrue($config->shouldCacheEmbedding());

        $config2 = new CacheConfig(enabled: false);
        $this->assertFalse($config2->shouldCacheEmbedding());
    }

    // =========================================================================
    // CacheKeyGenerator Tests
    // =========================================================================

    /**
     * @test
     */
    public function testCacheKeyGeneratorForChat() {
        $generator = new CacheKeyGenerator();

        $key = $generator->forChat(
            'openai',
            'gpt-4o',
            [new Message('user', 'Hello')],
            ['temperature' => 0]
        );

        $this->assertStringStartsWith('chat_', $key);
        $this->assertSame(64 + 5, strlen($key)); // 'chat_' + 64 char sha256
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorDeterministic() {
        $generator = new CacheKeyGenerator();
        $messages = [new Message('user', 'Hello')];
        $options = ['temperature' => 0];

        $key1 = $generator->forChat('openai', 'gpt-4o', $messages, $options);
        $key2 = $generator->forChat('openai', 'gpt-4o', $messages, $options);

        $this->assertSame($key1, $key2);
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorDifferentMessages() {
        $generator = new CacheKeyGenerator();

        $key1 = $generator->forChat('openai', 'gpt-4o', [new Message('user', 'Hello')], []);
        $key2 = $generator->forChat('openai', 'gpt-4o', [new Message('user', 'Hi')], []);

        $this->assertNotSame($key1, $key2);
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorDifferentModels() {
        $generator = new CacheKeyGenerator();
        $messages = [new Message('user', 'Hello')];

        $key1 = $generator->forChat('openai', 'gpt-4o', $messages, []);
        $key2 = $generator->forChat('openai', 'gpt-4o-mini', $messages, []);

        $this->assertNotSame($key1, $key2);
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorDifferentProviders() {
        $generator = new CacheKeyGenerator();
        $messages = [new Message('user', 'Hello')];

        $key1 = $generator->forChat('openai', 'gpt-4o', $messages, []);
        $key2 = $generator->forChat('google', 'gpt-4o', $messages, []);

        $this->assertNotSame($key1, $key2);
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorDifferentOptions() {
        $generator = new CacheKeyGenerator();
        $messages = [new Message('user', 'Hello')];

        $key1 = $generator->forChat('openai', 'gpt-4o', $messages, ['temperature' => 0]);
        $key2 = $generator->forChat('openai', 'gpt-4o', $messages, ['temperature' => 0.5]);

        $this->assertNotSame($key1, $key2);
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorIgnoresIrrelevantOptions() {
        $generator = new CacheKeyGenerator();
        $messages = [new Message('user', 'Hello')];

        // 'tools' and 'auto_execute_tools' should not affect the key
        $key1 = $generator->forChat('openai', 'gpt-4o', $messages, ['temperature' => 0]);
        $key2 = $generator->forChat('openai', 'gpt-4o', $messages, ['temperature' => 0, 'tools' => []]);

        $this->assertSame($key1, $key2);
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorForEmbedding() {
        $generator = new CacheKeyGenerator();

        $key = $generator->forEmbedding('openai', 'text-embedding-3-small', 'Hello', []);

        $this->assertStringStartsWith('embed_', $key);
        $this->assertSame(64 + 6, strlen($key)); // 'embed_' + 64 char sha256
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorForEmbeddingBatch() {
        $generator = new CacheKeyGenerator();

        $key1 = $generator->forEmbedding('openai', 'text-embedding-3-small', ['Hello', 'World'], []);
        $key2 = $generator->forEmbedding('openai', 'text-embedding-3-small', ['Hello'], []);

        $this->assertNotSame($key1, $key2);
    }

    /**
     * @test
     */
    public function testCacheKeyGeneratorForEmbeddingWithDimensions() {
        $generator = new CacheKeyGenerator();

        $key1 = $generator->forEmbedding('openai', 'text-embedding-3-small', 'Hello', []);
        $key2 = $generator->forEmbedding('openai', 'text-embedding-3-small', 'Hello', ['dimensions' => 512]);

        $this->assertNotSame($key1, $key2);
    }

    // =========================================================================
    // InMemoryCache Tests
    // =========================================================================

    /**
     * @test
     */
    public function testInMemoryCacheSetAndGet() {
        $cache = new InMemoryCache();
        $response = new CachedResponse('test data', 'chat');

        $cache->set('key1', $response, 3600);
        $retrieved = $cache->get('key1');

        $this->assertNotNull($retrieved);
        $this->assertSame('test data', $retrieved->getData());
    }

    /**
     * @test
     */
    public function testInMemoryCacheGetNonExistent() {
        $cache = new InMemoryCache();

        $this->assertNull($cache->get('nonexistent'));
    }

    /**
     * @test
     */
    public function testInMemoryCacheHas() {
        $cache = new InMemoryCache();
        $response = new CachedResponse('data', 'chat');

        $this->assertFalse($cache->has('key1'));

        $cache->set('key1', $response, 3600);

        $this->assertTrue($cache->has('key1'));
    }

    /**
     * @test
     */
    public function testInMemoryCacheDelete() {
        $cache = new InMemoryCache();
        $response = new CachedResponse('data', 'chat');

        $cache->set('key1', $response, 3600);
        $this->assertTrue($cache->has('key1'));

        $cache->delete('key1');
        $this->assertFalse($cache->has('key1'));
    }

    /**
     * @test
     */
    public function testInMemoryCacheDeleteNonExistent() {
        $cache = new InMemoryCache();

        // Should not throw
        $cache->delete('nonexistent');
        $this->assertFalse($cache->has('nonexistent'));
    }

    /**
     * @test
     */
    public function testInMemoryCacheClear() {
        $cache = new InMemoryCache();

        $cache->set('key1', new CachedResponse('data1', 'chat'), 3600);
        $cache->set('key2', new CachedResponse('data2', 'chat'), 3600);

        $this->assertSame(2, $cache->count());

        $cache->clear();

        $this->assertSame(0, $cache->count());
        $this->assertFalse($cache->has('key1'));
        $this->assertFalse($cache->has('key2'));
    }

    /**
     * @test
     */
    public function testInMemoryCacheExpiration() {
        $cache = new InMemoryCache();
        $response = new CachedResponse('data', 'chat');

        // Set with 1 second TTL
        $cache->set('key1', $response, 1);

        $this->assertTrue($cache->has('key1'));

        // Wait for expiration
        sleep(2);

        $this->assertFalse($cache->has('key1'));
        $this->assertNull($cache->get('key1'));
    }

    /**
     * @test
     */
    public function testInMemoryCacheCount() {
        $cache = new InMemoryCache();

        $this->assertSame(0, $cache->count());

        $cache->set('key1', new CachedResponse('data', 'chat'), 3600);
        $this->assertSame(1, $cache->count());

        $cache->set('key2', new CachedResponse('data', 'chat'), 3600);
        $this->assertSame(2, $cache->count());
    }

    /**
     * @test
     */
    public function testInMemoryCacheOverwrite() {
        $cache = new InMemoryCache();

        $cache->set('key1', new CachedResponse('data1', 'chat'), 3600);
        $cache->set('key1', new CachedResponse('data2', 'chat'), 3600);

        $this->assertSame(1, $cache->count());
        $this->assertSame('data2', $cache->get('key1')->getData());
    }

    // =========================================================================
    // Provider Integration Tests
    // =========================================================================

    /**
     * @test
     */
    public function testProviderChatCacheHit() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new CacheConfig(
            enabled: true,
            skipCacheAboveTemperature: null // Cache all
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello!']]],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])));
        $client->setHttpClient($fakeHttp);

        $messages = [new Message('user', 'Hi')];

        // First call - should hit API
        $response1 = $client->chat($messages);
        $this->assertSame('Hello!', $response1->getMessage()->getContent());

        // Second call - should hit cache (no more HTTP responses queued)
        $response2 = $client->chat($messages);
        $this->assertSame('Hello!', $response2->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testProviderChatCacheMissOnDifferentMessages() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new CacheConfig(skipCacheAboveTemperature: null));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Response 1']]],
            'model' => 'gpt-4o',
        ])));
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Response 2']]],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        $response1 = $client->chat([new Message('user', 'Hello')]);
        $response2 = $client->chat([new Message('user', 'Goodbye')]);

        $this->assertSame('Response 1', $response1->getMessage()->getContent());
        $this->assertSame('Response 2', $response2->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testProviderChatSkipsCacheForHighTemperature() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new CacheConfig(skipCacheAboveTemperature: 0.0));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Response 1']]],
            'model' => 'gpt-4o',
        ])));
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Response 2']]],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        $messages = [new Message('user', 'Hi')];

        // Both calls should hit API since temperature > 0
        $response1 = $client->chat($messages, ['temperature' => 0.7]);
        $response2 = $client->chat($messages, ['temperature' => 0.7]);

        $this->assertSame('Response 1', $response1->getMessage()->getContent());
        $this->assertSame('Response 2', $response2->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testProviderChatCachesWithTemperatureZero() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new CacheConfig(skipCacheAboveTemperature: 0.0));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Cached!']]],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        $messages = [new Message('user', 'Hi')];

        $response1 = $client->chat($messages, ['temperature' => 0]);
        $response2 = $client->chat($messages, ['temperature' => 0]);

        $this->assertSame('Cached!', $response1->getMessage()->getContent());
        $this->assertSame('Cached!', $response2->getMessage()->getContent());
        $this->assertSame(1, $cache->count());
    }

    /**
     * @test
     */
    public function testProviderChatSkipsCacheWithAutoExecuteTools() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new CacheConfig(skipCacheAboveTemperature: null));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'No cache']]],
            'model' => 'gpt-4o',
        ])));
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Still no cache']]],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        $messages = [new Message('user', 'Hi')];

        // auto_execute_tools should skip cache
        $response1 = $client->chat($messages, ['auto_execute_tools' => true, 'tools' => []]);
        $response2 = $client->chat($messages, ['auto_execute_tools' => true, 'tools' => []]);

        $this->assertSame('No cache', $response1->getMessage()->getContent());
        $this->assertSame('Still no cache', $response2->getMessage()->getContent());
        $this->assertSame(0, $cache->count());
    }

    /**
     * @test
     */
    public function testProviderEmbeddingCacheHit() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 5],
        ])));
        $client->setHttpClient($fakeHttp);

        // First call - hits API
        $response1 = $client->embed('Hello');
        $this->assertSame([0.1, 0.2, 0.3], $response1->getVectors()[0]);

        // Second call - hits cache
        $response2 = $client->embed('Hello');
        $this->assertSame([0.1, 0.2, 0.3], $response2->getVectors()[0]);
    }

    /**
     * @test
     */
    public function testProviderCacheDisabled() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new CacheConfig(enabled: false));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Response 1']]],
            'model' => 'gpt-4o',
        ])));
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Response 2']]],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        $messages = [new Message('user', 'Hi')];

        $response1 = $client->chat($messages);
        $response2 = $client->chat($messages);

        $this->assertSame('Response 1', $response1->getMessage()->getContent());
        $this->assertSame('Response 2', $response2->getMessage()->getContent());
        $this->assertSame(0, $cache->count());
    }

    /**
     * @test
     */
    public function testProviderNoCacheSet() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        // No cache set

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Response']]],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        // Should work without errors
        $response = $client->chat([new Message('user', 'Hi')]);
        $this->assertSame('Response', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testSetCacheEnablesConfigAutomatically() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);

        $this->assertFalse($client->getCacheConfig()->isEnabled());

        $client->setCache(new InMemoryCache());

        $this->assertTrue($client->getCacheConfig()->isEnabled());
    }

    /**
     * @test
     */
    public function testSetCacheNullDisables() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $client->setCache(new InMemoryCache());
        $client->setCache(null);

        $this->assertNull($client->getCache());
    }

    /**
     * @test
     */
    public function testGetCacheConfig() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $config = new CacheConfig(defaultTtl: 9999);
        $client->setCacheConfig($config);

        $this->assertSame($config, $client->getCacheConfig());
        $this->assertSame(9999, $client->getCacheConfig()->getDefaultTtl());
    }

    /**
     * @test
     */
    public function testCacheUsesEmbeddingTtl() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new CacheConfig(
            defaultTtl: 100,
            embeddingTtl: 200
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'data' => [['embedding' => [0.1, 0.2]]],
            'model' => 'text-embedding-3-small',
        ])));
        $client->setHttpClient($fakeHttp);

        $client->embed('test');

        // Embedding should be cached with embedding TTL (we can't easily verify TTL,
        // but we verify it was cached)
        $this->assertSame(1, $cache->count());
    }

    /**
     * @test
     */
    public function testCacheInterface() {
        // Verify InMemoryCache implements CacheInterface
        $cache = new InMemoryCache();
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    /**
     * @test
     */
    public function testCacheDifferentTemperaturesAreDifferentKeys() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $cache = new InMemoryCache();
        $client->setCache($cache);
        $client->setCacheConfig(new CacheConfig(skipCacheAboveTemperature: null));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Temp 0']]],
            'model' => 'gpt-4o',
        ])));
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Temp 0.5']]],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        $messages = [new Message('user', 'Hi')];

        $response1 = $client->chat($messages, ['temperature' => 0]);
        $response2 = $client->chat($messages, ['temperature' => 0.5]);

        $this->assertSame('Temp 0', $response1->getMessage()->getContent());
        $this->assertSame('Temp 0.5', $response2->getMessage()->getContent());
        $this->assertSame(2, $cache->count());
    }
}
