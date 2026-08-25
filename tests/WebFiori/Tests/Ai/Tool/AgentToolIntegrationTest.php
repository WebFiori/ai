<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Tool;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Provider\ClientConfig;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\AgentMessageStrategy;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Usage;

/**
 * Integration tests for AgentTool working with AbstractClient's auto_execute_tools loop.
 *
 * These tests verify that when an orchestrator provider returns a tool call
 * targeting an AgentTool, the full cycle completes: the agent tool is executed
 * (calling its sub-agent provider), and the result is fed back to the orchestrator,
 * producing a final response.
 */
class AgentToolIntegrationTest extends TestCase {
    /**
     * @test
     *
     * Tests the full auto_execute_tools loop with an AgentTool:
     * 1. Orchestrator receives user message
     * 2. Orchestrator returns a tool call to the agent tool
     * 3. AgentTool executes: calls its sub-agent provider
     * 4. Tool result is sent back to orchestrator
     * 5. Orchestrator returns a final response incorporating the agent's output
     */
    public function testAgentToolWorksWithAutoExecuteTools(): void {
        // Set up the sub-agent provider (what the AgentTool calls internally)
        $subAgentProvider = new CapturingMockProvider('sub-agent', 'The capital of France is Paris.');

        // Create the AgentTool
        $agentTool = new AgentTool(
            'geography_expert',
            'An agent that answers geography questions',
            $subAgentProvider,
            'You are a geography expert.'
        );

        // Set up the orchestrator (AbstractClient) with FakeHttpClient
        $orchestrator = $this->createOrchestratorProvider();
        $httpClient = new FakeHttpClient();

        // First response: orchestrator decides to call the agent tool
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => '',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'tool_calls' => [
                [
                    'id' => 'call_123',
                    'name' => 'geography_expert',
                    'arguments' => ['task' => 'What is the capital of France?'],
                ],
            ],
        ])));

        // Second response: orchestrator incorporates the agent's result
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'According to our geography expert, the capital of France is Paris.',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 20,
            'completion_tokens' => 10,
        ])));

        $orchestrator->setHttpClient($httpClient);

        // Execute chat with auto_execute_tools
        $response = $orchestrator->chat(
            [new Message('user', 'What is the capital of France?')],
            [
                ChatOption::TOOLS => [$agentTool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
            ]
        );

        // Verify final response incorporates agent output
        $this->assertStringContainsString('capital of France is Paris', $response->getMessage()->getContent());

        // Verify the sub-agent was actually called
        $this->assertSame(1, $subAgentProvider->callCount);

        // Verify the sub-agent received the correct task
        $this->assertCount(2, $subAgentProvider->lastMessages);
        $this->assertSame('system', $subAgentProvider->lastMessages[0]->getRole());
        $this->assertSame('You are a geography expert.', $subAgentProvider->lastMessages[0]->getContent());
        $this->assertSame('user', $subAgentProvider->lastMessages[1]->getRole());
        $this->assertSame('What is the capital of France?', $subAgentProvider->lastMessages[1]->getContent());
    }

    /**
     * @test
     *
     * Tests that FULL_HISTORY strategy causes the sub-agent to receive
     * the full conversation context when invoked through the auto_execute_tools loop.
     */
    public function testAgentToolFullHistoryReceivesContext(): void {
        $subAgentProvider = new CapturingMockProvider('sub-agent', 'Based on the context, the answer is 42.');

        $agentTool = new AgentTool(
            'context_aware_agent',
            'An agent that uses full conversation history',
            $subAgentProvider,
            'You are a context-aware assistant.',
            AgentMessageStrategy::FULL_HISTORY
        );

        $orchestrator = $this->createOrchestratorProvider();
        $httpClient = new FakeHttpClient();

        // First response: tool call
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => '',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'tool_calls' => [
                [
                    'id' => 'call_456',
                    'name' => 'context_aware_agent',
                    'arguments' => ['task' => 'Summarize the conversation so far.'],
                ],
            ],
        ])));

        // Second response: final
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'Summary complete.',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 30,
            'completion_tokens' => 5,
        ])));

        $orchestrator->setHttpClient($httpClient);

        // Provide a multi-message conversation
        $messages = [
            new Message('system', 'You are an orchestrator.'),
            new Message('user', 'Tell me about PHP.'),
            new Message('assistant', 'PHP is a programming language.'),
            new Message('user', 'Summarize everything.'),
        ];

        $response = $orchestrator->chat(
            $messages,
            [
                ChatOption::TOOLS => [$agentTool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
            ]
        );

        // Verify the sub-agent provider was called
        $this->assertSame(1, $subAgentProvider->callCount);

        // Verify the sub-agent received the full history context
        // The messages passed to sub-agent should be:
        // [0] system (from profile) + context messages + [last] user (task)
        $subMessages = $subAgentProvider->lastMessages;

        // First message should be system prompt from the profile
        $this->assertSame('system', $subMessages[0]->getRole());
        $this->assertSame('You are a context-aware assistant.', $subMessages[0]->getContent());

        // Context messages should include the orchestrator conversation
        // The context is set from the messages array in executeTools, which at the point
        // of tool execution includes the original messages + the assistant message with tool calls
        // So we expect: system(profile) + original_messages + assistant_tool_call + user(task)
        // But the key check is that context messages ARE present (more than just system + task)
        $this->assertGreaterThan(2, count($subMessages));

        // Last message should be the task
        $lastMsg = $subMessages[count($subMessages) - 1];
        $this->assertSame('user', $lastMsg->getRole());
        $this->assertSame('Summarize the conversation so far.', $lastMsg->getContent());

        // Verify some context messages are from the original conversation
        $foundContextMessage = false;

        foreach ($subMessages as $msg) {
            if ($msg->getContent() === 'Tell me about PHP.' || $msg->getContent() === 'PHP is a programming language.') {
                $foundContextMessage = true;

                break;
            }
        }

        $this->assertTrue($foundContextMessage, 'Sub-agent should receive conversation context from FULL_HISTORY strategy');
    }

    /**
     * @test
     *
     * Tests that an AgentTool whose profile has sub-tools passes them correctly
     * to the sub-agent provider.
     */
    public function testAgentToolWithSubTools(): void {
        $subAgentProvider = new CapturingMockProvider('sub-agent', 'Calculated: 42');

        // Create a sub-tool for the agent
        $calculatorTool = new Tool(
            'calculator',
            'Performs arithmetic',
            [
                'type' => 'object',
                'properties' => [
                    'expression' => ['type' => 'string', 'description' => 'Math expression'],
                ],
                'required' => ['expression'],
            ],
            fn(array $args) => (string) eval('return ' . $args['expression'] . ';')
        );

        // Profile with tools
        $profile = new AgentProfile(
            identity: 'You are a math agent with calculator access.',
            skills: ['arithmetic', 'algebra'],
            tools: [$calculatorTool],
        );

        $agentTool = new AgentTool(
            'math_agent',
            'An agent that does math with calculator',
            $subAgentProvider,
            $profile
        );

        $orchestrator = $this->createOrchestratorProvider();
        $httpClient = new FakeHttpClient();

        // First response: tool call to math_agent
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => '',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'tool_calls' => [
                [
                    'id' => 'call_789',
                    'name' => 'math_agent',
                    'arguments' => ['task' => 'Calculate 6 * 7'],
                ],
            ],
        ])));

        // Second response: final
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'The result is 42.',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 20,
            'completion_tokens' => 5,
        ])));

        $orchestrator->setHttpClient($httpClient);

        $response = $orchestrator->chat(
            [new Message('user', 'What is 6 times 7?')],
            [
                ChatOption::TOOLS => [$agentTool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
            ]
        );

        // Verify the sub-agent was called with tools in options
        $this->assertSame(1, $subAgentProvider->callCount);
        $this->assertArrayHasKey(ChatOption::TOOLS, $subAgentProvider->lastOptions);
        $this->assertCount(1, $subAgentProvider->lastOptions[ChatOption::TOOLS]);
        $this->assertSame($calculatorTool, $subAgentProvider->lastOptions[ChatOption::TOOLS][0]);
        $this->assertTrue($subAgentProvider->lastOptions[ChatOption::AUTO_EXECUTE_TOOLS]);

        // Verify the system prompt includes the profile render
        $this->assertStringContainsString('You are a math agent', $subAgentProvider->lastMessages[0]->getContent());
        $this->assertStringContainsString('## Skills', $subAgentProvider->lastMessages[0]->getContent());
    }

    /**
     * @test
     *
     * Tests that multiple AgentTools can be called in the same conversation
     * when the orchestrator issues tool calls for both.
     */
    public function testMultipleAgentTools(): void {
        $researcherProvider = new CapturingMockProvider('researcher', 'Research result: PHP was created in 1994.');
        $writerProvider = new CapturingMockProvider('writer', 'Article: PHP is a widely-used language created in 1994.');

        $researcherTool = new AgentTool(
            'researcher',
            'Researches topics and finds facts',
            $researcherProvider,
            'You are a researcher. Find factual information.'
        );

        $writerTool = new AgentTool(
            'writer',
            'Writes articles based on research',
            $writerProvider,
            'You are a writer. Write engaging articles.'
        );

        $orchestrator = $this->createOrchestratorProvider();
        $httpClient = new FakeHttpClient();

        // First response: orchestrator calls the researcher
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => '',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'tool_calls' => [
                [
                    'id' => 'call_research',
                    'name' => 'researcher',
                    'arguments' => ['task' => 'When was PHP created?'],
                ],
            ],
        ])));

        // Second response: orchestrator calls the writer with the research result
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => '',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 20,
            'completion_tokens' => 5,
            'tool_calls' => [
                [
                    'id' => 'call_writer',
                    'name' => 'writer',
                    'arguments' => ['task' => 'Write an article about PHP history based on: PHP was created in 1994.'],
                ],
            ],
        ])));

        // Third response: final response after both tools have executed
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'Here is the final article about PHP history.',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 30,
            'completion_tokens' => 10,
        ])));

        $orchestrator->setHttpClient($httpClient);

        $response = $orchestrator->chat(
            [new Message('user', 'Write an article about PHP history.')],
            [
                ChatOption::TOOLS => [$researcherTool, $writerTool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
            ]
        );

        // Both agents should have been called
        $this->assertSame(1, $researcherProvider->callCount);
        $this->assertSame(1, $writerProvider->callCount);

        // Researcher received the correct task
        $this->assertSame('user', $researcherProvider->lastMessages[1]->getRole());
        $this->assertSame('When was PHP created?', $researcherProvider->lastMessages[1]->getContent());

        // Writer received the correct task
        $this->assertSame('user', $writerProvider->lastMessages[1]->getRole());
        $this->assertStringContainsString('PHP was created in 1994', $writerProvider->lastMessages[1]->getContent());

        // Final response is from the orchestrator
        $this->assertSame('Here is the final article about PHP history.', $response->getMessage()->getContent());
    }

    /**
     * @test
     *
     * Tests that multiple agent tools can be called in parallel (both tool calls
     * in a single response from the orchestrator).
     */
    public function testMultipleAgentToolsCalledInParallel(): void {
        $factsProvider = new CapturingMockProvider('facts', 'PHP was created by Rasmus Lerdorf.');
        $statsProvider = new CapturingMockProvider('stats', 'PHP powers 77% of websites.');

        $factsTool = new AgentTool(
            'facts_agent',
            'Gets facts',
            $factsProvider,
            'You provide facts.'
        );

        $statsTool = new AgentTool(
            'stats_agent',
            'Gets statistics',
            $statsProvider,
            'You provide statistics.'
        );

        $orchestrator = $this->createOrchestratorProvider();
        $httpClient = new FakeHttpClient();

        // First response: orchestrator calls BOTH agent tools at once
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => '',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'tool_calls' => [
                [
                    'id' => 'call_facts',
                    'name' => 'facts_agent',
                    'arguments' => ['task' => 'Who created PHP?'],
                ],
                [
                    'id' => 'call_stats',
                    'name' => 'stats_agent',
                    'arguments' => ['task' => 'What percentage of websites use PHP?'],
                ],
            ],
        ])));

        // Second response: final
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'PHP was created by Rasmus Lerdorf and powers 77% of websites.',
            'model' => 'orchestrator-model',
            'prompt_tokens' => 30,
            'completion_tokens' => 10,
        ])));

        $orchestrator->setHttpClient($httpClient);

        $response = $orchestrator->chat(
            [new Message('user', 'Tell me about PHP.')],
            [
                ChatOption::TOOLS => [$factsTool, $statsTool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
            ]
        );

        // Both agents should have been called
        $this->assertSame(1, $factsProvider->callCount);
        $this->assertSame(1, $statsProvider->callCount);

        // Final response incorporates both results
        $this->assertStringContainsString('Rasmus Lerdorf', $response->getMessage()->getContent());
        $this->assertStringContainsString('77%', $response->getMessage()->getContent());
    }

    /**
     * Creates a test orchestrator provider (concrete AbstractClient subclass)
     * that simulates tool calling behavior via FakeHttpClient.
     *
     * The orchestrator parses responses that may include tool_calls in the JSON.
     */
    private function createOrchestratorProvider(): AbstractClient {
        $testConfig = new class extends ClientConfig {
            public function __construct() {
                parent::__construct('orchestrator-model');
            }

            public function toArray(): array {
                return ['model' => 'orchestrator-model'];
            }
        };

        return new class($testConfig) extends AbstractClient {
            public function getName(): string {
                return 'test-orchestrator';
            }

            protected function buildChatRequest(array $messages, array $options): HttpRequest {
                $model = $options['model'] ?? $this->getConfig('model');
                $body = [
                    'model' => $model,
                    'messages' => array_map(fn(Message $m) => [
                        'role' => $m->getRole(),
                        'content' => $m->getContent(),
                    ], $messages),
                ];

                if (isset($options['temperature'])) {
                    $body['temperature'] = $options['temperature'];
                }

                return new HttpRequest(
                    'POST',
                    'https://api.test-orchestrator.com/v1/chat',
                    ['Content-Type' => 'application/json'],
                    json_encode($body)
                );
            }

            protected function buildEmbedRequest(string|array $input, array $options): HttpRequest {
                return new HttpRequest('POST', 'https://api.test-orchestrator.com/v1/embed');
            }

            protected function buildImageRequest(ImageRequest $request): HttpRequest {
                return new HttpRequest('POST', 'https://api.test-orchestrator.com/v1/images');
            }

            protected function buildStreamChatRequest(array $messages, array $options): HttpRequest {
                return new HttpRequest('POST', 'https://api.test-orchestrator.com/v1/chat/stream');
            }

            protected function doStreamChat(
                HttpRequest $request,
                callable $onToken,
                ?callable $onComplete,
                ?callable $onError
            ): void {
            }

            protected function handleErrorResponse(HttpResponse $response): void {
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    return;
                }

                throw new \WebFiori\Ai\Exception\ProviderException(
                    'Provider error',
                    $status
                );
            }

            protected function parseChatResponse(HttpResponse $response): ChatResponse {
                $data = $response->getJson();
                $toolCalls = [];

                if (isset($data['tool_calls']) && is_array($data['tool_calls'])) {
                    foreach ($data['tool_calls'] as $tc) {
                        $toolCalls[] = new ToolCall(
                            $tc['id'],
                            $tc['name'],
                            $tc['arguments'] ?? []
                        );
                    }
                }

                $message = new Message(
                    'assistant',
                    $data['content'] ?? '',
                    $toolCalls
                );

                return new ChatResponse(
                    $message,
                    $data['model'] ?? 'orchestrator-model',
                    new Usage($data['prompt_tokens'] ?? 0, $data['completion_tokens'] ?? 0),
                    empty($toolCalls) ? 'stop' : 'tool_calls'
                );
            }

            protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
                return new EmbeddingResponse([[0.1, 0.2]], 'orchestrator-model');
            }

            protected function parseImageResponse(HttpResponse $response): ImageResponse {
                return new ImageResponse([], 'orchestrator-model');
            }

            public function healthCheck(int $timeout = 5): HealthCheckResult {
                return HealthCheckResult::success(1, 'test-orchestrator');
            }
        };
    }
}
