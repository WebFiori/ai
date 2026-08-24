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
use WebFiori\Ai\Context\SummarizationPrompt;
use WebFiori\Ai\Context\SummarizingWindowStrategy;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Tests for #121: SummarizingWindowStrategy.
 */
class SummarizingWindowStrategyTest extends TestCase {
    // =========================================================================
    // SummarizationPrompt
    // =========================================================================

    public function testSummarizationPromptDefaults(): void {
        $prompt = new SummarizationPrompt();

        $this->assertNotEmpty($prompt->getInstruction());
        $this->assertStringContainsString('Summary', $prompt->getSummaryPrefix());
    }

    public function testSummarizationPromptCustomValues(): void {
        $prompt = new SummarizationPrompt(
            instruction: 'Custom instruction',
            summaryPrefix: 'History: '
        );

        $this->assertEquals('Custom instruction', $prompt->getInstruction());
        $this->assertEquals('History: ', $prompt->getSummaryPrefix());
    }

    // =========================================================================
    // Constructor and getters
    // =========================================================================

    public function testConstructorDefaults(): void {
        $strategy = new SummarizingWindowStrategy(
            summarizer: $this->createMockSummarizer('Summary text')
        );

        $this->assertEquals(8192, $strategy->getContextWindow());
        $this->assertEquals(0.70, $strategy->getThreshold());
        $this->assertEquals(3, $strategy->getKeepRecentTurns());
        $this->assertEquals(1024, $strategy->getReservedTokens());
        $this->assertInstanceOf(SummarizationPrompt::class, $strategy->getPrompt());
    }

    public function testConstructorCustomValues(): void {
        $summarizer = $this->createMockSummarizer('Summary');
        $prompt = new SummarizationPrompt('Custom', 'Prefix: ');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 4096,
            threshold: 0.80,
            keepRecentTurns: 5,
            reserveForCompletion: 512,
            prompt: $prompt
        );

