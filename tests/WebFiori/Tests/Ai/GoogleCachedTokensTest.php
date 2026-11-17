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
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;

/**
 * Tests for cached token reporting from Google generateContent and the
 * Interactions API (issue #198).
 */
class GoogleCachedTokensTest extends TestCase {
    public function testGenerateContentCostUsesCachedPrice(): void {
        $client = $this->createGemini2Client();
        $client->setPricing(new PricingConfig([
            'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50, 'cached_input' => 0.03],
        ]));
        $client->setHttpClient($this->fakeGenerateContent([
            'promptTokenCount' => 1_000_000,
            'candidatesTokenCount' => 0,
            'cachedContentTokenCount' => 750_000,
        ]));

        $cost = $client->chat([new Message('user', 'Hi')])->getCost();

        // 250k * 0.30 + 750k * 0.03 = 0.075 + 0.0225
        $this->assertNotNull($cost);
        $this->assertEqualsWithDelta(0.0975, $cost->getInputCost(), 1e-9);
        $this->assertEqualsWithDelta(0.0225, $cost->getCachedInputCost(), 1e-9);
    }
    // =========================================================================
    // generateContent
    // =========================================================================

    public function testGenerateContentReportsCachedTokens(): void {
        $usage = $this->generateContent([
            'promptTokenCount' => 4096,
            'candidatesTokenCount' => 50,
            'cachedContentTokenCount' => 3072,
        ])->getUsage();

        $this->assertSame(4096, $usage->getPromptTokens());
        $this->assertSame(50, $usage->getCompletionTokens());
        $this->assertSame(3072, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
    }

    public function testGenerateContentStreamingReportsCachedTokens(): void {
        $http = new FakeHttpClient();
        $http->addStreamingChunks([
            'data: '.json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]]])."\n\n",
            'data: '.json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'lo']]], 'finishReason' => 'STOP']],
                'usageMetadata' => [
                    'promptTokenCount' => 2048,
                    'candidatesTokenCount' => 4,
                    'cachedContentTokenCount' => 1024,
                ],
            ])."\n\n",
        ]);
        $client = $this->createGemini2Client();
        $client->setHttpClient($http);

        $completed = null;
        $client->streamChat(
            [new Message('user', 'Hi')],
            function (string $token)
            {
            },
            function ($response) use (&$completed)
            {
                $completed = $response;
            }
        );

        $this->assertNotNull($completed);
        $this->assertSame('Hello', $completed->getMessage()->getContent());
        $this->assertSame(2048, $completed->getUsage()->getPromptTokens());
        $this->assertSame(1024, $completed->getUsage()->getCachedTokens());
    }

    public function testGenerateContentStreamingWithoutCachedFieldReportsZero(): void {
        $http = new FakeHttpClient();
        $http->addStreamingChunks([
            'data: '.json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'Hi']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 1],
            ])."\n\n",
        ]);
        $client = $this->createGemini2Client();
        $client->setHttpClient($http);

        $completed = null;
        $client->streamChat(
            [new Message('user', 'Hi')],
            function (string $token)
            {
            },
            function ($response) use (&$completed)
            {
                $completed = $response;
            }
        );

        $this->assertSame(9, $completed->getUsage()->getPromptTokens());
        $this->assertSame(0, $completed->getUsage()->getCachedTokens());
    }

    public function testGenerateContentWithoutCachedFieldReportsZero(): void {
        $usage = $this->generateContent(['promptTokenCount' => 10, 'candidatesTokenCount' => 5])->getUsage();

        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCachedTokens());
    }

    public function testInteractionsAcceptsSpecFieldNames(): void {
        $usage = $this->interactions(['input_tokens' => 100, 'output_tokens' => 3, 'cached_tokens' => 40])->getUsage();

        $this->assertSame(100, $usage->getPromptTokens());
        $this->assertSame(40, $usage->getCachedTokens());
    }

    // =========================================================================
    // Interactions API
    // =========================================================================

    public function testInteractionsReportsCachedTokens(): void {
        $usage = $this->interactions([
            'total_input_tokens' => 5000,
            'total_output_tokens' => 20,
            'total_cached_tokens' => 4000,
            'total_tokens' => 5020,
        ])->getUsage();

        $this->assertSame(5000, $usage->getPromptTokens());
        $this->assertSame(20, $usage->getCompletionTokens());
        $this->assertSame(4000, $usage->getCachedTokens());
        $this->assertSame(0, $usage->getCacheWriteTokens());
    }

    public function testInteractionsStreamingReportsCachedTokens(): void {
        $http = new FakeHttpClient();
        $http->addStreamingChunks([
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => ['type' => 'text', 'text' => 'Hi!']]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('interaction.completed', ['interaction' => [
                'id' => 'int_1', 'model' => 'gemini-3.5-flash', 'status' => 'completed',
                'usage' => [
                    'total_input_tokens' => 6000,
                    'total_output_tokens' => 8,
                    'total_cached_tokens' => 5500,
                ],
            ]]),
        ]);
        $client = $this->createGemini3Client();
        $client->setHttpClient($http);

        $completed = null;
        $client->streamChat(
            [new Message('user', 'Hi')],
            function (string $token)
            {
            },
            function ($response) use (&$completed)
            {
                $completed = $response;
            }
        );

        $this->assertNotNull($completed);
        $this->assertSame(6000, $completed->getUsage()->getPromptTokens());
        $this->assertSame(8, $completed->getUsage()->getCompletionTokens());
        $this->assertSame(5500, $completed->getUsage()->getCachedTokens());
    }

    public function testInteractionsStreamingWithoutCachedFieldReportsZero(): void {
        $http = new FakeHttpClient();
        $http->addStreamingChunks([
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => ['type' => 'text', 'text' => 'Hi!']]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('interaction.completed', ['interaction' => [
                'id' => 'int_1', 'model' => 'gemini-3.5-flash', 'status' => 'completed',
                'usage' => ['total_input_tokens' => 3, 'total_output_tokens' => 1],
            ]]),
        ]);
        $client = $this->createGemini3Client();
        $client->setHttpClient($http);

        $completed = null;
        $client->streamChat(
            [new Message('user', 'Hi')],
            function (string $token)
            {
            },
            function ($response) use (&$completed)
            {
                $completed = $response;
            }
        );

        $this->assertSame(3, $completed->getUsage()->getPromptTokens());
        $this->assertSame(0, $completed->getUsage()->getCachedTokens());
    }

    public function testInteractionsWithoutCachedFieldReportsZero(): void {
        $usage = $this->interactions(['total_input_tokens' => 12, 'total_output_tokens' => 3])->getUsage();

        $this->assertSame(12, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCachedTokens());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createGemini2Client(): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(model: 'gemini-2.5-flash', apiKey: 'test-key'));
    }

    private function createGemini3Client(): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(model: 'gemini-3.5-flash', apiKey: 'test-key'));
    }

    private function fakeGenerateContent(array $usageMetadata): FakeHttpClient {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hello']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'modelVersion' => 'gemini-2.5-flash',
            'usageMetadata' => $usageMetadata,
        ])));

        return $http;
    }

    private function generateContent(array $usageMetadata): ChatResponse {
        $client = $this->createGemini2Client();
        $client->setHttpClient($this->fakeGenerateContent($usageMetadata));

        return $client->chat([new Message('user', 'Hi')]);
    }

    private function interactions(array $usage): ChatResponse {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_1',
            'model' => 'gemini-3.5-flash',
            'status' => 'completed',
            'steps' => [['type' => 'text', 'text' => 'Hello']],
            'usage' => $usage,
        ])));
        $client = $this->createGemini3Client();
        $client->setHttpClient($http);

        return $client->chat([new Message('user', 'Hi')]);
    }

    private function sseEvent(string $type, array $data): string {
        return "event: {$type}\ndata: ".json_encode($data)."\n\n";
    }
}
