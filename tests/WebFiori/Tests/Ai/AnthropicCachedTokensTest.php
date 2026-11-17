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
use WebFiori\Ai\PricingConfig;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\Formatter\AnthropicFormatter;

/**
 * Tests for cache read/write token reporting from Anthropic (issue #200).
 *
 * Anthropic's input_tokens excludes cache reads and writes, so the prompt
 * total is input_tokens + cache_read_input_tokens + cache_creation_input_tokens.
 */
class AnthropicCachedTokensTest extends TestCase {
    // =========================================================================
    // Non-streaming
    // =========================================================================

    public function testChatSumsCacheCountersIntoPromptTokens(): void {
        $usage = $this->chat([
            'input_tokens' => 50,
            'cache_read_input_tokens' => 2000,
            'cache_creation_input_tokens' => 300,
            'output_tokens' => 80,
        ])->getUsage();

        $this->assertSame(2350, $usage->getPromptTokens());
        $this->assertSame(2000, $usage->getCachedTokens());
        $this->assertSame(300, $usage->getCacheWriteTokens());
        $this->assertSame(80, $usage->getCompletionTokens());
    }

    public function testChatWithoutCacheCountersIsUnchanged(): void {
        $usage = $this->chat(['input_tokens' => 10, 'output_tokens' => 5])->getUsage();

        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
        $this->assertSame(15, $usage->getTotalTokens());
    }

    public function testChatWithOnlyCacheRead(): void {
        $usage = $this->chat([
            'input_tokens' => 4,
            'cache_read_input_tokens' => 1000,
            'output_tokens' => 1,
        ])->getUsage();

        $this->assertSame(1004, $usage->getPromptTokens());
        $this->assertSame(1000, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
    }

    public function testChatCostPricesEachInputKind(): void {
        $client = $this->createClient();
        $client->setPricing(new PricingConfig([
            'claude-sonnet-4-20250514' => [
                'input' => 3.00, 'output' => 15.00, 'cached_input' => 0.30, 'cache_write' => 3.75,
            ],
        ]));
        $client->setHttpClient($this->fakeChat([
            'input_tokens' => 100_000,
            'cache_read_input_tokens' => 800_000,
            'cache_creation_input_tokens' => 100_000,
            'output_tokens' => 0,
        ]));

        $cost = $client->chat([new Message('user', 'Hi')])->getCost();

        // 100k * 3.00 + 800k * 0.30 + 100k * 3.75 = 0.3 + 0.24 + 0.375
        $this->assertNotNull($cost);
        $this->assertEqualsWithDelta(0.915, $cost->getInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.24, $cost->getCachedInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.375, $cost->getCacheWriteCost(), 1e-9);
    }

    public function testBuildUsageHandlesMissingKeys(): void {
        $usage = AnthropicFormatter::buildUsage([]);

        $this->assertSame(0, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCompletionTokens());
        $this->assertSame(0, $usage->getCachedTokens());
    }

    // =========================================================================
    // Streaming
    // =========================================================================

    public function testStreamSumsCacheCountersFromMessageStart(): void {
        $response = $this->stream([
            $this->event('message_start', ['message' => [
                'id' => 'msg_1',
                'model' => 'claude-sonnet-4-20250514',
                'usage' => [
                    'input_tokens' => 25,
                    'cache_creation_input_tokens' => 200,
                    'cache_read_input_tokens' => 1800,
                    'output_tokens' => 1,
                ],
            ]]),
            $this->event('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
            $this->event('content_block_delta', ['delta' => ['type' => 'text_delta', 'text' => 'Hi']]),
            $this->event('content_block_stop', ['index' => 0]),
            $this->event('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 7]]),
            "data: [DONE]\n\n",
        ]);

        $usage = $response->getUsage();
        $this->assertSame('Hi', $response->getMessage()->getContent());
        $this->assertSame(2025, $usage->getPromptTokens());
        $this->assertSame(1800, $usage->getCachedTokens());
        $this->assertSame(200, $usage->getCacheWriteTokens());
        $this->assertSame(7, $usage->getCompletionTokens());
    }

    public function testStreamWithoutCacheCountersIsUnchanged(): void {
        $response = $this->stream([
            $this->event('message_start', ['message' => ['model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 10]]]),
            $this->event('content_block_delta', ['delta' => ['type' => 'text_delta', 'text' => 'Hi']]),
            $this->event('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]),
        ]);

        $usage = $response->getUsage();
        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
        $this->assertSame(5, $usage->getCompletionTokens());
    }

    public function testStreamMessageDeltaWithoutInputKeysDoesNotResetCounters(): void {
        $response = $this->stream([
            $this->event('message_start', ['message' => ['model' => 'm', 'usage' => [
                'input_tokens' => 5, 'cache_read_input_tokens' => 500,
            ]]]),
            $this->event('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]]),
        ]);

        $this->assertSame(505, $response->getUsage()->getPromptTokens());
        $this->assertSame(500, $response->getUsage()->getCachedTokens());
    }

    public function testStreamMessageDeltaCumulativeInputCountersWin(): void {
        $response = $this->stream([
            $this->event('message_start', ['message' => ['model' => 'm', 'usage' => [
                'input_tokens' => 5, 'cache_read_input_tokens' => 500,
            ]]]),
            $this->event('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => [
                'input_tokens' => 9, 'cache_read_input_tokens' => 600, 'output_tokens' => 3,
            ]]),
        ]);

        $this->assertSame(609, $response->getUsage()->getPromptTokens());
        $this->assertSame(600, $response->getUsage()->getCachedTokens());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function chat(array $usage): ChatResponse {
        $client = $this->createClient();
        $client->setHttpClient($this->fakeChat($usage));

        return $client->chat([new Message('user', 'Hi')]);
    }

    private function createClient(): AnthropicClient {
        return new AnthropicClient(new AnthropicClientConfig(apiKey: 'test-api-key', model: 'claude-sonnet-4-20250514'));
    }

    private function event(string $type, array $payload): string {
        return 'data: '.json_encode(['type' => $type] + $payload)."\n\n";
    }

    private function fakeChat(array $usage): FakeHttpClient {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hello']],
            'stop_reason' => 'end_turn',
            'usage' => $usage,
        ])));

        return $http;
    }

    private function stream(array $chunks): ChatResponse {
        $http = new FakeHttpClient();
        $http->addStreamingChunks($chunks);
        $client = $this->createClient();
        $client->setHttpClient($http);

        $completed = null;
        $client->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {},
            function ($response) use (&$completed) {
                $completed = $response;
            }
        );

        $this->assertNotNull($completed, 'onComplete was not called');

        return $completed;
    }
}