        $this->assertEquals(4096, $strategy->getContextWindow());
        $this->assertEquals(0.80, $strategy->getThreshold());
        $this->assertEquals(5, $strategy->getKeepRecentTurns());
        $this->assertEquals(512, $strategy->getReservedTokens());
        $this->assertSame($prompt, $strategy->getPrompt());
        $this->assertSame($summarizer, $strategy->getSummarizer());
    }

    public function testConstructorClampsThreshold(): void {
        $summarizer = $this->createMockSummarizer('S');

        $s1 = new SummarizingWindowStrategy($summarizer, threshold: 0.0);
        $this->assertEquals(0.1, $s1->getThreshold());

        $s2 = new SummarizingWindowStrategy($summarizer, threshold: 2.0);
        $this->assertEquals(1.0, $s2->getThreshold());
    }

    public function testConstructorClampsMinValues(): void {
        $summarizer = $this->createMockSummarizer('S');

        $s = new SummarizingWindowStrategy($summarizer, contextWindow: 0, keepRecentTurns: 0, reserveForCompletion: -1);
        $this->assertEquals(1, $s->getContextWindow());
        $this->assertEquals(1, $s->getKeepRecentTurns());
        $this->assertEquals(0, $s->getReservedTokens());
    }

    // =========================================================================
    // truncate() — below threshold
    // =========================================================================

    public function testBelowThresholdReturnsMessagesUnchanged(): void {
        $summarizer = $this->createMockSummarizer('Should not be called');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 100000, // very large — won't trigger
            threshold: 0.70
        );

        $messages = [
            new Message('user', 'Hello'),
            new Message('assistant', 'Hi there'),
        ];

        $result = $strategy->truncate($messages, 100000);

        $this->assertSame($messages, $result);
    }

    // =========================================================================
    // truncate() — above threshold — summarization triggered
    // =========================================================================

    public function testAboveThresholdTriggersSummarization(): void {
        $summarizer = $this->createMockSummarizer('This is the summary.');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,   // very small — always triggers
            threshold: 0.70,
            keepRecentTurns: 1
        );

        $messages = [
            new Message('user', 'Message 1'),
            new Message('assistant', 'Response 1'),
            new Message('user', 'Message 2'),
            new Message('assistant', 'Response 2'),
            new Message('user', 'Message 3'),   // recent
            new Message('assistant', 'Response 3'), // recent
        ];

        $result = $strategy->truncate($messages, 10);

        // Should have: summary message + last 2 messages (1 turn)
        $this->assertNotSame($messages, $result);

        // Find summary message
        $summaryMessages = array_filter($result, fn($m) => $m->getRole() === 'system');
        $this->assertNotEmpty($summaryMessages);
        $summary = array_values($summaryMessages)[0];
        $this->assertStringContainsString('This is the summary.', $summary->getContent());
    }

    public function testSystemMessagesAlwaysPreserved(): void {
        $summarizer = $this->createMockSummarizer('Summary');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 1
        );

        $messages = [
            new Message('system', 'You are a helpful assistant.'),
            new Message('user', 'Old message 1'),
            new Message('assistant', 'Old response 1'),
            new Message('user', 'Recent message'),
            new Message('assistant', 'Recent response'),
        ];

        $result = $strategy->truncate($messages, 10);

        // First message should be the original system message
        $this->assertEquals('system', $result[0]->getRole());
        $this->assertEquals('You are a helpful assistant.', $result[0]->getContent());
    }

    public function testSummaryInjectedAfterSystemMessage(): void {
        $summarizer = $this->createMockSummarizer('Conversation summary.');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 1
        );

        $messages = [
            new Message('system', 'Original system.'),
            new Message('user', 'Old 1'),
            new Message('assistant', 'Old resp 1'),
            new Message('user', 'Recent'),
            new Message('assistant', 'Recent resp'),
        ];

        $result = $strategy->truncate($messages, 10);

        // Position 0: original system
        $this->assertEquals('You are not the summary', 'You are not the summary'); // placeholder
        $this->assertEquals('Original system.', $result[0]->getContent());
        // Position 1: summary system message
        $this->assertEquals('system', $result[1]->getRole());
        $this->assertStringContainsString('Conversation summary.', $result[1]->getContent());
    }

    public function testCorrectNumberOfRecentTurnsKept(): void {
        $summarizer = $this->createMockSummarizer('Summary');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 2  // keep last 2 turns = 4 messages
        );

        $messages = [
            new Message('user', 'Old 1'),
            new Message('assistant', 'Old resp 1'),
            new Message('user', 'Old 2'),
            new Message('assistant', 'Old resp 2'),
            new Message('user', 'Recent 1'),     // turn 1
            new Message('assistant', 'Recent resp 1'),
            new Message('user', 'Recent 2'),     // turn 2
            new Message('assistant', 'Recent resp 2'),
        ];

        $result = $strategy->truncate($messages, 10);

        // Should have: summary + 4 recent messages
        $nonSystemResult = array_values(array_filter($result, fn($m) => $m->getRole() !== 'system'));
        $this->assertCount(4, $nonSystemResult);
        $this->assertEquals('Recent 1', $nonSystemResult[0]->getContent());
        $this->assertEquals('Recent 2', $nonSystemResult[2]->getContent());
    }

    public function testConversationTooShortToSummarize(): void {
        $summarizer = $this->createMockSummarizer('Should not be called');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 3  // need 6 non-system messages to summarize
        );

        // Only 4 non-system messages — not enough to keep 6 recent
        $messages = [
            new Message('user', 'Msg 1'),
            new Message('assistant', 'Resp 1'),
            new Message('user', 'Msg 2'),
            new Message('assistant', 'Resp 2'),
        ];

        $result = $strategy->truncate($messages, 10);

        // Returns unchanged — nothing to summarize
        $this->assertSame($messages, $result);
    }

    // =========================================================================
    // Caching
    // =========================================================================

    public function testCachePreventsDuplicateSummarizerCall(): void {
        $callCount = 0;
        $summarizer = $this->createCountingSummarizer('Cached summary', $callCount);
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 1
        );

        $messages = [
            new Message('user', 'Old msg'),
            new Message('assistant', 'Old resp'),
            new Message('user', 'Recent'),
            new Message('assistant', 'Recent resp'),
        ];

        // First call — should invoke summarizer
        $strategy->truncate($messages, 10);
        $this->assertEquals(1, $callCount);

        // Second call with same messages — should use cache
        $strategy->truncate($messages, 10);
        $this->assertEquals(1, $callCount); // still 1
    }

    public function testCacheInvalidatedWhenMessagesChange(): void {
        $callCount = 0;
        $summarizer = $this->createCountingSummarizer('Summary', $callCount);
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 1
        );

        $messages1 = [
            new Message('user', 'Old msg A'),
            new Message('assistant', 'Old resp A'),
            new Message('user', 'Recent'),
            new Message('assistant', 'Recent resp'),
        ];

        $messages2 = [
            new Message('user', 'Old msg B'),  // different
            new Message('assistant', 'Old resp B'),
            new Message('user', 'Recent'),
            new Message('assistant', 'Recent resp'),
        ];

        $strategy->truncate($messages1, 10);
        $this->assertEquals(1, $callCount);

        $strategy->truncate($messages2, 10);
        $this->assertEquals(2, $callCount); // re-summarized
    }

    public function testClearCacheForcesSummarizationAgain(): void {
        $callCount = 0;
        $summarizer = $this->createCountingSummarizer('Summary', $callCount);
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 1
        );

        $messages = [
            new Message('user', 'Old'),
            new Message('assistant', 'Old resp'),
            new Message('user', 'Recent'),
            new Message('assistant', 'Recent resp'),
        ];

        $strategy->truncate($messages, 10);
        $this->assertEquals(1, $callCount);

        $strategy->clearCache();

        $strategy->truncate($messages, 10);
        $this->assertEquals(2, $callCount); // re-summarized after clear
    }

    // =========================================================================
    // Custom prompt
    // =========================================================================

    public function testCustomPromptUsedInSummarizationRequest(): void {
        $capturedMessages = null;
        $summarizer = $this->createCapturingSummarizer('Summary', $capturedMessages);

        $customPrompt = new SummarizationPrompt(
            instruction: 'Custom: summarize briefly',
            summaryPrefix: 'Prior context: '
        );

        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 1,
            prompt: $customPrompt
        );

        $messages = [
            new Message('user', 'Old'),
            new Message('assistant', 'Resp'),
            new Message('user', 'Recent'),
            new Message('assistant', 'Recent resp'),
        ];

        $result = $strategy->truncate($messages, 10);

        // The system message sent to summarizer should contain custom instruction
        $this->assertNotNull($capturedMessages);
        $this->assertEquals('Custom: summarize briefly', $capturedMessages[0]->getContent());

        // The summary message should use custom prefix
        $summaryMessages = array_filter($result, fn($m) => str_starts_with($m->getContent(), 'Prior context: '));
        $this->assertNotEmpty($summaryMessages);
    }

    // =========================================================================
    // Integration with AbstractClient
    // =========================================================================

    public function testIntegrationWithAbstractClient(): void {
        $summarizer = $this->createMockSummarizer('Earlier: user asked about PHP.');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10, // tiny — always triggers
            threshold: 0.70,
            keepRecentTurns: 1
        );

        $client = new OpenAIClient(new OpenAIClientConfig(
            apiKey: 'test-key',
            model: 'gpt-4o',
        ));
        $client->setContextWindowStrategy($strategy);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Final answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
        ])));
        $client->setHttpClient($fakeHttp);

        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'Old question about PHP'),
            new Message('assistant', 'Old answer about PHP'),
            new Message('user', 'New question'), // recent
            new Message('assistant', 'New answer'), // recent
        ];

        // Should trigger summarization — request body should contain summary message
        $response = $client->chat($messages);

        $this->assertEquals('Final answer.', $response->getMessage()->getContent());

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $systemMessages = array_filter($body['messages'], fn($m) => ($m['role'] ?? '') === 'system');
        $systemContents = array_column(array_values($systemMessages), 'content');

        // Should have original system + summary system
        $this->assertCount(2, $systemContents);
        $this->assertEquals('You are helpful.', $systemContents[0]);
        $this->assertStringContainsString('Earlier: user asked about PHP.', $systemContents[1]);
    }

    // =========================================================================
    // Multiple system messages
    // =========================================================================

    public function testMultipleSystemMessagesAllPreserved(): void {
        $summarizer = $this->createMockSummarizer('Summary');
        $strategy = new SummarizingWindowStrategy(
            summarizer: $summarizer,
            contextWindow: 10,
            threshold: 0.70,
            keepRecentTurns: 1
        );

        $messages = [
            new Message('system', 'System 1'),
            new Message('system', 'System 2'),
            new Message('user', 'Old'),
            new Message('assistant', 'Old resp'),
            new Message('user', 'Recent'),
            new Message('assistant', 'Recent resp'),
        ];

        $result = $strategy->truncate($messages, 10);

        $systemMessages = array_values(array_filter($result, fn($m) => $m->getRole() === 'system'));
        $this->assertCount(3, $systemMessages); // 2 original + 1 summary
        $this->assertEquals('System 1', $systemMessages[0]->getContent());
        $this->assertEquals('System 2', $systemMessages[1]->getContent());
        $this->assertStringContainsString('Summary', $systemMessages[2]->getContent());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createMockSummarizer(string $summaryText): OpenAIClient {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));
        $fakeHttp = new FakeHttpClient();

        // Add many responses so cache tests don't run out
        for ($i = 0; $i < 10; $i++) {
            $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
                'id' => 'chatcmpl-s',
                'model' => 'gpt-4o',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => $summaryText],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
            ])));
        }

        $client->setHttpClient($fakeHttp);

        return $client;
    }

    private function createCountingSummarizer(string $summaryText, int &$callCount): OpenAIClient {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));

        $fakeHttp = new class($summaryText, $callCount) extends FakeHttpClient {
            public function __construct(
                private string $summaryText,
                private int &$callCount
            ) {}

            public function send(\WebFiori\Ai\Http\HttpRequest $request): \WebFiori\Ai\Http\HttpResponse {
                $this->callCount++;

                return new \WebFiori\Ai\Http\HttpResponse(200, [], json_encode([
                    'id' => 'chatcmpl-s',
                    'model' => 'gpt-4o',
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => $this->summaryText],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ]));
            }
        };

        $client->setHttpClient($fakeHttp);

        return $client;
    }

    private function createCapturingSummarizer(string $summaryText, ?array &$capturedMessages): OpenAIClient {
        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key'));

        $fakeHttp = new class($summaryText, $capturedMessages) extends FakeHttpClient {
            public function __construct(
                private string $summaryText,
                private ?array &$capturedMessages
            ) {}

            public function send(\WebFiori\Ai\Http\HttpRequest $request): \WebFiori\Ai\Http\HttpResponse {
                $body = json_decode($request->getBody(), true);
                $this->capturedMessages = array_map(
                    fn($m) => new \WebFiori\Ai\Message($m['role'], $m['content']),
                    $body['messages'] ?? []
                );

                return new \WebFiori\Ai\Http\HttpResponse(200, [], json_encode([
                    'id' => 'chatcmpl-s',
                    'model' => 'gpt-4o',
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => $this->summaryText],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ]));
            }
        };

        $client->setHttpClient($fakeHttp);

        return $client;
    }
}
