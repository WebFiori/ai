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
use WebFiori\Ai\Conversation\Conversation;
use WebFiori\Ai\Conversation\InMemoryStorage;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;
use WebFiori\Ai\Usage;

/**
 * Unit tests for Conversation and InMemoryStorage.
 *
 * @author Ibrahim
 */
class ConversationTest extends TestCase {
    /**
     * @test
     */
    public function testConversationAutoGeneratesId() {
        $provider = $this->createProvider();
        $storage = new InMemoryStorage();
        $conversation = new Conversation($provider, $storage);

        $this->assertNotEmpty($conversation->getId());
        $this->assertStringStartsWith('conv_', $conversation->getId());
    }

    /**
     * @test
     */
    public function testConversationHistoryMaintained() {
        $client = new FakeHttpClient();
        $this->queueResponse($client, 'PHP is a language.');
        $this->queueResponse($client, 'It has a dynamic type system.');

        $provider = $this->createProvider($client);
        $storage = new InMemoryStorage();
        $conversation = new Conversation($provider, $storage, 'test-conv');
        $conversation->setSystemMessage('You are helpful.');

        // First message
        $response1 = $conversation->send('What is PHP?');
        $this->assertEquals('PHP is a language.', $response1->getMessage()->getContent());

        // Second message — history should include first exchange
        $response2 = $conversation->send('Tell me about its type system.');
        $this->assertEquals('It has a dynamic type system.', $response2->getMessage()->getContent());

        // Verify history has 4 messages (user, assistant, user, assistant)
        $history = $conversation->getHistory();
        $this->assertCount(4, $history);
        $this->assertEquals('user', $history[0]->getRole());
        $this->assertEquals('What is PHP?', $history[0]->getContent());
        $this->assertEquals('assistant', $history[1]->getRole());
        $this->assertEquals('PHP is a language.', $history[1]->getContent());
        $this->assertEquals('user', $history[2]->getRole());
        $this->assertEquals('assistant', $history[3]->getRole());

        // Verify the second request included full history
        $requests = $client->getRequests();
        $secondBody = json_decode($requests[1]->getBody(), true);
        // system + user + assistant + user = 4 messages in the request
        $this->assertCount(4, $secondBody['messages']);
        $this->assertEquals('system', $secondBody['messages'][0]['role']);
        $this->assertEquals('user', $secondBody['messages'][1]['role']);
        $this->assertEquals('assistant', $secondBody['messages'][2]['role']);
        $this->assertEquals('user', $secondBody['messages'][3]['role']);
    }

    /**
     * @test
     */
    public function testConversationMaxHistory() {
        $client = new FakeHttpClient();
        $this->queueResponse($client, 'Response 1');
        $this->queueResponse($client, 'Response 2');
        $this->queueResponse($client, 'Response 3');

        $provider = $this->createProvider($client);
        $storage = new InMemoryStorage();
        $conversation = new Conversation($provider, $storage, 'test-conv');
        $conversation->setMaxHistory(4); // Keep last 4 messages only

        $conversation->send('Message 1');
        $conversation->send('Message 2');
        $conversation->send('Message 3');

        // Without limit: 6 messages (3 user + 3 assistant)
        // With limit of 4: last 4 messages kept
        $history = $conversation->getHistory();
        $this->assertCount(4, $history);
        // Oldest messages trimmed, last 4 remain:
        // user "Message 2", assistant "Response 2", user "Message 3", assistant "Response 3"
        $this->assertEquals('user', $history[0]->getRole());
        $this->assertEquals('Message 2', $history[0]->getContent());
        $this->assertEquals('assistant', $history[1]->getRole());
        $this->assertEquals('Response 2', $history[1]->getContent());
        $this->assertEquals('user', $history[2]->getRole());
        $this->assertEquals('Message 3', $history[2]->getContent());
        $this->assertEquals('assistant', $history[3]->getRole());
        $this->assertEquals('Response 3', $history[3]->getContent());
    }

    /**
     * @test
     */
    public function testConversationSend() {
        $client = new FakeHttpClient();
        $this->queueResponse($client, 'Hello! How can I help you?');

        $provider = $this->createProvider($client);
        $storage = new InMemoryStorage();
        $conversation = new Conversation($provider, $storage, 'conv-1');
        $conversation->setSystemMessage('You are a helpful assistant.');

        $response = $conversation->send('Hello');

        $this->assertEquals('Hello! How can I help you?', $response->getMessage()->getContent());

        // Verify storage was updated
        $this->assertTrue($storage->exists('conv-1'));
        $messages = $storage->load('conv-1');
        $this->assertCount(2, $messages); // user + assistant
        $this->assertEquals('user', $messages[0]->getRole());
        $this->assertEquals('Hello', $messages[0]->getContent());
        $this->assertEquals('assistant', $messages[1]->getRole());

        // Verify request included system message
        $requestBody = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals('system', $requestBody['messages'][0]['role']);
        $this->assertEquals('You are a helpful assistant.', $requestBody['messages'][0]['content']);
    }

