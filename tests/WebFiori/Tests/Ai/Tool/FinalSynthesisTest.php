<?php

namespace WebFiori\Tests\Ai\Tool;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Status;
use WebFiori\Ai\Tool\Tool;

/**
 * Final synthesis pass when the non-streaming tool loop hits its iteration cap
 * (issue #189, ADR-0059).
 */
class FinalSynthesisTest extends TestCase {
    public function testSynthesisFiresWhenCapHitWithPendingToolCalls(): void {
        // maxIterations=1: one tool round, then the model still wants tools →
        // cap hit with pending tool calls → synthesis pass produces text.
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse());     // initial: tool call
        $client->addResponse($this->toolCallResponse());     // after tool: still a tool call (cap now hit)
        $client->addResponse($this->textResponse('Final synthesized answer')); // synthesis (tools-off)

        $provider = $this->provider($client);
        $response = $provider->chat([new Message('user', 'go')], [
            ChatOption::TOOLS => [$this->tool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::MAX_TOOL_ITERATIONS => 1,
            ChatOption::FINAL_SYNTHESIS_ON_MAX_ITERATIONS => true,
        ]);

        $this->assertSame('Final synthesized answer', $response->getMessage()->getContent());
        $this->assertFalse($response->hasToolCalls());
        $this->assertCount(3, $client->getRequests(), 'initial + 1 loop round + synthesis');

        // The synthesis (3rd) request must carry no tools.
        $lastBody = json_decode($client->getRequests()[2]->getBody(), true);
        $this->assertArrayNotHasKey('tools', $lastBody, 'synthesis request must have tools removed');
    }

    public function testNoSynthesisWhenOptionDisabled(): void {
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse());
        $client->addResponse($this->toolCallResponse()); // cap hit, still tool calls

        $provider = $this->provider($client);
        $response = $provider->chat([new Message('user', 'go')], [
            ChatOption::TOOLS => [$this->tool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::MAX_TOOL_ITERATIONS => 1,
            // option omitted → default false
        ]);

        // Current behavior: returns the dangling tool-call response, no extra request.
        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(2, $client->getRequests(), 'no synthesis request when disabled');
    }

    public function testNoSynthesisWhenLoopConvergesNormally(): void {
        // Model stops calling tools before the cap → no synthesis even if enabled.
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse());             // initial: tool call
        $client->addResponse($this->textResponse('Converged answer')); // after tool: plain text, loop ends

        $provider = $this->provider($client);
        $response = $provider->chat([new Message('user', 'go')], [
            ChatOption::TOOLS => [$this->tool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::MAX_TOOL_ITERATIONS => 5,
            ChatOption::FINAL_SYNTHESIS_ON_MAX_ITERATIONS => true,
        ]);

        $this->assertSame('Converged answer', $response->getMessage()->getContent());
        $this->assertCount(2, $client->getRequests(), 'no synthesis request on normal convergence');
    }

    public function testSynthesisPromptIsInjectedWhenSet(): void {
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse());
        $client->addResponse($this->toolCallResponse());
        $client->addResponse($this->textResponse('answer'));

        $provider = $this->provider($client);
        $provider->chat([new Message('user', 'go')], [
            ChatOption::TOOLS => [$this->tool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::MAX_TOOL_ITERATIONS => 1,
            ChatOption::FINAL_SYNTHESIS_ON_MAX_ITERATIONS => true,
            ChatOption::FINAL_SYNTHESIS_PROMPT => 'Answer now with what you have.',
        ]);

        $synthBody = json_decode($client->getRequests()[2]->getBody(), true);
        $contents = json_encode($synthBody['messages'] ?? []);
        $this->assertStringContainsString('Answer now with what you have.', $contents);
    }

    public function testNoPromptInjectedWhenNull(): void {
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse());
        $client->addResponse($this->toolCallResponse());
        $client->addResponse($this->textResponse('answer'));

        $provider = $this->provider($client);
        $provider->chat([new Message('user', 'unique-user-text-xyz')], [
            ChatOption::TOOLS => [$this->tool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::MAX_TOOL_ITERATIONS => 1,
            ChatOption::FINAL_SYNTHESIS_ON_MAX_ITERATIONS => true,
        ]);

        $synthBody = json_decode($client->getRequests()[2]->getBody(), true);
        $roles = array_column($synthBody['messages'] ?? [], 'role');
        // Only the original user message, no injected synthesis user turn.
        $this->assertSame(1, count(array_filter($roles, fn ($r) => $r === 'user')));
    }

    public function testSynthesisEmitsStatusAndMetric(): void {
        $client = new FakeHttpClient();
        $client->addResponse($this->toolCallResponse());
        $client->addResponse($this->toolCallResponse());
        $client->addResponse($this->textResponse('answer'));

        $provider = $this->provider($client);

        $statuses = [];
        $provider->setStatusEmitter(new CallbackStatusEmitter(
            function (string $status, array $context) use (&$statuses): void {
                $statuses[] = $status;
            }
        ));

        $metrics = [];
        $provider->setMetricsCallback(function (string $event, array $data) use (&$metrics): void {
            $metrics[] = $event;
        });

        $provider->chat([new Message('user', 'go')], [
            ChatOption::TOOLS => [$this->tool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::MAX_TOOL_ITERATIONS => 1,
            ChatOption::FINAL_SYNTHESIS_ON_MAX_ITERATIONS => true,
        ]);

        $this->assertContains(Status::FINAL_SYNTHESIS, $statuses);
        $this->assertContains('request.final_synthesis', $metrics);
    }

    private function provider(FakeHttpClient $client): OpenAIClient {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);

        return $provider;
    }

    private function tool(): Tool {
        return new Tool(
            'get_weather',
            'Gets weather',
            ['type' => 'object', 'properties' => []],
            fn (array $args): string => json_encode(['temp' => 20])
        );
    }

    private function toolCallResponse(): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_'.uniqid(),
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
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
