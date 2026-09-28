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
use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Bedrock\ApiMethod;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;
use WebFiori\Ai\Status;
use WebFiori\Ai\StatusMessageFormatter;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolResponse;

/**
 * Tests for #164: per-tool success/failure reporting in status events and
 * exception-guarded tool execution.
 */
class ToolStatusEventsTest extends TestCase {
    /**
     * Builds a Bedrock CONVERSE client whose first response is a tool call for
     * the given tool name and whose second response is a final answer.
     *
     * @param string $toolName
     * @param array<string, mixed> $input
     *
     * @return BedrockClient
     */
    private function clientWithToolCall(string $toolName, array $input = []): BedrockClient {
        $client = new FakeHttpClient();

        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['toolUse' => [
                    'toolUseId' => 'tool_1',
                    'name' => $toolName,
                    'input' => $input,
                ]],
            ]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
        ])));

        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['text' => 'Done.'],
            ]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 30, 'outputTokens' => 10],
        ])));

        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            apiKey: 'test-key',
            apiMethod: ApiMethod::CONVERSE,
        ));
        $provider->setHttpClient($client);

        return $provider;
    }

    /**
     * @param BedrockClient $provider
     * @param Tool $tool
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function runAndCapture(BedrockClient $provider, Tool $tool): array {
        $events = [];
        $provider->setStatusEmitter(new CallbackStatusEmitter(
            function (string $status, array $context) use (&$events): void {
                $events[] = [$status, $context];
            }
        ));

        $provider->chat(
            [new Message('user', 'Go')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        return $events;
    }

    /**
     * @param array<int, array{0: string, 1: array<string, mixed>}> $events
     * @param string $status
     *
     * @return array<string, mixed>|null
     */
    private function firstContext(array $events, string $status): ?array {
        foreach ($events as [$eventStatus, $context]) {
            if ($eventStatus === $status) {
                return $context;
            }
        }

        return null;
    }

    public function testSuccessEmitsToolCompletedWithOkAndCallId(): void {
        $tool = new Tool(
            'get_weather',
            'Get weather',
            ['type' => 'object', 'properties' => []],
            fn (array $args): string => json_encode(['temp' => 22])
        );

        $events = $this->runAndCapture($this->clientWithToolCall('get_weather'), $tool);

        $completed = $this->firstContext($events, Status::TOOL_COMPLETED);
        $this->assertNotNull($completed, 'TOOL_COMPLETED should be emitted on success');
        $this->assertTrue($completed['ok']);
        $this->assertSame('get_weather', $completed['tool']);
        $this->assertSame('tool_1', $completed['tool_call_id']);
        $this->assertArrayHasKey('duration_ms', $completed);
        $this->assertFalse($completed['multimodal']);
        $this->assertSame(0, $completed['parts_count']);

        // No failure event on success.
        $this->assertNull($this->firstContext($events, Status::TOOL_FAILED));
    }

    public function testCallingAndExecutingCarryCallId(): void {
        $tool = new Tool(
            'get_weather',
            'Get weather',
            ['type' => 'object', 'properties' => []],
            fn (array $args): string => 'ok'
        );

        $events = $this->runAndCapture($this->clientWithToolCall('get_weather'), $tool);

        $calling = $this->firstContext($events, Status::TOOL_CALLING);
        $executing = $this->firstContext($events, Status::TOOL_EXECUTING);

        $this->assertSame('tool_1', $calling['tool_call_id']);
        $this->assertSame('tool_1', $executing['tool_call_id']);
    }

    public function testThrownExceptionEmitsToolFailedWithExceptionType(): void {
        $tool = new Tool(
            'boom',
            'Throws',
            ['type' => 'object', 'properties' => []],
            function (array $args): string {
                throw new \RuntimeException('kaboom');
            }
        );

        $events = $this->runAndCapture($this->clientWithToolCall('boom'), $tool);

        $failed = $this->firstContext($events, Status::TOOL_FAILED);
        $this->assertNotNull($failed, 'TOOL_FAILED should be emitted when a tool throws');
        $this->assertFalse($failed['ok']);
        $this->assertSame(Status::TOOL_ERROR_EXCEPTION, $failed['error_type']);
        $this->assertSame('kaboom', $failed['error']);
        $this->assertSame(\RuntimeException::class, $failed['exception_class']);
        $this->assertSame('tool_1', $failed['tool_call_id']);

        // No success event on failure.
        $this->assertNull($this->firstContext($events, Status::TOOL_COMPLETED));
    }

    public function testToolResponseErrorEmitsToolFailedWithReturnedType(): void {
        $tool = new Tool(
            'lookup',
            'Lookup',
            ['type' => 'object', 'properties' => []],
            fn (array $args): ToolResponse => ToolResponse::error('not found in db')
        );

        $events = $this->runAndCapture($this->clientWithToolCall('lookup'), $tool);

        $failed = $this->firstContext($events, Status::TOOL_FAILED);
        $this->assertNotNull($failed);
        $this->assertFalse($failed['ok']);
        $this->assertSame(Status::TOOL_ERROR_RETURNED, $failed['error_type']);
        $this->assertSame('not found in db', $failed['error']);
        $this->assertArrayNotHasKey('exception_class', $failed);
    }

    public function testUnknownToolEmitsToolFailedWithNotFoundType(): void {
        // The model asks for 'ghost' but we only register 'real'.
        $tool = new Tool(
            'real',
            'Real tool',
            ['type' => 'object', 'properties' => []],
            fn (array $args): string => 'ok'
        );

        $events = $this->runAndCapture($this->clientWithToolCall('ghost'), $tool);

        $failed = $this->firstContext($events, Status::TOOL_FAILED);
        $this->assertNotNull($failed);
        $this->assertFalse($failed['ok']);
        $this->assertSame(Status::TOOL_ERROR_NOT_FOUND, $failed['error_type']);
        $this->assertStringContainsString('ghost', $failed['error']);
    }

    public function testThrowingToolDoesNotAbortBatch(): void {
        // Two tool calls in one response: first throws, second succeeds.
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['toolUse' => ['toolUseId' => 'tc_a', 'name' => 'boom', 'input' => []]],
                ['toolUse' => ['toolUseId' => 'tc_b', 'name' => 'ok_tool', 'input' => []]],
            ]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
        ])));
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Done.']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 30, 'outputTokens' => 10],
        ])));

        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            apiKey: 'test-key',
            apiMethod: ApiMethod::CONVERSE,
        ));
        $provider->setHttpClient($client);

        $boom = new Tool('boom', 'Throws', ['type' => 'object', 'properties' => []],
            function (array $args): string {
                throw new \RuntimeException('first fails');
            });
        $okTool = new Tool('ok_tool', 'Works', ['type' => 'object', 'properties' => []],
            fn (array $args): string => 'second succeeds');

        $events = [];
        $provider->setStatusEmitter(new CallbackStatusEmitter(
            function (string $status, array $context) use (&$events): void {
                $events[] = [$status, $context];
            }
        ));

        $provider->chat(
            [new Message('user', 'Go')],
            ['tools' => [$boom, $okTool], 'auto_execute_tools' => true]
        );

        // The first tool failed but the second still ran and succeeded.
        $failedTools = [];
        $completedTools = [];

        foreach ($events as [$status, $context]) {
            if ($status === Status::TOOL_FAILED) {
                $failedTools[] = $context['tool'];
            } elseif ($status === Status::TOOL_COMPLETED) {
                $completedTools[] = $context['tool'];
            }
        }

        $this->assertContains('boom', $failedTools);
        $this->assertContains('ok_tool', $completedTools);
    }

    public function testFormatterRendersToolFailedTemplate(): void {
        $formatter = new StatusMessageFormatter();

        $message = $formatter->format(Status::TOOL_FAILED, [
            'tool' => 'get_weather',
            'duration_ms' => 12,
            'error' => 'timeout',
        ]);

        $this->assertSame('get_weather failed after 12ms: timeout', $message);
    }
}
