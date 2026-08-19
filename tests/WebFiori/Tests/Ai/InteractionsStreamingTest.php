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
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\GoogleClient;

/**
 * Tests for #104: Interactions API streaming support.
 */
class InteractionsStreamingTest extends TestCase {
    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Build a named SSE event string */
    private function sseEvent(string $type, array $data): string {
        return "event: {$type}\ndata: ".json_encode($data)."\n\n";
    }

    private function createGemini3Client(): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));
    }

    // ─── Text streaming ───────────────────────────────────────────────────────

    public function testStreamTextTokens(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            $this->sseEvent('interaction.created', ['interaction' => ['id' => 'int_001', 'status' => 'in_progress', 'model' => 'gemini-3.5-flash']]),
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => ['type' => 'text', 'text' => 'Hello']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => ['type' => 'text', 'text' => ' World']]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('interaction.completed', ['interaction' => [
                'id' => 'int_001', 'model' => 'gemini-3.5-flash', 'status' => 'completed',
                'usage' => ['total_input_tokens' => 5, 'total_output_tokens' => 2, 'total_tokens' => 7],
            ]]),
        ]);

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $tokens = [];
        $client->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$tokens) {
                $tokens[] = $token;
            }
        );

        $this->assertEquals(['Hello', ' World'], $tokens);
    }

    public function testStreamCompletionCallback(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => ['type' => 'text', 'text' => 'Hi!']]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('interaction.completed', ['interaction' => [
                'id' => 'int_abc', 'model' => 'gemini-3.5-flash', 'status' => 'completed',
                'usage' => ['total_input_tokens' => 3, 'total_output_tokens' => 1, 'total_tokens' => 4],
            ]]),
        ]);

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $completionResponse = null;
        $client->streamChat(
            [new Message('user', 'Hi')],
            fn(string $t) => null,
            function (ChatResponse $response) use (&$completionResponse) {
                $completionResponse = $response;
            }
        );

        $this->assertNotNull($completionResponse);
        $this->assertEquals('Hi!', $completionResponse->getMessage()->getContent());
        $this->assertEquals('int_abc', $completionResponse->getRequestId());
        $this->assertNotNull($completionResponse->getUsage());
        $this->assertEquals(3, $completionResponse->getUsage()->getPromptTokens());
        $this->assertEquals(1, $completionResponse->getUsage()->getCompletionTokens());
    }

    public function testStreamIgnoresThoughtSteps(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'thought']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => ['type' => 'thought_signature', 'signature' => 'abc']]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('step.start', ['index' => 1, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 1, 'delta' => ['type' => 'text', 'text' => 'Answer.']]),
            $this->sseEvent('step.stop', ['index' => 1]),
            $this->sseEvent('interaction.completed', ['interaction' => ['id' => 'x', 'model' => 'gemini-3.5-flash', 'status' => 'completed']]),
        ]);

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $tokens = [];
        $client->streamChat(
            [new Message('user', 'Q')],
            function (string $t) use (&$tokens) { $tokens[] = $t; }
        );

        // Only text from model_output, not thought signatures
        $this->assertEquals(['Answer.'], $tokens);
    }

    public function testStreamRawStepsPreservedInCompletion(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'thought']]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('step.start', ['index' => 1, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 1, 'delta' => ['type' => 'text', 'text' => 'Answer.']]),
            $this->sseEvent('step.stop', ['index' => 1]),
            $this->sseEvent('interaction.completed', ['interaction' => ['id' => 'x', 'model' => 'gemini-3.5-flash', 'status' => 'completed']]),
        ]);

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $completionResponse = null;
        $client->streamChat(
            [new Message('user', 'Q')],
            fn(string $t) => null,
            function (ChatResponse $r) use (&$completionResponse) { $completionResponse = $r; }
        );

        $rawSteps = $completionResponse->getMessage()->getRawSteps();
        $this->assertNotNull($rawSteps);
        $this->assertCount(2, $rawSteps);
        $this->assertEquals('thought', $rawSteps[0]['type']);
        $this->assertEquals('model_output', $rawSteps[1]['type']);
    }

    public function testStreamFunctionCallDetected(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => [
                'type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['city' => 'NYC'],
            ]]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('interaction.completed', ['interaction' => [
                'id' => 'int_001', 'model' => 'gemini-3.5-flash', 'status' => 'completed',
                'usage' => ['total_input_tokens' => 10, 'total_output_tokens' => 5, 'total_tokens' => 15],
            ]]),
        ]);

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $completionResponse = null;
        $client->streamChat(
            [new Message('user', 'Weather?')],
            fn(string $t) => null,
            function (ChatResponse $r) use (&$completionResponse) { $completionResponse = $r; }
        );

        $this->assertNotNull($completionResponse);
        $this->assertTrue($completionResponse->hasToolCalls());
        $toolCalls = $completionResponse->getMessage()->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['city' => 'NYC'], $toolCalls[0]->getArguments());
        $this->assertEquals('tool_calls', $completionResponse->getFinishReason());
    }

    public function testStreamBodyContainsStreamTrue(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => ['type' => 'text', 'text' => 'Hi']]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('interaction.completed', ['interaction' => ['id' => 'x', 'model' => 'gemini-3.5-flash', 'status' => 'completed']]),
        ]);

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $client->streamChat([new Message('user', 'Hi')], fn(string $t) => null);

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);
        $this->assertTrue($body['stream'] ?? false, 'stream:true should be in request body');
    }

    // ─── Backward compatibility ────────────────────────────────────────────────

    public function testGemini2xStreamingUnchanged(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hello\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}]}\n\n",
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\" there\"}],\"role\":\"model\"}}],\"usageMetadata\":{\"promptTokenCount\":3,\"candidatesTokenCount\":2}}\n\n",
            "data: [DONE]\n\n",
        ]);

        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-key',
        ));
        $client->setHttpClient($fakeHttp);

        $tokens = [];
        $completionResponse = null;
        $client->streamChat(
            [new Message('user', 'Hi')],
            function (string $t) use (&$tokens) { $tokens[] = $t; },
            function (ChatResponse $r) use (&$completionResponse) { $completionResponse = $r; }
        );

        $this->assertEquals(['Hello', ' there'], $tokens);
        $this->assertNotNull($completionResponse);
        $this->assertEquals('Hello there', $completionResponse->getMessage()->getContent());
    }

    public function testUsesInteractionsEndpoint(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            $this->sseEvent('step.start', ['index' => 0, 'step' => ['type' => 'model_output']]),
            $this->sseEvent('step.delta', ['index' => 0, 'delta' => ['type' => 'text', 'text' => 'Hi']]),
            $this->sseEvent('step.stop', ['index' => 0]),
            $this->sseEvent('interaction.completed', ['interaction' => ['id' => 'x', 'model' => 'gemini-3.5-flash', 'status' => 'completed']]),
        ]);

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $client->streamChat([new Message('user', 'Hi')], fn(string $t) => null);

        $url = $fakeHttp->getLastRequest()->getUrl();
        $this->assertStringContainsString('interactions', $url);
        $this->assertStringNotContainsString('streamGenerateContent', $url);
    }
}
