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
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Tests for cached token reporting from OpenAI Chat Completions (issue #199).
 */
class OpenAICachedTokensTest extends TestCase {
    public function testChatReportsCachedTokens(): void {
        $response = $this->chatWithUsage([
            'prompt_tokens' => 2006,
            'completion_tokens' => 300,
            'total_tokens' => 2306,
            'prompt_tokens_details' => ['cached_tokens' => 1920],
        ]);

        $usage = $response->getUsage();
        $this->assertSame(2006, $usage->getPromptTokens());
        $this->assertSame(300, $usage->getCompletionTokens());
        $this->assertSame(1920, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
    }

    public function testChatWithoutPromptTokensDetailsReportsZeroCached(): void {
        $usage = $this->chatWithUsage(['prompt_tokens' => 10, 'completion_tokens' => 5])->getUsage();

        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCachedTokens());
    }

    public function testChatWithNullPromptTokensDetailsReportsZeroCached(): void {
        $usage = $this->chatWithUsage([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'prompt_tokens_details' => null,
        ])->getUsage();

        $this->assertSame(0, $usage->getCachedTokens());
    }

    public function testChatReportsCacheWritesWhenProviderSendsThem(): void {
        $usage = $this->chatWithUsage([
            'prompt_tokens' => 1000,
            'completion_tokens' => 5,
            'prompt_tokens_details' => ['cached_tokens' => 200, 'cache_write_tokens' => 300],
        ])->getUsage();

        $this->assertSame(200, $usage->getCachedTokens());
        $this->assertSame(300, $usage->getCacheWriteTokens());
    }

    public function testChatClampsCachedTokensAbovePrompt(): void {
        $usage = $this->chatWithUsage([
            'prompt_tokens' => 100,
            'completion_tokens' => 5,
            'prompt_tokens_details' => ['cached_tokens' => 500],
        ])->getUsage();

        $this->assertSame(100, $usage->getPromptTokens());
        $this->assertSame(100, $usage->getCachedTokens());
    }

    public function testCostUsesCachedInputPrice(): void {
        $client = $this->createClient();
        $client->setPricing(new PricingConfig([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'cached_input' => 1.25],
        ]));
        $client->setHttpClient($this->fakeChat([
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 100_000,
            'prompt_tokens_details' => ['cached_tokens' => 800_000],
        ]));

        $cost = $client->chat([new Message('user', 'Hi')])->getCost();

        // 200k * 2.50 + 800k * 1.25 = 0.5 + 1.0
        $this->assertEqualsWithDelta(1.5, $cost->getInputCost(), 1e-9);
        $this->assertEqualsWithDelta(1.0, $cost->getCachedInputCost(), 1e-9);
        $this->assertEqualsWithDelta(1.0, $cost->getOutputCost(), 1e-9);
    }

    public function testCostIsUnchangedWhenNoCachedPriceConfigured(): void {
        $client = $this->createClient();
        $client->setPricing(new PricingConfig(['gpt-4o' => ['input' => 2.50, 'output' => 10.00]]));
        $client->setHttpClient($this->fakeChat([
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 0,
            'prompt_tokens_details' => ['cached_tokens' => 800_000],
        ]));

        $cost = $client->chat([new Message('user', 'Hi')])->getCost();

        $this->assertEqualsWithDelta(2.5, $cost->getInputCost(), 1e-9);
    }

    // =========================================================================
    // Audit and metrics (ADR-0062)
    // =========================================================================

    public function testAuditEntryCarriesCachedTokens(): void {
        $client = $this->createClient();
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) {
            $entry = $e;
        });
        $client->setHttpClient($this->fakeChat([
            'prompt_tokens' => 2006,
            'completion_tokens' => 300,
            'prompt_tokens_details' => ['cached_tokens' => 1920],
        ]));

        $client->chat([new Message('user', 'Hi')]);

        // prompt keeps its meaning: total input including cached tokens.
        $this->assertSame(2006, $entry['tokens']['prompt']);
        $this->assertSame(300, $entry['tokens']['completion']);
        $this->assertSame(2306, $entry['tokens']['total']);
        $this->assertSame(1920, $entry['tokens']['cached']);
        $this->assertSame(0, $entry['tokens']['cache_write']);
    }

    public function testAuditEntryCachedTokensAreZeroWithoutCaching(): void {
        $client = $this->createClient();
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) {
            $entry = $e;
        });
        $client->setHttpClient($this->fakeChat(['prompt_tokens' => 10, 'completion_tokens' => 5]));

        $client->chat([new Message('user', 'Hi')]);

        $this->assertSame(0, $entry['tokens']['cached']);
        $this->assertSame(0, $entry['tokens']['cache_write']);
    }

    public function testMetricsRequestCompletedCarriesCachedTokens(): void {
        $client = $this->createClient();
        $events = [];
        $client->setMetricsCallback(function ($event, $data) use (&$events) {
            $events[$event] = $data;
        });
        $client->setHttpClient($this->fakeChat([
            'prompt_tokens' => 2006,
            'completion_tokens' => 300,
            'prompt_tokens_details' => ['cached_tokens' => 1920],
        ]));

        $client->chat([new Message('user', 'Hi')]);

        $completed = $events['request.completed'];
        $this->assertSame(2006, $completed['prompt_tokens']);
        $this->assertSame(1920, $completed['cached_tokens']);
        $this->assertSame(0, $completed['cache_write_tokens']);
    }

    // =========================================================================
    // Streaming
    // =========================================================================

    public function testStreamRequestAsksForUsage(): void {
        $http = new FakeHttpClient();
        $http->addStreamingChunks(["data: [DONE]\n\n"]);
        $client = $this->createClient();
        $client->setHttpClient($http);

        $client->streamChat([new Message('user', 'Hi')], function (string $token) {});

        $body = json_decode($http->getLastRequest()->getBody(), true);
        $this->assertSame(['include_usage' => true], $body['stream_options']);
    }

    public function testNonStreamRequestDoesNotSendStreamOptions(): void {
        $http = $this->fakeChat(['prompt_tokens' => 1, 'completion_tokens' => 1]);
        $client = $this->createClient();
        $client->setHttpClient($http);

        $client->chat([new Message('user', 'Hi')]);

        $body = json_decode($http->getLastRequest()->getBody(), true);
        $this->assertArrayNotHasKey('stream_options', $body);
    }

    public function testStreamReportsUsageFromFinalChunk(): void {
        $response = $this->stream([
            $this->sse(['model' => 'gpt-4o', 'choices' => [['delta' => ['content' => 'Hi'], 'finish_reason' => null]]]),
            $this->sse(['model' => 'gpt-4o', 'choices' => [['delta' => [], 'finish_reason' => 'stop']]]),
            // Final chunk requested by include_usage: empty choices, usage set.
            $this->sse([
                'model' => 'gpt-4o',
                'choices' => [],
                'usage' => [
                    'prompt_tokens' => 2006,
                    'completion_tokens' => 12,
                    'prompt_tokens_details' => ['cached_tokens' => 1920],
                ],
            ]),
            "data: [DONE]\n\n",
        ]);

        $this->assertSame('Hi', $response->getMessage()->getContent());
        $this->assertSame('stop', $response->getFinishReason());
        $this->assertSame(2006, $response->getUsage()->getPromptTokens());
        $this->assertSame(12, $response->getUsage()->getCompletionTokens());
        $this->assertSame(1920, $response->getUsage()->getCachedTokens());
    }

    public function testStreamWithoutUsageChunkHasNullUsage(): void {
        $response = $this->stream([
            $this->sse(['model' => 'gpt-4o', 'choices' => [['delta' => ['content' => 'Hi'], 'finish_reason' => 'stop']]]),
            "data: [DONE]\n\n",
        ]);

        $this->assertNull($response->getUsage());
    }

    public function testStreamUsageWithoutDetailsReportsZeroCached(): void {
        $response = $this->stream([
            $this->sse(['model' => 'gpt-4o', 'choices' => [['delta' => ['content' => 'Hi'], 'finish_reason' => 'stop']]]),
            $this->sse(['model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2]]),
            "data: [DONE]\n\n",
        ]);

        $this->assertSame(7, $response->getUsage()->getPromptTokens());
        $this->assertSame(0, $response->getUsage()->getCachedTokens());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function chatWithUsage(array $usage): ChatResponse {
        $client = $this->createClient();
        $client->setHttpClient($this->fakeChat($usage));

        return $client->chat([new Message('user', 'Hi')]);
    }

    private function createClient(): OpenAIClient {
        return new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
    }

    private function fakeChat(array $usage): FakeHttpClient {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => $usage,
        ])));

        return $http;
    }

    private function sse(array $payload): string {
        return 'data: '.json_encode($payload)."\n\n";
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
