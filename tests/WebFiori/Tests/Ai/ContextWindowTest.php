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
use WebFiori\Ai\Context\ContextWindowStrategyInterface;
use WebFiori\Ai\Context\NoTruncationStrategy;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Context\TokenEstimator;
use WebFiori\Ai\Exception\ContextOverflowException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for token counting and context window management.
 */
class ContextWindowTest extends TestCase {
    // =========================================================================
    // TokenEstimator Tests
    // =========================================================================

    /**
     * @test
     */
    public function testTokenEstimatorCountText() {
        $estimator = new TokenEstimator();

        // Empty string = 0 tokens
        $this->assertSame(0, $estimator->countText(''));

        // ~4 chars per token
        $this->assertSame(1, $estimator->countText('Hi'));      // 2 chars
        $this->assertSame(1, $estimator->countText('test'));    // 4 chars
        $this->assertSame(3, $estimator->countText('Hello World'));  // 11 chars
    }

    /**
     * @test
     */
    public function testTokenEstimatorCountMessage() {
        $estimator = new TokenEstimator();

        $message = new Message('user', 'Hello');
        $tokens = $estimator->countMessage($message);

        // Should include overhead + role + content
        $this->assertGreaterThan(0, $tokens);
    }

    /**
     * @test
     */
    public function testTokenEstimatorCountMessages() {
        $estimator = new TokenEstimator();

        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hi'),
        ];

        $tokens = $estimator->countMessages($messages);

