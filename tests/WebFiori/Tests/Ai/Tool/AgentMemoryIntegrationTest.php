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
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Provider\ClientConfig;
use WebFiori\Ai\Provider\ProviderInterface;
use WebFiori\Ai\Rag\LocalRagProvider;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\AgentMemory;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\KeywordRememberStrategy;
use WebFiori\Ai\Tool\ManualRememberStrategy;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Usage;

/**
 * Integration tests for AgentMemory with AgentTool and AbstractClient.
 */
class AgentMemoryIntegrationTest extends TestCase {
    /**
     * Creates a mock embedder that returns vectors based on input keywords.
     * Uses a simple mapping to allow testing similarity.
     */
    private function createSmartEmbedder(): ProviderInterface {
        return new class implements ProviderInterface {
            public function chat(array $messages, array $options = []): ChatResponse {
                return new ChatResponse(new Message('assistant', ''), 'mock');
            }

            public function embed(string|array $input, array $options = []): EmbeddingResponse {
                $text = is_array($input) ? $input[0] : $input;

                // Simple keyword-based vector assignment for testing similarity
                if (stripos($text, 'dark mode') !== false || stripos($text, 'theme') !== false) {
                    $vector = [1.0, 0.0, 0.0];
                } elseif (stripos($text, 'PostgreSQL') !== false || stripos($text, 'database') !== false) {
                    $vector = [0.0, 1.0, 0.0];
                } elseif (stripos($text, 'PHP') !== false || stripos($text, 'language') !== false) {
                    $vector = [0.0, 0.0, 1.0];
                } else {
                    // Default vector somewhat similar to all
                    $vector = [0.3, 0.3, 0.3];
                }

                return new EmbeddingResponse([$vector], 'mock-embed');
            }

            public function generateImage(ImageRequest $request): ImageResponse {
                return new ImageResponse([], 'mock');
            }

            public function getName(): string {
                return 'smart-embedder';
            }

            public function healthCheck(int $timeout = 5): HealthCheckResult {
                return HealthCheckResult::success(1, 'mock');
            }

            public function setHttpClient(HttpClientInterface $client): void {
            }

            public function setLogCallback(?callable $callback): void {
            }

            public function streamChat(
                array $messages,
                callable $onToken,
                ?callable $onComplete = null,
                ?callable $onError = null,
                array $options = []
            ): void {
            }
        };
    }

    // =========================================================================
    // AgentTool + Memory Integration
    // =========================================================================

    public function testAgentToolRecallsMemoriesBeforeExecution(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createSmartEmbedder();
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, minScore: 0.5);

        // Pre-store a memory about dark mode
        $memory->remember('User prefers dark mode for all interfaces.');

        // Set up an AgentTool with memory
        $subProvider = new CapturingMockProvider('sub-agent', 'I will use dark mode.');
        $tool = new AgentTool(
            'designer',
            'Designs UIs',
            $subProvider,
            'You are a UI designer.',
            memory: $memory,
        );

        // Execute with a task about themes (same vector space as 'dark mode')
        $tool->execute(['task' => 'What theme should I use for the dashboard?']);

