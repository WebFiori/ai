<?php

namespace WebFiori\Tests\Ai\Cancellation;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Exception\OperationCancelledException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Status;
use WebFiori\Ai\Tool\Tool;

/**
 * Tool-loop cancellation tests (issue #180): cancelling during the
 * auto-execute tool loop surfaces OperationCancelledException, stops before
 * the next iteration/tool, and is recorded as a cancellation (not a failure).
 */
class ToolLoopCancellationTest extends TestCase {
    public function testCancelledStatusConstant(): void {
        $this->assertSame('cancelled', Status::CANCELLED);
    }

    public function testCancelDuringToolStopsLoopAndThrows(): void {
        $client = new FakeHttpClient();
        // First response asks to call the tool.
        $client->addResponse($this->toolCallResponse());
        // A second response is queued, but the loop must NOT reach it because
        // the tool cancels the request.
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'should not reach'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);

        $source = new CancellationTokenSource();
        $toolRan = false;

        $tool = new Tool(
            'get_weather',
            'Gets weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            function (array $args) use ($source, &$toolRan): string {
                $toolRan = true;
                // User cancels while the tool is running.
                $source->cancel();

                return json_encode(['temp' => 20]);
            }
        );

        $threw = false;

        try {
            $provider->chat([new Message('user', 'weather?')], [
                ChatOption::TOOLS => [$tool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
                ChatOption::CANCELLATION_TOKEN => $source->getToken(),
            ]);
        } catch (OperationCancelledException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'chat() must surface OperationCancelledException');
        $this->assertTrue($toolRan, 'the tool should have run (and cancelled)');
        // Only the first request was sent; the loop aborted before the second.
        $this->assertCount(1, $client->getRequests(), 'loop must not send a second request after cancel');
    }

    public function testCancelBeforeToolExecutionSkipsTool(): void {
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse());

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);

        // Pre-cancelled before the loop even starts executing tools.
        $source = new CancellationTokenSource();
        $source->cancel();

        $toolRan = false;
        $tool = new Tool(
            'get_weather',
            'Gets weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            function (array $args) use (&$toolRan): string {
                $toolRan = true;

                return '{}';
            }
        );

        $this->expectException(OperationCancelledException::class);

        try {
            $provider->chat([new Message('user', 'weather?')], [
                ChatOption::TOOLS => [$tool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
                ChatOption::CANCELLATION_TOKEN => $source->getToken(),
            ]);
        } finally {
            $this->assertFalse($toolRan, 'tool must not run when already cancelled before execution');
        }
    }

    public function testUncancelledToolLoopCompletesNormally(): void {
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse());
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'It is 20C'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);

        // Token present but never cancelled: loop must complete as usual.
        $source = new CancellationTokenSource();

        $tool = new Tool(
            'get_weather',
            'Gets weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            fn (array $args): string => json_encode(['temp' => 20])
        );

        $response = $provider->chat([new Message('user', 'weather?')], [
            ChatOption::TOOLS => [$tool],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::CANCELLATION_TOKEN => $source->getToken(),
        ]);

        $this->assertSame('It is 20C', $response->getMessage()->getContent());
        $this->assertCount(2, $client->getRequests());
    }

    private function toolCallResponse(): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_abc123',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"location":"London"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ]));
    }
}
