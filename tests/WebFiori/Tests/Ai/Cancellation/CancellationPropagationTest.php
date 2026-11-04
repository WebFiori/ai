<?php

namespace WebFiori\Tests\Ai\Cancellation;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Cancellation\CancellationToken;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\OperationCancelledException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\RetryableHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\RetryConfig;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Tests\Ai\Tool\CapturingMockProvider;

/**
 * Cancellation propagation through tools, AgentTool and retry backoff (issue #186).
 */
class CancellationPropagationTest extends TestCase {
    // ---- conditional rethrow in executeTools() -------------------------------

    public function testCancellationInsideToolWithCancelledTokenStopsRun(): void {
        $logs = [];
        $source = new CancellationTokenSource();
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse('get_weather', '{"location":"London"}'));
        $client->addResponse($this->textResponse('should not reach'));
        $provider = $this->provider($client, $logs);

        $tool = new Tool('get_weather', 'Gets weather', ['type' => 'object', 'properties' => []],
            function (array $args) use ($source): string {
                $source->cancel();

                throw new OperationCancelledException();
            }
        );

        try {
            $provider->chat([new Message('user', 'weather?')], [
                ChatOption::TOOLS => [$tool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
                ChatOption::CANCELLATION_TOKEN => $source->getToken(),
            ]);
            $this->fail('chat() must surface OperationCancelledException');
        } catch (OperationCancelledException $e) {
            $this->assertCount(1, $client->getRequests());
            $this->assertNotContains('Tool execution threw', array_column($logs, 1), 'cancellation must not be logged as a tool failure');
        }
    }

    public function testNonCancellationToolErrorWithCancelledTokenStillStopsRun(): void {
        $logs = [];
        $source = new CancellationTokenSource();
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse('get_weather', '{}'));
        $provider = $this->provider($client, $logs);

        $tool = new Tool('get_weather', 'Gets weather', ['type' => 'object', 'properties' => []],
            function (array $args) use ($source): string {
                $source->cancel();

                throw new \RuntimeException('aborted mid-flight');
            }
        );