        // The system prompt should contain the recalled memory
        $systemMessage = $subProvider->lastMessages[0];
        $this->assertSame('system', $systemMessage->getRole());
        $this->assertStringContainsString('Relevant Knowledge (from memory)', $systemMessage->getContent());
        $this->assertStringContainsString('User prefers dark mode', $systemMessage->getContent());
    }

    public function testAgentToolRemembersAfterExecution(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createSmartEmbedder();
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, minScore: 0.5);

        $subProvider = new CapturingMockProvider('sub-agent', 'Noted your preference.');
        $strategy = new KeywordRememberStrategy();

        $tool = new AgentTool(
            'helper',
            'Helps users',
            $subProvider,
            'You are a helpful assistant.',
            memory: $memory,
            rememberStrategy: $strategy,
        );

        // Set conversation context with a keyword-matching message
        $tool->setConversationContext([
            new Message(Role::USER, 'Actually, I prefer tabs over spaces.'),
        ]);

        $tool->execute(['task' => 'Help me with coding style.']);

        // Memory should have stored the fact from the keyword strategy
        $this->assertGreaterThanOrEqual(1, $store->count());
    }

    // =========================================================================
    // AbstractClient + Memory Integration
    // =========================================================================

    public function testAbstractClientRecallsMemories(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createSmartEmbedder();
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, minScore: 0.5);

        // Store a memory about PostgreSQL
        $memory->remember('The project uses PostgreSQL as its primary database.');

        // Create an orchestrator client with memory
        $client = $this->createOrchestratorProvider();
        $client->setMemory($memory);

        $httpClient = new FakeHttpClient();
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'You should connect to PostgreSQL on port 5432.',
            'model' => 'test-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ])));
        $client->setHttpClient($httpClient);

        $response = $client->chat([
            new Message(Role::SYSTEM, 'You are a DevOps assistant.'),
            new Message(Role::USER, 'How do I connect to the database?'),
        ]);

        // Verify the request body contains the injected memory
        $lastRequest = $httpClient->getLastRequest();
        $body = json_decode($lastRequest->getBody(), true);
        $systemContent = $body['messages'][0]['content'];
        $this->assertStringContainsString('Relevant Knowledge (from memory)', $systemContent);
        $this->assertStringContainsString('PostgreSQL', $systemContent);
    }

    public function testAbstractClientRemembersAfterResponse(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createSmartEmbedder();
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, minScore: 0.5);

        $client = $this->createOrchestratorProvider();
        $client->setMemory($memory);
        $client->setRememberStrategy(new KeywordRememberStrategy());

        $httpClient = new FakeHttpClient();
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'content' => 'Got it, I will remember that.',
            'model' => 'test-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ])));
        $client->setHttpClient($httpClient);

        // Send a message with a correction keyword
        $response = $client->chat([
            new Message(Role::USER, 'Actually, our production server uses PHP 8.3, not 8.2.'),
        ]);

        // The keyword strategy should have extracted the fact and stored it
        $this->assertGreaterThanOrEqual(1, $store->count());
    }

    public function testSharedMemoryBetweenAgents(): void {
        $store = new InMemoryVectorStore();
        $embedder = $this->createSmartEmbedder();
        $ragProvider = new LocalRagProvider($store, $embedder);
        $memory = new AgentMemory($ragProvider, minScore: 0.5);

        // Agent A stores a fact
        $providerA = new CapturingMockProvider('agent-a', 'Done.');
        $agentA = new AgentTool(
            'agent_a',
            'First agent',
            $providerA,
            'You are agent A.',
            memory: $memory,
            rememberStrategy: new KeywordRememberStrategy(),
        );

        // Set context with keyword-matching message for agent A
        $agentA->setConversationContext([
            new Message(Role::USER, 'Remember that the API uses PostgreSQL.'),
        ]);
        $agentA->execute(['task' => 'Note database preference.']);

        // Agent B should be able to recall the memory stored by Agent A
        $providerB = new CapturingMockProvider('agent-b', 'Using PostgreSQL.');
        $agentB = new AgentTool(
            'agent_b',
            'Second agent',
            $providerB,
            'You are agent B.',
            memory: $memory,
        );

        $agentB->execute(['task' => 'What database do we use?']);

        // Agent B's system prompt should contain the memory from Agent A
        $systemMessage = $providerB->lastMessages[0];
        $this->assertStringContainsString('Relevant Knowledge (from memory)', $systemMessage->getContent());
        $this->assertStringContainsString('PostgreSQL', $systemMessage->getContent());
    }

    // =========================================================================
    // Helper: creates a concrete AbstractClient for testing
    // =========================================================================

    private function createOrchestratorProvider(): AbstractClient {
        $testConfig = new class extends ClientConfig {
            public function __construct() {
                parent::__construct('test-model');
            }

            public function toArray(): array {
                return ['model' => 'test-model'];
            }
        };

        return new class($testConfig) extends AbstractClient {
            public function getName(): string {
                return 'test-client';
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
                    'https://api.test.com/v1/chat',
                    ['Content-Type' => 'application/json'],
                    json_encode($body)
                );
            }

            protected function buildEmbedRequest(string|array $input, array $options): HttpRequest {
                return new HttpRequest('POST', 'https://api.test.com/v1/embed');
            }

            protected function buildImageRequest(ImageRequest $request): HttpRequest {
                return new HttpRequest('POST', 'https://api.test.com/v1/images');
            }

            protected function buildStreamChatRequest(array $messages, array $options): HttpRequest {
                return new HttpRequest('POST', 'https://api.test.com/v1/chat/stream');
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
                    $data['model'] ?? 'test-model',
                    new Usage($data['prompt_tokens'] ?? 0, $data['completion_tokens'] ?? 0),
                    empty($toolCalls) ? 'stop' : 'tool_calls'
                );
            }

            protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
                return new EmbeddingResponse([[0.1, 0.2]], 'test-model');
            }

            protected function parseImageResponse(HttpResponse $response): ImageResponse {
                return new ImageResponse([], 'test-model');
            }

            public function healthCheck(int $timeout = 5): HealthCheckResult {
                return HealthCheckResult::success(1, 'test-client');
            }
        };
    }
}