        // Should be sum of individual messages + request overhead
        $this->assertGreaterThan(0, $tokens);
    }

    /**
     * @test
     */
    public function testTokenEstimatorCountTool() {
        $estimator = new TokenEstimator();

        $tool = new Tool(
            'get_weather',
            'Gets the weather for a location',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string'],
                ],
            ],
            fn($args) => 'Sunny'
        );

        $tokens = $estimator->countTool($tool);

        // Should include name + description + parameters
        $this->assertGreaterThan(0, $tokens);
    }

    /**
     * @test
     */
    public function testTokenEstimatorCountTools() {
        $estimator = new TokenEstimator();

        $tools = [
            new Tool('tool1', 'Description 1', ['type' => 'object'], fn($a) => ''),
            new Tool('tool2', 'Description 2', ['type' => 'object'], fn($a) => ''),
        ];

        $tokens = $estimator->countTools($tools);

        $this->assertGreaterThan(0, $tokens);
    }

    /**
     * @test
     */
    public function testTokenEstimatorCountCombined() {
        $estimator = new TokenEstimator();

        $messages = [new Message('user', 'Hello')];
        $tools = [new Tool('tool1', 'Desc', ['type' => 'object'], fn($a) => '')];

        $combined = $estimator->count($messages, $tools);
        $messagesOnly = $estimator->countMessages($messages);
        $toolsOnly = $estimator->countTools($tools);

        $this->assertSame($messagesOnly + $toolsOnly, $combined);
    }

    /**
     * @test
     */
    public function testTokenEstimatorEmptyMessages() {
        $estimator = new TokenEstimator();

        // Empty array should just have request overhead
        $tokens = $estimator->countMessages([]);

        $this->assertSame(3, $tokens); // REQUEST_OVERHEAD
    }

    /**
     * @test
     */
    public function testTokenEstimatorEmptyTools() {
        $estimator = new TokenEstimator();

        $tokens = $estimator->countTools([]);

        $this->assertSame(0, $tokens);
    }

    // =========================================================================
    // SlidingWindowStrategy Tests
    // =========================================================================

    /**
     * @test
     */
    public function testSlidingWindowStrategyNoTruncationNeeded() {
        $strategy = new SlidingWindowStrategy(maxTokens: 10000);

        $messages = [
            new Message('system', 'Be helpful.'),
            new Message('user', 'Hi'),
        ];

        $result = $strategy->truncate($messages, 10000);

        $this->assertCount(2, $result);
    }

    /**
     * @test
     */
    public function testSlidingWindowStrategyTruncatesOldestFirst() {
        $strategy = new SlidingWindowStrategy(
            maxTokens: 80,
            reserveForCompletion: 20,
            preserveSystemMessage: true
        );

        // Create messages that will definitely exceed 60 tokens (80 - 20 reserved)
        $messages = [
            new Message('system', 'System message'),
            new Message('user', str_repeat('First user message content ', 5)),
            new Message('assistant', str_repeat('First assistant response content ', 5)),
            new Message('user', 'Recent'),
        ];

        $result = $strategy->truncate($messages, 80);

        // Should have removed some messages
        $this->assertLessThan(4, count($result));

        // System message should always be first if preserved
        $this->assertSame('system', $result[0]->getRole());
    }

    /**
     * @test
     */
    public function testSlidingWindowStrategyPreservesSystemMessage() {
        $strategy = new SlidingWindowStrategy(
            maxTokens: 50,
            reserveForCompletion: 10,
            preserveSystemMessage: true
        );

        $messages = [
            new Message('system', 'Important system instructions'),
            new Message('user', 'User message'),
        ];

        $result = $strategy->truncate($messages, 50);

        // System message should be preserved even if tight on space
        $hasSystem = false;

        foreach ($result as $msg) {
            if ($msg->getRole() === 'system') {
                $hasSystem = true;

                break;
            }
        }

        $this->assertTrue($hasSystem);
    }

    /**
     * @test
     */
    public function testSlidingWindowStrategyCanDropSystemMessage() {
        $strategy = new SlidingWindowStrategy(
            maxTokens: 100,
            reserveForCompletion: 20,
            preserveSystemMessage: false
        );

        $messages = [
            new Message('system', 'System'),
            new Message('user', 'User'),
        ];

        // With preserveSystemMessage: false, system can be dropped
        $this->assertFalse($strategy->isPreserveSystemMessage());
    }

    /**
     * @test
     */
    public function testSlidingWindowStrategyGetters() {
        $strategy = new SlidingWindowStrategy(
            maxTokens: 128000,
            reserveForCompletion: 4096,
            preserveSystemMessage: true
        );

        $this->assertSame(128000, $strategy->getMaxTokens());
        $this->assertSame(4096, $strategy->getReservedTokens());
        $this->assertTrue($strategy->isPreserveSystemMessage());
    }

    /**
     * @test
     */
    public function testSlidingWindowStrategyWithTools() {
        $strategy = new SlidingWindowStrategy(
            maxTokens: 200,
            reserveForCompletion: 50
        );

        $messages = [
            new Message('user', 'Call the tool'),
        ];

        $tools = [
            new Tool('big_tool', str_repeat('x', 100), ['type' => 'object'], fn($a) => ''),
        ];

        // Tools consume tokens, leaving less for messages
        $result = $strategy->truncate($messages, 200, $tools);

        $this->assertIsArray($result);
    }

    /**
     * @test
     */
    public function testSlidingWindowStrategyImplementsInterface() {
        $strategy = new SlidingWindowStrategy(maxTokens: 1000);

        $this->assertInstanceOf(ContextWindowStrategyInterface::class, $strategy);
    }

    // =========================================================================
    // NoTruncationStrategy Tests
    // =========================================================================

    /**
     * @test
     */
    public function testNoTruncationStrategyPassesWhenUnderLimit() {
        $strategy = new NoTruncationStrategy(maxTokens: 10000);

        $messages = [
            new Message('user', 'Short message'),
        ];

        $result = $strategy->truncate($messages, 10000);

        $this->assertCount(1, $result);
        $this->assertSame($messages, $result);
    }

    /**
     * @test
     */
    public function testNoTruncationStrategyThrowsWhenOverLimit() {
        $strategy = new NoTruncationStrategy(maxTokens: 10, reserveForCompletion: 0);

        $messages = [
            new Message('user', str_repeat('x', 1000)), // Way over 10 tokens
        ];

        $this->expectException(ContextOverflowException::class);

        $strategy->truncate($messages, 10);
    }

    /**
     * @test
     */
    public function testNoTruncationStrategyGetters() {
        $strategy = new NoTruncationStrategy(maxTokens: 5000, reserveForCompletion: 1000);

        $this->assertSame(5000, $strategy->getMaxTokens());
        $this->assertSame(1000, $strategy->getReservedTokens());
    }

    /**
     * @test
     */
    public function testNoTruncationStrategyImplementsInterface() {
        $strategy = new NoTruncationStrategy(maxTokens: 1000);

        $this->assertInstanceOf(ContextWindowStrategyInterface::class, $strategy);
    }

    // =========================================================================
    // ContextOverflowException Tests
    // =========================================================================

    /**
     * @test
     */
    public function testContextOverflowExceptionProperties() {
        $exception = new ContextOverflowException(
            'Overflow!',
            1500,
            1000
        );

        $this->assertSame('Overflow!', $exception->getMessage());
        $this->assertSame(1500, $exception->getRequiredTokens());
        $this->assertSame(1000, $exception->getAvailableTokens());
        $this->assertSame(500, $exception->getOverflowTokens());
    }

    // =========================================================================
    // Provider Integration Tests
    // =========================================================================

    /**
     * @test
     */
    public function testProviderCountTokens() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));

        $messages = [
            new Message('user', 'Hello world'),
        ];

        $count = $provider->countTokens($messages);

        $this->assertGreaterThan(0, $count);
    }

    /**
     * @test
     */
    public function testProviderCountTokensWithTools() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));

        $messages = [new Message('user', 'Hi')];
        $tools = [new Tool('test', 'Description', ['type' => 'object'], fn($a) => '')];

        $withTools = $provider->countTokens($messages, $tools);
        $withoutTools = $provider->countTokens($messages);

        $this->assertGreaterThan($withoutTools, $withTools);
    }

    /**
     * @test
     */
    public function testProviderGetRemainingTokensWithoutStrategy() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));

        $remaining = $provider->getRemainingTokens([new Message('user', 'Hi')]);

        $this->assertNull($remaining);
    }

    /**
     * @test
     */
    public function testProviderGetRemainingTokensWithStrategy() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 1000,
            reserveForCompletion: 100
        ));

        $messages = [new Message('user', 'Hi')];
        $remaining = $provider->getRemainingTokens($messages);

        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThan(900, $remaining); // 1000 - 100 - some tokens for message
    }

    /**
     * @test
     */
    public function testProviderSetAndGetContextWindowStrategy() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));

        $this->assertNull($provider->getContextWindowStrategy());

        $strategy = new SlidingWindowStrategy(maxTokens: 128000);
        $provider->setContextWindowStrategy($strategy);

        $this->assertSame($strategy, $provider->getContextWindowStrategy());

        $provider->setContextWindowStrategy(null);

        $this->assertNull($provider->getContextWindowStrategy());
    }

    /**
     * @test
     */
    public function testProviderChatWithSlidingWindowStrategy() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 100,
            reserveForCompletion: 20
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello!']]],
            'model' => 'gpt-4o',
        ])));
        $provider->setHttpClient($fakeHttp);

        // Create messages that would exceed limit
        $messages = [
            new Message('system', 'Be helpful'),
            new Message('user', str_repeat('old message ', 10)),
            new Message('assistant', str_repeat('old response ', 10)),
            new Message('user', 'Recent question'),
        ];

        // Should not throw - messages will be truncated
        $response = $provider->chat($messages);

        $this->assertSame('Hello!', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testProviderChatWithNoTruncationStrategyThrows() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        $provider->setContextWindowStrategy(new NoTruncationStrategy(
            maxTokens: 20,
            reserveForCompletion: 5
        ));

        $fakeHttp = new FakeHttpClient();
        $provider->setHttpClient($fakeHttp);

        $messages = [
            new Message('user', str_repeat('This is a very long message ', 50)),
        ];

        $this->expectException(ContextOverflowException::class);

        $provider->chat($messages);
    }

    /**
     * @test
     */
    public function testProviderChatWithoutStrategyPassesAllMessages() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        // No strategy set

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
            'model' => 'gpt-4o',
        ])));
        $provider->setHttpClient($fakeHttp);

        $messages = [
            new Message('user', 'Message 1'),
            new Message('assistant', 'Response 1'),
            new Message('user', 'Message 2'),
        ];

        $response = $provider->chat($messages);

        $this->assertSame('OK', $response->getMessage()->getContent());

        // Verify all messages were sent (check the request body)
        $request = $fakeHttp->getLastRequest();
        $body = json_decode($request->getBody(), true);

        $this->assertCount(3, $body['messages']);
    }

    /**
     * @test
     */
    public function testProviderStreamChatWithStrategy() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 100,
            reserveForCompletion: 20
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n",
            "data: [DONE]\n\n",
        ]);
        $provider->setHttpClient($fakeHttp);

        $messages = [
            new Message('user', str_repeat('long ', 20)),
        ];

        $tokens = [];
        $provider->streamChat($messages, function ($token) use (&$tokens) {
            $tokens[] = $token;
        });

        $this->assertContains('Hi', $tokens);
    }

    /**
     * @test
     */
    public function testTruncationLogsWarning() {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 50,
            reserveForCompletion: 10
        ));

        $logged = [];
        $provider->setLogCallback(function ($level, $message, $context) use (&$logged) {
            $logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]],
            'model' => 'gpt-4o',
        ])));
        $provider->setHttpClient($fakeHttp);

        $messages = [
            new Message('system', 'Be helpful'),
            new Message('user', str_repeat('old ', 30)),
            new Message('assistant', str_repeat('response ', 30)),
            new Message('user', 'new'),
        ];

        $provider->chat($messages);

        // Should have logged a warning about truncation
        $warningFound = false;

        foreach ($logged as $log) {
            if ($log['level'] === 'warning' && str_contains($log['message'], 'truncation')) {
                $warningFound = true;

                break;
            }
        }

        $this->assertTrue($warningFound, 'Expected a warning log about truncation');
    }
}