    /**
     * @test
     */
    public function testConversationSetters() {
        $provider = $this->createProvider();
        $storage = new InMemoryStorage();
        $conversation = new Conversation($provider, $storage);

        $conversation->setId('my-conv');
        $this->assertEquals('my-conv', $conversation->getId());

        $conversation->setSystemMessage('Be concise.');
        $this->assertEquals('Be concise.', $conversation->getSystemMessage());

        $conversation->setMaxHistory(10);
        $this->assertEquals(10, $conversation->getMaxHistory());

        $this->assertSame($provider, $conversation->getProvider());
        $this->assertSame($storage, $conversation->getStorage());
    }

    /**
     * @test
     */
    public function testConversationWithoutSystemMessage() {
        $client = new FakeHttpClient();
        $this->queueResponse($client, 'Hi');

        $provider = $this->createProvider($client);
        $storage = new InMemoryStorage();
        $conversation = new Conversation($provider, $storage, 'conv-2');

        $conversation->send('Hello');

        // No system message in request
        $requestBody = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertCount(1, $requestBody['messages']);
        $this->assertEquals('user', $requestBody['messages'][0]['role']);
    }

    /**
     * @test
     */
    public function testInMemoryStorageDelete() {
        $storage = new InMemoryStorage();
        $storage->save('conv-1', [new Message('user', 'Hi')]);

        $this->assertTrue($storage->delete('conv-1'));
        $this->assertFalse($storage->exists('conv-1'));
        $this->assertFalse($storage->delete('conv-1')); // Already deleted
    }

    /**
     * @test
     */
    public function testInMemoryStorageExists() {
        $storage = new InMemoryStorage();

        $this->assertFalse($storage->exists('conv-1'));

        $storage->save('conv-1', []);
        $this->assertTrue($storage->exists('conv-1'));
    }

    /**
     * @test
     */
    public function testInMemoryStorageList() {
        $storage = new InMemoryStorage();
        $storage->save('conv-1', [new Message('user', 'Hi')]);
        $storage->save('conv-2', [new Message('user', 'Hello')]);

        $list = $storage->listConversations();
        $this->assertCount(2, $list);
        $this->assertContains('conv-1', $list);
        $this->assertContains('conv-2', $list);
    }

    /**
     * @test
     */
    public function testInMemoryStorageLoadEmpty() {
        $storage = new InMemoryStorage();

        $messages = $storage->load('nonexistent');
        $this->assertEmpty($messages);
    }

    /**
     * @test
     */
    public function testInMemoryStorageSaveAndLoad() {
        $storage = new InMemoryStorage();
        $messages = [
            new Message('user', 'Hello'),
            new Message('assistant', 'Hi there!'),
        ];

        $storage->save('conv-1', $messages);
        $loaded = $storage->load('conv-1');

        $this->assertCount(2, $loaded);
        $this->assertEquals('Hello', $loaded[0]->getContent());
        $this->assertEquals('Hi there!', $loaded[1]->getContent());
    }

    /**
     * @test
     */
    public function testInMemoryStorageSaveOverwrites() {
        $storage = new InMemoryStorage();
        $storage->save('conv-1', [new Message('user', 'First')]);
        $storage->save('conv-1', [new Message('user', 'Second')]);

        $loaded = $storage->load('conv-1');
        $this->assertCount(1, $loaded);
        $this->assertEquals('Second', $loaded[0]->getContent());
    }

    /**
     * Creates an OpenAI provider for testing.
     *
     * @param FakeHttpClient|null $client The fake HTTP client to use.
     *
     * @return OpenAIProvider The configured provider.
     */
    private function createProvider(?FakeHttpClient $client = null): OpenAIProvider {
        $provider = new OpenAIProvider([
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ]);

        if ($client !== null) {
            $provider->setHttpClient($client);
        }

        return $provider;
    }

    /**
     * Queues a standard chat response on the fake client.
     *
     * @param FakeHttpClient $client The fake client.
     * @param string $content The assistant message content.
     */
    private function queueResponse(FakeHttpClient $client, string $content): void {
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])));
    }
}
