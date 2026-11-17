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
use WebFiori\Ai\Provider\Bedrock\ApiMethod;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;

/**
 * Tests for cache read/write token reporting from Bedrock Converse and
 * InvokeModel (issue #201).
 *
 * Bedrock's input counters exclude cache reads and writes, so the prompt total
 * is the sum of the input, cache-read and cache-write counters.
 */
class BedrockCachedTokensTest extends TestCase {
    private const ANTHROPIC_MODEL = 'anthropic.claude-3-5-sonnet-20241022-v2:0';

    // =========================================================================
    // Converse: non-streaming
    // =========================================================================

    public function testConverseSumsCacheCountersIntoPromptTokens(): void {
        $usage = $this->converse([
            'inputTokens' => 30,
            'outputTokens' => 12,
            'totalTokens' => 2342,
            'cacheReadInputTokens' => 2000,
            'cacheWriteInputTokens' => 300,
        ])->getUsage();

        $this->assertSame(2330, $usage->getPromptTokens());
        $this->assertSame(2000, $usage->getCachedTokens());
        $this->assertSame(300, $usage->getCacheWriteTokens());
        $this->assertSame(12, $usage->getCompletionTokens());
    }

    public function testConverseWithoutCacheCountersIsUnchanged(): void {
        $usage = $this->converse(['inputTokens' => 12, 'outputTokens' => 5])->getUsage();

        $this->assertSame(12, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
    }

    public function testConverseCostPricesEachInputKind(): void {
        $client = $this->client(ApiMethod::CONVERSE);
        $client->setPricing(new PricingConfig([
            self::ANTHROPIC_MODEL => [
                'input' => 3.00, 'output' => 15.00, 'cached_input' => 0.30, 'cache_write' => 3.75,
            ],
        ]));
        $client->setHttpClient($this->fakeConverse([
            'inputTokens' => 100_000,
            'outputTokens' => 0,
            'cacheReadInputTokens' => 800_000,
            'cacheWriteInputTokens' => 100_000,
        ]));

        $cost = $client->chat([new Message('user', 'Hi')])->getCost();

        $this->assertNotNull($cost);
        $this->assertEqualsWithDelta(0.915, $cost->getInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.24, $cost->getCachedInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.375, $cost->getCacheWriteCost(), 1e-9);
    }

    // =========================================================================
    // Converse: streaming
    // =========================================================================

    public function testConverseStreamSumsCacheCountersFromMetadata(): void {
        $response = $this->stream(ApiMethod::CONVERSE, [
            $this->eventStreamMessage('messageStart', ['role' => 'assistant']),
            $this->eventStreamMessage('contentBlockDelta', ['delta' => ['text' => 'Hi']]),
            $this->eventStreamMessage('contentBlockStop', []),
            $this->eventStreamMessage('messageStop', ['stopReason' => 'end_turn']),
            $this->eventStreamMessage('metadata', ['usage' => [
                'inputTokens' => 25,
                'outputTokens' => 7,
                'cacheReadInputTokens' => 1800,
                'cacheWriteInputTokens' => 200,
            ]]),
        ]);

        $usage = $response->getUsage();
        $this->assertSame('Hi', $response->getMessage()->getContent());
        $this->assertSame(2025, $usage->getPromptTokens());
        $this->assertSame(1800, $usage->getCachedTokens());
        $this->assertSame(200, $usage->getCacheWriteTokens());
        $this->assertSame(7, $usage->getCompletionTokens());
    }

    public function testConverseStreamWithoutCacheCountersIsUnchanged(): void {
        $response = $this->stream(ApiMethod::CONVERSE, [
            $this->eventStreamMessage('contentBlockDelta', ['delta' => ['text' => 'Hi']]),
            $this->eventStreamMessage('messageStop', ['stopReason' => 'end_turn']),
            $this->eventStreamMessage('metadata', ['usage' => ['inputTokens' => 5, 'outputTokens' => 2]]),
        ]);

        $this->assertSame(5, $response->getUsage()->getPromptTokens());
        $this->assertSame(0, $response->getUsage()->getCachedTokens());
        $this->assertSame(0, $response->getUsage()->getCacheWriteTokens());
    }

    // =========================================================================
    // InvokeModel (Anthropic family)
    // =========================================================================

    public function testInvokeSumsCacheCountersIntoPromptTokens(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 20,
                'cache_read_input_tokens' => 1500,
                'cache_creation_input_tokens' => 100,
                'output_tokens' => 9,
            ],
        ])));
        $client = $this->client(ApiMethod::INVOKE);
        $client->setHttpClient($http);

        $usage = $client->chat([new Message('user', 'Hi')])->getUsage();

        $this->assertSame(1620, $usage->getPromptTokens());
        $this->assertSame(1500, $usage->getCachedTokens());
        $this->assertSame(100, $usage->getCacheWriteTokens());
        $this->assertSame(9, $usage->getCompletionTokens());
    }

    public function testInvokeWithoutCacheCountersIsUnchanged(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 4, 'output_tokens' => 3],
        ])));
        $client = $this->client(ApiMethod::INVOKE);
        $client->setHttpClient($http);

        $usage = $client->chat([new Message('user', 'Hi')])->getUsage();

        $this->assertSame(4, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCachedTokens());
    }

    public function testInvokeStreamSumsCacheCountersFromMessageStart(): void {
        $response = $this->stream(ApiMethod::INVOKE, [
            $this->invokeEvent(['type' => 'message_start', 'message' => ['usage' => [
                'input_tokens' => 15,
                'cache_read_input_tokens' => 900,
                'cache_creation_input_tokens' => 85,
            ]]]),
            $this->invokeEvent(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]),
            $this->invokeEvent(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 4]]),
        ]);

        $usage = $response->getUsage();
        $this->assertSame('Hi', $response->getMessage()->getContent());
        $this->assertSame(1000, $usage->getPromptTokens());
        $this->assertSame(900, $usage->getCachedTokens());
        $this->assertSame(85, $usage->getCacheWriteTokens());
        $this->assertSame(4, $usage->getCompletionTokens());
    }

    public function testInvokeStreamWithoutCacheCountersIsUnchanged(): void {
        $response = $this->stream(ApiMethod::INVOKE, [
            $this->invokeEvent(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5]]]),
            $this->invokeEvent(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]),
            $this->invokeEvent(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]]),
        ]);

        $this->assertSame(5, $response->getUsage()->getPromptTokens());
        $this->assertSame(0, $response->getUsage()->getCachedTokens());
        $this->assertSame(0, $response->getUsage()->getCacheWriteTokens());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function client(ApiMethod $apiMethod): BedrockClient {
        return new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: self::ANTHROPIC_MODEL,
            apiKey: 'test-bedrock-key',
            apiMethod: $apiMethod,
        ));
    }

    private function converse(array $usage): ChatResponse {
        $client = $this->client(ApiMethod::CONVERSE);
        $client->setHttpClient($this->fakeConverse($usage));

        return $client->chat([new Message('user', 'Hi')]);
    }

    /**
     * Builds a binary AWS event stream message.
     */
    private function eventStreamMessage(string $eventType, array $payload): string {
        $payloadBytes = json_encode($payload);
        $headerName = ':event-type';
        $headers = chr(strlen($headerName)).$headerName;
        $headers .= chr(7);
        $headers .= pack('n', strlen($eventType)).$eventType;

        $totalLength = 4 + 4 + 4 + strlen($headers) + strlen($payloadBytes) + 4;
        $prelude = pack('N', $totalLength).pack('N', strlen($headers));
        $body = $prelude.pack('N', crc32($prelude)).$headers.$payloadBytes;

        return $body.pack('N', crc32($body));
    }

    private function fakeConverse(array $usage): FakeHttpClient {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hello']]]],
            'stopReason' => 'end_turn',
            'usage' => $usage,
        ])));

        return $http;
    }

    private function invokeEvent(array $chunk): string {
        return 'data: '.json_encode(['bytes' => base64_encode(json_encode($chunk))])."\n\n";
    }

    private function stream(ApiMethod $apiMethod, array $chunks): ChatResponse {
        $http = new FakeHttpClient();
        $http->addStreamingChunks($chunks);
        $client = $this->client($apiMethod);
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