        $this->expectException(OperationCancelledException::class);
        $provider->chat([new Message('user', 'weather?')], [
            ChatOption::TOOLS => [$tool],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::CANCELLATION_TOKEN => $source->getToken(),
        ]);
    }

    public function testToolOwnCancellationWithUncancelledOuterTokenIsToolFailure(): void {
        $logs = [];
        $source = new CancellationTokenSource(); // never cancelled
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse('get_weather', '{}'));
        $client->addResponse($this->textResponse('model carried on'));
        $provider = $this->provider($client, $logs);

        $tool = new Tool('get_weather', 'Gets weather', ['type' => 'object', 'properties' => []],
            function (array $args): string {
                // e.g. the tool's own deadline token fired.
                throw new OperationCancelledException('own deadline');
            }
        );

        $response = $provider->chat([new Message('user', 'weather?')], [
            ChatOption::TOOLS => [$tool],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::CANCELLATION_TOKEN => $source->getToken(),
        ]);

        $this->assertSame('model carried on', $response->getMessage()->getContent());
        $this->assertContains('Tool execution threw', array_column($logs, 1));
    }

    // ---- AgentTool token injection -------------------------------------------

    public function testOuterTokenReachesSubAgent(): void {
        $logs = [];
        $source = new CancellationTokenSource();
        $mock = new CapturingMockProvider();
        $agent = new AgentTool('get_weather', 'Sub agent', $mock, 'You are an agent.');

        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse('get_weather', '{"task":"do it"}'));
        $client->addResponse($this->textResponse('done'));
        $provider = $this->provider($client, $logs);

        $provider->chat([new Message('user', 'go')], [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::CANCELLATION_TOKEN => $source->getToken(),
        ]);

        $this->assertSame(1, $mock->callCount);
        $this->assertSame($source->getToken(), $mock->lastOptions[ChatOption::CANCELLATION_TOKEN] ?? null);
    }

    public function testOuterTokenWinsOverConstructorToken(): void {
        $logs = [];
        $outer = new CancellationTokenSource();
        $own = new CancellationTokenSource();
        $mock = new CapturingMockProvider();
        $agent = new AgentTool('get_weather', 'Sub agent', $mock, 'Agent.',
            options: [ChatOption::CANCELLATION_TOKEN => $own->getToken()]);

        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse('get_weather', '{"task":"do it"}'));
        $client->addResponse($this->textResponse('done'));

        $this->provider($client, $logs)->chat([new Message('user', 'go')], [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::CANCELLATION_TOKEN => $outer->getToken(),
        ]);

        $this->assertSame($outer->getToken(), $mock->lastOptions[ChatOption::CANCELLATION_TOKEN]);
    }

    public function testTokenIsClearedAfterRun(): void {
        $logs = [];
        $outer = new CancellationTokenSource();
        $mock = new CapturingMockProvider();
        $agent = new AgentTool('get_weather', 'Sub agent', $mock, 'Agent.');

        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse('get_weather', '{"task":"do it"}'));
        $client->addResponse($this->textResponse('done'));
        $this->provider($client, $logs)->chat([new Message('user', 'go')], [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::CANCELLATION_TOKEN => $outer->getToken(),
        ]);

        // A later direct execution must not inherit the previous run's token.
        $agent->execute(['task' => 'again']);
        $this->assertArrayNotHasKey(ChatOption::CANCELLATION_TOKEN, $mock->lastOptions);
    }

    public function testNoTokenMeansNoTokenOption(): void {
        $mock = new CapturingMockProvider();
        $agent = new AgentTool('a', 'Sub agent', $mock, 'Agent.');

        $agent->execute(['task' => 'x']);

        $this->assertArrayNotHasKey(ChatOption::CANCELLATION_TOKEN, $mock->lastOptions);
    }

    // ---- interruptible retry backoff -----------------------------------------

    public function testBackoffIsInterruptedByCancellation(): void {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(503, [], 'busy'));
        $inner->addResponse(new HttpResponse(200, [], 'ok'));

        $polls = 0;
        // Poller flips to "stop" on its third check, i.e. ~100ms into the wait.
        $source = new CancellationTokenSource(function () use (&$polls): bool {
            return ++$polls >= 3;
        }, 0);

        $client = new RetryableHttpClient($inner, new RetryConfig(maxRetries: 2, initialDelayMs: 5000, maxDelayMs: 5000));
        $request = (new HttpRequest('GET', 'http://example.test'))->withCancellationToken($source->getToken());

        $start = hrtime(true);

        try {
            $client->send($request);
            $this->fail('expected OperationCancelledException');
        } catch (OperationCancelledException $e) {
            $elapsedMs = (hrtime(true) - $start) / 1e6;
            $this->assertLessThan(2000, $elapsedMs, 'backoff must not wait for the full delay');
            $this->assertCount(1, $inner->getRequests(), 'no retry after cancellation');
        }
    }

    public function testBackoffWithAlreadyCancelledTokenDoesNotWait(): void {
        $inner = new FakeHttpClient();
        $inner->addException(new HttpException('boom', 28));
        $inner->addResponse(new HttpResponse(200, [], 'ok'));

        $source = new CancellationTokenSource();
        $source->cancel();

        $client = new RetryableHttpClient($inner, new RetryConfig(maxRetries: 2, initialDelayMs: 5000, maxDelayMs: 5000));
        $request = (new HttpRequest('GET', 'http://example.test'))->withCancellationToken($source->getToken());

        $start = hrtime(true);

        try {
            $client->send($request);
            $this->fail('expected OperationCancelledException');
        } catch (OperationCancelledException $e) {
            $this->assertLessThan(500, (hrtime(true) - $start) / 1e6);
        }
    }

    public function testBackoffWithoutTokenStillRetries(): void {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(503, [], 'busy'));
        $inner->addResponse(new HttpResponse(200, [], 'ok'));

        $client = new RetryableHttpClient($inner, new RetryConfig(maxRetries: 2, initialDelayMs: 10, maxDelayMs: 10));
        $response = $client->send(new HttpRequest('GET', 'http://example.test'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $inner->getRequests());
    }

    public function testBackoffWithUncancelledTokenStillRetries(): void {
        $inner = new FakeHttpClient();
        $inner->addResponse(new HttpResponse(503, [], 'busy'));
        $inner->addResponse(new HttpResponse(200, [], 'ok'));

        $source = new CancellationTokenSource();
        $client = new RetryableHttpClient($inner, new RetryConfig(maxRetries: 2, initialDelayMs: 60, maxDelayMs: 60));
        $request = (new HttpRequest('GET', 'http://example.test'))->withCancellationToken($source->getToken());

        $this->assertSame(200, $client->send($request)->getStatusCode());
        $this->assertCount(2, $inner->getRequests());
    }

    // ---- helpers ---------------------------------------------------------------

    /**
     * @param array<int, array{0:string,1:string,2:array}> $logs Collected log entries (by reference).
     */
    private function provider(FakeHttpClient $client, array &$logs): OpenAIClient {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);
        $provider->setLogCallback(function (string $level, string $message, array $context) use (&$logs) {
            $logs[] = [$level, $message, $context];
        });

        return $provider;
    }

    private function toolCallResponse(string $name, string $arguments): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_abc123',
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => $arguments],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ]));
    }

    private function textResponse(string $text): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ]));
    }
}
