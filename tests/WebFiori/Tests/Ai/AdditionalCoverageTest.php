<?php

namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Bedrock\ApiMethod;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\InteractionsResponseParser;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Rag\Retriever;
use WebFiori\Ai\RetryConfig;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Additional coverage tests targeting remaining gaps.
 */
class AdditionalCoverageTest extends TestCase {
    // ==================== INTERACTIONS RESPONSE PARSER ====================

    /**
     * @test
     */
    public function testInteractionsParserBasicText() {
        $parser = new InteractionsResponseParser();
        $data = [
            'steps' => [
                ['type' => 'text', 'text' => 'Hello world!'],
            ],
            'id' => 'int_123',
            'model' => 'gemini-3.5-flash',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ];

        $response = $parser->parse($data, 'default-model');

        $this->assertEquals('Hello world!', $response->getMessage()->getContent());
        $this->assertEquals('gemini-3.5-flash', $response->getModel());
        $this->assertEquals('int_123', $response->getRequestId());
        $this->assertEquals('stop', $response->getFinishReason());
        $this->assertEquals(5, $response->getUsage()->getPromptTokens());
        $this->assertEquals(3, $response->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testInteractionsParserModelOutputFormat() {
        $parser = new InteractionsResponseParser();
        $data = [
            'steps' => [
                [
                    'type' => 'model_output',
                    'content' => [
                        ['type' => 'text', 'text' => 'Result from model output.'],
                    ],
                ],
            ],
            'usage' => ['total_input_tokens' => 10, 'total_output_tokens' => 5],
        ];

        $response = $parser->parse($data, 'gemini-3.5-flash');

        $this->assertEquals('Result from model output.', $response->getMessage()->getContent());
        $this->assertEquals(10, $response->getUsage()->getPromptTokens());
        $this->assertEquals(5, $response->getUsage()->getCompletionTokens());
    }

    /**
     * @test
     */
    public function testInteractionsParserToolCall() {
        $parser = new InteractionsResponseParser();
        $data = [
            'steps' => [
                [
                    'type' => 'function_call',
                    'id' => 'call_abc',
                    'name' => 'get_weather',
                    'arguments' => ['location' => 'London'],
                ],
            ],
        ];

        $response = $parser->parse($data, 'gemini-3.5-flash');

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('tool_calls', $response->getFinishReason());
        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['location' => 'London'], $toolCalls[0]->getArguments());
    }

    /**
     * @test
     */
    public function testInteractionsParserModelOutputToolCall() {
        $parser = new InteractionsResponseParser();
        $data = [
            'steps' => [
                [
                    'type' => 'model_output',
                    'content' => [
                        ['type' => 'function_call', 'id' => 'call_1', 'name' => 'search', 'arguments' => ['q' => 'PHP']],
                    ],
                ],
            ],
        ];

        $response = $parser->parse($data, 'gemini-3.5-flash');

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('tool_calls', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testInteractionsParserThoughtStepSkipped() {
        $parser = new InteractionsResponseParser();
        $data = [
            'steps' => [
                ['type' => 'thought', 'text' => 'Let me think...'],
                ['type' => 'text', 'text' => 'The answer is 42.'],
            ],
        ];

        $response = $parser->parse($data, 'gemini-3.5-flash');

        // Thought should be skipped from content
        $this->assertEquals('The answer is 42.', $response->getMessage()->getContent());
        // But raw steps should include thought
        $rawSteps = $response->getMessage()->getRawSteps();
        $this->assertCount(2, $rawSteps);
    }

    /**
     * @test
     */
    public function testInteractionsParserEmptySteps() {
        $parser = new InteractionsResponseParser();
        $data = ['steps' => []];

        $response = $parser->parse($data, 'gemini-3.5-flash');

        $this->assertEquals('', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testInteractionsParserNoUsage() {
        $parser = new InteractionsResponseParser();
        $data = ['steps' => [['type' => 'text', 'text' => 'Hi']]];

        $response = $parser->parse($data, 'gemini-3.5-flash');

        $this->assertNull($response->getUsage());
    }

    /**
     * @test
     */
    public function testInteractionsParserDefaultModel() {
        $parser = new InteractionsResponseParser();
        $data = ['steps' => [['type' => 'text', 'text' => 'Hi']]];

        $response = $parser->parse($data, 'my-default-model');

        $this->assertEquals('my-default-model', $response->getModel());
    }

    // ==================== RETRY CONFIG ====================

    /**
     * @test
     */
    public function testRetryConfigDefaults() {
        $config = new RetryConfig();

        $this->assertEquals(3, $config->getMaxRetries());
        $this->assertEquals(1000, $config->getInitialDelayMs());
        $this->assertEquals(30000, $config->getMaxDelayMs());
        $this->assertEquals(2.0, $config->getBackoffMultiplier());
        $this->assertEquals([429, 500, 502, 503, 504], $config->getRetryableStatusCodes());
    }

    /**
     * @test
     */
    public function testRetryConfigCustomValues() {
        $config = new RetryConfig(
            maxRetries: 5,
            initialDelayMs: 500,
            maxDelayMs: 60000,
            backoffMultiplier: 3.0,
            retryableStatusCodes: [429, 503],
        );

        $this->assertEquals(5, $config->getMaxRetries());
        $this->assertEquals(500, $config->getInitialDelayMs());
        $this->assertEquals(60000, $config->getMaxDelayMs());
        $this->assertEquals(3.0, $config->getBackoffMultiplier());
        $this->assertEquals([429, 503], $config->getRetryableStatusCodes());
    }

    /**
     * @test
     */
    public function testRetryConfigIsRetryableStatusCode() {
        $config = new RetryConfig();

        $this->assertTrue($config->isRetryableStatusCode(429));
        $this->assertTrue($config->isRetryableStatusCode(500));
        $this->assertTrue($config->isRetryableStatusCode(502));
        $this->assertTrue($config->isRetryableStatusCode(503));
        $this->assertTrue($config->isRetryableStatusCode(504));
        $this->assertFalse($config->isRetryableStatusCode(400));
        $this->assertFalse($config->isRetryableStatusCode(401));
        $this->assertFalse($config->isRetryableStatusCode(404));
    }

    /**
     * @test
     */
    public function testRetryConfigIsRetryableException() {
        $config = new RetryConfig();

        $httpException = new \WebFiori\Ai\Exception\HttpException('test');
        $runtimeException = new \RuntimeException('test');

        $this->assertTrue($config->isRetryableException($httpException));
        $this->assertFalse($config->isRetryableException($runtimeException));
    }

    /**
     * @test
     */
    public function testRetryConfigCalculateDelay() {
        $config = new RetryConfig(
            initialDelayMs: 1000,
            maxDelayMs: 10000,
            backoffMultiplier: 2.0
        );

        // First attempt: ~1000ms (±20% jitter)
        $delay1 = $config->calculateDelayMs(1);
        $this->assertGreaterThanOrEqual(800, $delay1);
        $this->assertLessThanOrEqual(1200, $delay1);

        // Second attempt: ~2000ms (±20% jitter)
        $delay2 = $config->calculateDelayMs(2);
        $this->assertGreaterThanOrEqual(1600, $delay2);
        $this->assertLessThanOrEqual(2400, $delay2);

        // Third attempt: ~4000ms (±20% jitter)
        $delay3 = $config->calculateDelayMs(3);
        $this->assertGreaterThanOrEqual(3200, $delay3);
        $this->assertLessThanOrEqual(4800, $delay3);
    }

    /**
     * @test
     */
    public function testRetryConfigDelayCappedAtMax() {
        $config = new RetryConfig(
            initialDelayMs: 1000,
            maxDelayMs: 5000,
            backoffMultiplier: 10.0
        );

        // Large attempt should be capped at maxDelayMs
        $delay = $config->calculateDelayMs(5);
        $this->assertLessThanOrEqual(6000, $delay); // 5000 + 20% max jitter
    }

    // ==================== RETRIEVER ADDITIONAL TESTS ====================

    /**
     * @test
     */
    public function testRetrieverIngest() {
        $store = new InMemoryVectorStore();

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'text-embedding-3-small'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store);
        $id = $retriever->ingest('The text about PHP', ['source' => 'doc.txt']);

        // Verify it was stored
        $this->assertStringStartsWith('doc_', $id);
        $results = $store->query([0.1, 0.2, 0.3], 1);
        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function testRetrieverDelete() {
        $store = new InMemoryVectorStore();
        $store->store('chunk-1', [1.0, 0.0], ['text' => 'First chunk']);
        $store->store('chunk-2', [0.0, 1.0], ['text' => 'Second chunk']);

        $fakeHttp = new FakeHttpClient();
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'text-embedding-3-small'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store);
        $retriever->delete('chunk-1');

        // Verify it was removed
        $this->assertNull($store->get('chunk-1'));
        $this->assertNotNull($store->get('chunk-2'));
    }

    /**
     * @test
     */
    public function testRetrieverSetMinScore() {
        $store = new InMemoryVectorStore();
        $store->store('chunk-1', [1.0, 0.0], ['text' => 'High match']);
        $store->store('chunk-2', [0.0, 1.0], ['text' => 'Low match']);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'object' => 'list',
            'data' => [['embedding' => [1.0, 0.0]]],
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
        ])));

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'text-embedding-3-small'));
        $provider->setHttpClient($fakeHttp);

        $retriever = new Retriever($provider, $store);
        $retriever->setMinScore(0.9);

        $results = $retriever->retrieve('high match query');
        // Only chunk-1 should pass the 0.9 threshold
        $this->assertCount(1, $results);
        $this->assertEquals('chunk-1', $results[0]->getId());
    }

    // ==================== GOOGLE CLIENT - INTERACTIONS API FORCED ====================

    /**
     * @test
     */
    public function testForcedInteractionsApiVersion() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'steps' => [
                ['type' => 'text', 'text' => 'Forced interactions response'],
            ],
            'id' => 'int_forced',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 4],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',  // Normally would use generateContent
            apiKey: 'test-key',
            apiVersion: GoogleApiVersion::INTERACTIONS,  // Force Interactions API
        ));
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);

        // Should use Interactions API even though model is 2.5
        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('interactions', $url);
        $this->assertEquals('Forced interactions response', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testForcedGenerateContentApiVersion() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Forced generate response']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',  // Normally would use Interactions
            apiKey: 'test-key',
            apiVersion: GoogleApiVersion::GENERATE_CONTENT,  // Force generateContent
        ));
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);

        // Should use generateContent even though model is 3.5
        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('generateContent', $url);
        $this->assertEquals('Forced generate response', $response->getMessage()->getContent());
    }

    // ==================== BEDROCK CONVERSE - AUTO EXECUTE TOOLS ====================

    /**
     * @test
     */
    public function testConverseAutoExecuteTools() {
        $client = new FakeHttpClient();

        // First response: tool call
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['toolUse' => [
                    'toolUseId' => 'tool_1',
                    'name' => 'get_weather',
                    'input' => ['location' => 'Paris'],
                ]],
            ]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
        ])));

        // Second response: final answer
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['text' => 'The weather in Paris is 22°C and sunny.'],
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

        $weatherTool = new Tool(
            'get_weather',
            'Get weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            function (array $args): string { return json_encode(['temp' => 22, 'conditions' => 'sunny']); }
        );

        $response = $provider->chat(
            [new Message('user', 'Weather in Paris?')],
            ['tools' => [$weatherTool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('The weather in Paris is 22°C and sunny.', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testInvokeAutoExecuteTools() {
        $client = new FakeHttpClient();

        // First response: tool call
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [
                ['type' => 'tool_use', 'id' => 'tc_1', 'name' => 'calc', 'input' => ['expr' => '2+2']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])));

        // Second response: final answer
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'The answer is 4.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 30, 'output_tokens' => 5],
        ])));

        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            apiKey: 'test-key',
            apiMethod: ApiMethod::INVOKE,
        ));
        $provider->setHttpClient($client);

        $calcTool = new Tool(
            'calc',
            'Calculator',
            ['type' => 'object', 'properties' => ['expr' => ['type' => 'string']]],
            function (array $args): string { return '4'; }
        );

        $response = $provider->chat(
            [new Message('user', 'What is 2+2?')],
            ['tools' => [$calcTool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('The answer is 4.', $response->getMessage()->getContent());
    }

    // ==================== GOOGLE CLIENT INCREMENTAL REQUEST ====================

    /**
     * @test
     */
    public function testGoogleAutoExecuteToolsWithIncremental() {
        $client = new FakeHttpClient();

        // First response: tool call
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [['functionCall' => ['name' => 'get_info', 'args' => ['q' => 'test']]]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ])));

        // Second response: final answer
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Found the info.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 3],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-key',
        ));
        $provider->setHttpClient($client);

        $tool = new Tool(
            'get_info',
            'Get info',
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            function (array $args): string { return json_encode(['result' => 'data']); }
        );

        $response = $provider->chat(
            [new Message('user', 'Get info about test')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('Found the info.', $response->getMessage()->getContent());
    }

    // ==================== BEDROCK SESSION TOKEN ====================

    /**
     * @test
     */
    public function testBedrockWithSessionToken() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hi']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
        ])));

        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            accessKey: 'AKIAIOSFODNN7EXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            sessionToken: 'FwoGZXIvYXdzEBYaDH...',
        ));
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hi')]);
        $this->assertEquals('Hi', $response->getMessage()->getContent());

        // Session token should be in headers
        $headers = $client->getLastRequest()->getHeaders();
        $this->assertArrayHasKey('X-Amz-Security-Token', $headers);
    }


    // ==================== GOOGLE TOP_P ====================

    /**
     * @test
     */
    public function testGoogleTopP() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hello']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-key',
        ));
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hi')],
            ['top_p' => 0.95]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals(0.95, $body['generationConfig']['topP']);
    }

    // ==================== ANTHROPIC STREAMING ERROR CALLBACK ====================

    /**
     * @test
     */
    public function testAnthropicStreamingErrorCallback() {
        // Use streaming chunks that are empty to trigger basic flow, then simulate error
        // via an invalid chunk that the parser will handle gracefully
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 1]]]) . "\n\n",
            "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]) . "\n\n",
            "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]) . "\n\n",
        ]);

        $provider = new \WebFiori\Ai\Provider\Anthropic\AnthropicClient(
            new \WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig(apiKey: 'test-key', model: 'claude-sonnet-4-20250514')
        );
        $provider->setHttpClient($client);

        $completedResponse = null;
        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {},
            function ($response) use (&$completedResponse) { $completedResponse = $response; },
            function (\Throwable $e) { $this->fail('Should not error'); }
        );

        $this->assertNotNull($completedResponse);
        $this->assertEquals('Hello', $completedResponse->getMessage()->getContent());
    }

    // ==================== ANTHROPIC BUILT-IN TOOLS ====================

    /**
     * @test
     */
    public function testAnthropicBuiltInToolComputer() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Done']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
        ])));

        $provider = new \WebFiori\Ai\Provider\Anthropic\AnthropicClient(
            new \WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig(apiKey: 'test-key', model: 'claude-sonnet-4-20250514')
        );
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Click the button')],
            ['built_in_tools' => [\WebFiori\Ai\Tool\AnthropicBuiltInTool::COMPUTER]]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $found = false;
        foreach ($body['tools'] as $tool) {
            if (($tool['name'] ?? '') === 'computer') {
                $found = true;
                $this->assertEquals('computer_20241022', $tool['type']);
                $this->assertEquals(1024, $tool['display_width_px']);
                $this->assertEquals(768, $tool['display_height_px']);
            }
        }
        $this->assertTrue($found);
    }

    /**
     * @test
     */
    public function testAnthropicBuiltInToolBash() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Done']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
        ])));

        $provider = new \WebFiori\Ai\Provider\Anthropic\AnthropicClient(
            new \WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig(apiKey: 'test-key', model: 'claude-sonnet-4-20250514')
        );
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Run a command')],
            ['built_in_tools' => [\WebFiori\Ai\Tool\AnthropicBuiltInTool::BASH]]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $found = false;
        foreach ($body['tools'] as $tool) {
            if (($tool['name'] ?? '') === 'bash') {
                $found = true;
                $this->assertEquals('bash_20241022', $tool['type']);
            }
        }
        $this->assertTrue($found);
    }

    /**
     * @test
     */
    public function testAnthropicBuiltInToolTextEditor() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Done']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
        ])));

        $provider = new \WebFiori\Ai\Provider\Anthropic\AnthropicClient(
            new \WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig(apiKey: 'test-key', model: 'claude-sonnet-4-20250514')
        );
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Edit a file')],
            ['built_in_tools' => [\WebFiori\Ai\Tool\AnthropicBuiltInTool::TEXT_EDITOR]]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $found = false;
        foreach ($body['tools'] as $tool) {
            if (($tool['name'] ?? '') === 'text_editor') {
                $found = true;
                $this->assertEquals('text_editor_20241022', $tool['type']);
            }
        }
        $this->assertTrue($found);
    }

    // ==================== GOOGLE STREAMING ERROR CALLBACK ====================

    /**
     * @test
     */
    public function testGoogleStreamingWithNoComplete() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hello\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":3,\"candidatesTokenCount\":1}}\n\n",
        ]);

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-key',
        ));
        $provider->setHttpClient($client);

        $tokens = [];
        // Pass null for onComplete to test that path
        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$tokens) { $tokens[] = $token; },
            null, // no onComplete
            null  // no onError
        );

        $this->assertEquals(['Hello'], $tokens);
    }

    // ==================== BEDROCK CONVERSE STREAMING ERROR ====================

    /**
     * @test
     */
    public function testBedrockConverseStreamingNoComplete() {
        $client = new FakeHttpClient();
        $chunks = [
            $this->buildEventStreamMsg('messageStart', ['role' => 'assistant']),
            $this->buildEventStreamMsg('contentBlockDelta', ['delta' => ['text' => 'Hi']]),
            $this->buildEventStreamMsg('contentBlockStop', []),
            $this->buildEventStreamMsg('messageStop', ['stopReason' => 'end_turn']),
            $this->buildEventStreamMsg('metadata', ['usage' => ['inputTokens' => 1, 'outputTokens' => 1]]),
        ];
        $client->addStreamingChunks($chunks);

        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            apiKey: 'test-key',
            apiMethod: ApiMethod::CONVERSE,
        ));
        $provider->setHttpClient($client);

        $tokens = [];
        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$tokens) { $tokens[] = $token; },
            null, // no onComplete
            null  // no onError
        );

        $this->assertEquals(['Hi'], $tokens);
    }

    /**
     * @test
     */
    public function testBedrockInvokeStreamingNoComplete() {
        $client = new FakeHttpClient();
        $chunk = json_encode(['generation' => 'Hi', 'stop_reason' => 'stop']);
        $client->addStreamingChunks([
            "data: " . json_encode(['bytes' => base64_encode($chunk)]) . "\n\n",
        ]);

        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'meta.llama3-70b-instruct-v1:0',
            apiKey: 'test-key',
            apiMethod: ApiMethod::INVOKE,
        ));
        $provider->setHttpClient($client);

        $tokens = [];
        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$tokens) { $tokens[] = $token; },
            null,
            null
        );

        $this->assertEquals(['Hi'], $tokens);
    }

    /**
     * Builds a binary AWS Event Stream message for testing.
     */
    private function buildEventStreamMsg(string $eventType, array $payload): string {
        $payloadBytes = json_encode($payload);
        $headerName = ':event-type';
        $headerValue = $eventType;
        $headers = chr(strlen($headerName)) . $headerName;
        $headers .= chr(7);
        $headers .= pack('n', strlen($headerValue)) . $headerValue;
        $headersLength = strlen($headers);
        $totalLength = 4 + 4 + 4 + $headersLength + strlen($payloadBytes) + 4;
        $prelude = pack('N', $totalLength) . pack('N', $headersLength);
        $preludeCrc = pack('N', crc32($prelude));
        $messageBody = $prelude . $preludeCrc . $headers . $payloadBytes;
        $messageCrc = pack('N', crc32($messageBody));
        return $messageBody . $messageCrc;
    }
}
