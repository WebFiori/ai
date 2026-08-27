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
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\GoogleBuiltInTool;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Extended unit tests for GoogleClient covering uncovered paths.
 */
class GoogleClientExtendedTest extends TestCase {
    // ==================== IMAGE GENERATION ====================

    /**
     * @test
     */
    public function testImageGeneration() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Here is your generated image.'],
                        ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('fake-png-data')]],
                    ],
                    'role' => 'model',
                ],
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $response = $provider->generateImage(new ImageRequest('A beautiful sunset'));

        $this->assertCount(1, $response->getImages());
        $this->assertEquals(base64_encode('fake-png-data'), $response->getImages()[0]->getBase64());
        $this->assertEquals('Here is your generated image.', $response->getImages()[0]->getRevisedPrompt());
    }

    /**
     * @test
     */
    public function testImageGenerationWithNegativePrompt() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('img')]],
                    ],
                    'role' => 'model',
                ],
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $request = new ImageRequest(
            'A cat',
            '1792x1024',
            1,
            'standard',
            'url',
            'watercolor',
            'dogs'
        );

        $provider->generateImage($request);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $prompt = $body['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('A cat', $prompt);
        $this->assertStringContainsString('Do NOT include: dogs', $prompt);
        $this->assertStringContainsString('Style: watercolor', $prompt);
        $this->assertStringContainsString('16:9', $prompt);
    }

    /**
     * @test
     */
    public function testImageGenerationPortraitOrientation() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => 'aW1n']]],
                    'role' => 'model',
                ],
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $request = new ImageRequest('A portrait', '1024x1792');
        $provider->generateImage($request);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $prompt = $body['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('9:16', $prompt);
    }

    /**
     * @test
     */
    public function testImageGenerationLandscape4x3() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => 'aW1n']]],
                    'role' => 'model',
                ],
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $request = new ImageRequest('A landscape', '1024x768');
        $provider->generateImage($request);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $prompt = $body['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('4:3', $prompt);
    }

    // ==================== EMBEDDINGS ====================

    /**
     * @test
     */
    public function testGeminiApiSingleEmbedding() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'embedding' => ['values' => [0.1, 0.2, 0.3, 0.4]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $response = $provider->embed('Hello world');

        $this->assertEquals([0.1, 0.2, 0.3, 0.4], $response->getVector());
        $this->assertEquals(4, $response->getDimensions());

        // Verify it uses embedContent endpoint
        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('embedContent', $url);
    }

    /**
     * @test
     */
    public function testGeminiApiBatchEmbeddings() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'embeddings' => [
                ['values' => [0.1, 0.2]],
                ['values' => [0.3, 0.4]],
                ['values' => [0.5, 0.6]],
            ],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $response = $provider->embed(['Hello', 'World', 'Test']);

        $this->assertCount(3, $response->getVectors());
        $this->assertEquals([0.1, 0.2], $response->getVectors()[0]);

        // Verify it uses batchEmbedContents endpoint
        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('batchEmbedContents', $url);
    }

    /**
     * @test
     */
    public function testVertexAiEmbeddings() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'predictions' => [
                ['embeddings' => ['values' => [0.5, 0.6, 0.7]]],
            ],
        ])));

        $provider = $this->createVertexProvider();
        $provider->setHttpClient($client);

        $response = $provider->embed('Hello world');

        $this->assertEquals([0.5, 0.6, 0.7], $response->getVector());

        // Verify it uses predict endpoint
        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('predict', $url);
        $this->assertStringContainsString('aiplatform.googleapis.com', $url);
    }

    // ==================== STRUCTURED OUTPUT / JSON MODE ====================

    /**
     * @test
     */
    public function testJsonModeEnabled() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"key":"value"}']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Return JSON')],
            ['json_mode' => true]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals('application/json', $body['generationConfig']['responseMimeType']);
    }

    /**
     * @test
     */
    public function testJsonSchemaMode() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"name":"Test"}']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $provider->chat(
            [new Message('user', 'Give me a name')],
            ['json_schema' => $schema]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertEquals($schema, $body['generationConfig']['responseSchema']);
    }

    /**
     * @test
     */
    public function testStopSequences() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hello']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['stop' => ['END', 'DONE']]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals(['END', 'DONE'], $body['generationConfig']['stopSequences']);
    }

    /**
     * @test
     */
    public function testStopSequenceAsString() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hello']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['stop' => 'END']
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertEquals(['END'], $body['generationConfig']['stopSequences']);
    }

    // ==================== BUILT-IN TOOLS ====================

    /**
     * @test
     */
    public function testGoogleSearchBuiltInTool() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Search results.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'What happened today?')],
            ['built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH]]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $found = false;
        foreach ($body['tools'] as $tool) {
            if (isset($tool['googleSearch'])) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected googleSearch tool in request body');
    }

    /**
     * @test
     */
    public function testCodeExecutionBuiltInTool() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => '42']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Calculate 6*7')],
            ['built_in_tools' => [GoogleBuiltInTool::CODE_EXECUTION]]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $found = false;
        foreach ($body['tools'] as $tool) {
            if (isset($tool['codeExecution'])) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * @test
     */
    public function testUrlContextBuiltInTool() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Content.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Read this URL')],
            ['built_in_tools' => [GoogleBuiltInTool::URL_CONTEXT]]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $found = false;
        foreach ($body['tools'] as $tool) {
            if (isset($tool['urlContext'])) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * @test
     */
    public function testGoogleSearchWithCustomToolsOnVertexThrows() {
        $provider = $this->createVertexProvider();
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], '{}'));
        $provider->setHttpClient($fakeHttp);

        $customTool = new Tool(
            'my_tool',
            'A custom tool',
            ['type' => 'object', 'properties' => []],
            function (array $args): string { return '{}'; }
        );

        $this->expectException(UnsupportedFeatureException::class);
        $provider->chat(
            [new Message('user', 'Test')],
            ['tools' => [$customTool], 'built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH]]
        );
    }

    /**
     * @test
     */
    public function testCustomToolsFormatting() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'OK']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $tool = new Tool(
            'search_db',
            'Search a database',
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            function (array $args): string { return '[]'; }
        );

        $provider->chat(
            [new Message('user', 'Find something')],
            ['tools' => [$tool]]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $decl = $body['tools'][0]['functionDeclarations'][0];
        $this->assertEquals('search_db', $decl['name']);
        $this->assertEquals('Search a database', $decl['description']);
    }

    // ==================== MULTI-MODAL ====================

    /**
     * @test
     */
    public function testMultiModalImageBase64() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'A cat.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('What is this?'),
            ContentPart::imageBase64('iVBORw0KGgo=', 'image/png'),
        ]);

        $response = $provider->chat([$message]);
        $this->assertEquals('A cat.', $response->getMessage()->getContent());

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $parts = $body['contents'][0]['parts'];
        $this->assertEquals('What is this?', $parts[0]['text']);
        $this->assertArrayHasKey('inlineData', $parts[1]);
        $this->assertEquals('image/png', $parts[1]['inlineData']['mimeType']);
        $this->assertEquals('iVBORw0KGgo=', $parts[1]['inlineData']['data']);
    }

    /**
     * @test
     */
    public function testMultiModalDocument() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Summary.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('Summarize.'),
            ContentPart::document(base64_encode('pdf data'), 'application/pdf'),
        ]);

        $response = $provider->chat([$message]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $parts = $body['contents'][0]['parts'];
        $this->assertArrayHasKey('inlineData', $parts[1]);
        $this->assertEquals('application/pdf', $parts[1]['inlineData']['mimeType']);
    }

    /**
     * @test
     */
    public function testMultiModalGcsFile() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'File content.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $message = new Message('user', [
            ContentPart::text('Analyze.'),
            ContentPart::gcsUri('gs://bucket/file.pdf', 'application/pdf'),
        ]);

        $response = $provider->chat([$message]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $parts = $body['contents'][0]['parts'];
        $this->assertArrayHasKey('fileData', $parts[1]);
        $this->assertEquals('application/pdf', $parts[1]['fileData']['mimeType']);
        $this->assertEquals('gs://bucket/file.pdf', $parts[1]['fileData']['fileUri']);
    }

    // ==================== TOOL RESULT WITH MULTIMODAL ====================

    /**
     * @test
     */
    public function testToolResultMultimodal() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Chart analysis.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $toolResult = new ToolResult('generate_chart', '{"status": "done"}', 'generate_chart', [
            ContentPart::imageBase64('chartbase64data', 'image/png'),
        ]);

        $messages = [
            new Message('user', 'Generate and describe the chart'),
            new Message('assistant', '', [new ToolCall('call_1', 'generate_chart', ['title' => 'Q3'])]),
            new Message('tool', '', [], $toolResult),
        ];

        $response = $provider->chat($messages);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        // Find the function response
        $functionMsg = null;
        foreach ($body['contents'] as $content) {
            if ($content['role'] === 'function') {
                $functionMsg = $content;
            }
        }
        $this->assertNotNull($functionMsg);
        $funcResponse = $functionMsg['parts'][0]['functionResponse']['response'];
        // Multimodal tool result should have 'content' array
        $this->assertArrayHasKey('content', $funcResponse);
    }

    // ==================== RESPONSE PARSING EDGE CASES ====================

    /**
     * @test
     */
    public function testEmptyCandidatesReturnsEmpty() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hi')]);
        $this->assertEquals('', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testThoughtPartsAreSkipped() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['thought' => true, 'text' => 'Let me think about this...'],
                        ['text' => 'The answer is 42.'],
                    ],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 10],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'What is the meaning of life?')]);
        // Thought parts should be excluded from content
        $this->assertEquals('The answer is 42.', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testGroundingMetadataFallback() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => '']], 'role' => 'model'],
                'finishReason' => 'STOP',
                'groundingMetadata' => [
                    'searchEntryPoint' => [
                        'renderedContent' => 'Grounded search result content here.',
                    ],
                ],
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Search for something')]);
        $this->assertEquals('Grounded search result content here.', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testFinishReasonMapping() {
        $testCases = [
            'MAX_TOKENS' => 'length',
            'SAFETY' => 'content_filter',
            'RECITATION' => 'content_filter',
        ];

        foreach ($testCases as $googleReason => $expected) {
            $client = new FakeHttpClient();
            $client->addResponse(new HttpResponse(200, [], json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'content']], 'role' => 'model'],
                    'finishReason' => $googleReason,
                ]],
            ])));

            $provider = $this->createGeminiProvider();
            $provider->setHttpClient($client);

            $response = $provider->chat([new Message('user', 'Hi')]);
            $this->assertEquals($expected, $response->getFinishReason(), "Failed for reason: $googleReason");
        }
    }

    // ==================== INTERACTIONS API ====================

    /**
     * @test
     */
    public function testInteractionsApiAutoDetected() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'steps' => [
                ['type' => 'model_output', 'parts' => [['text' => 'Hello from Gemini 3!']]],
            ],
            'interaction' => ['id' => 'int_123', 'model' => 'gemini-3.5-flash'],
            'usage' => ['total_input_tokens' => 5, 'total_output_tokens' => 3],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);

        // Should use the Interactions API endpoint
        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('interactions', $url);
    }

    /**
     * @test
     */
    public function testInteractionsApiStreamDetected() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "event: step.start\ndata: {\"step\":{\"type\":\"model_output\"}}\n\n",
            "event: step.delta\ndata: {\"delta\":{\"type\":\"text\",\"text\":\"Hello\"}}\n\n",
            "event: step.delta\ndata: {\"delta\":{\"type\":\"text\",\"text\":\" world\"}}\n\n",
            "event: interaction.completed\ndata: {\"interaction\":{\"id\":\"int_1\",\"model\":\"gemini-3.5-flash\",\"usage\":{\"total_input_tokens\":5,\"total_output_tokens\":2}}}\n\n",
        ]);

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));
        $provider->setHttpClient($client);

        $tokens = [];
        $completedResponse = null;

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$tokens) { $tokens[] = $token; },
            function ($response) use (&$completedResponse) { $completedResponse = $response; }
        );

        $this->assertEquals(['Hello', ' world'], $tokens);
        $this->assertNotNull($completedResponse);
        $this->assertEquals('Hello world', $completedResponse->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testInteractionsStreamThoughtSkipped() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "event: step.start\ndata: {\"step\":{\"type\":\"thought\"}}\n\n",
            "event: step.delta\ndata: {\"delta\":{\"type\":\"text\",\"text\":\"thinking...\"}}\n\n",
            "event: step.start\ndata: {\"step\":{\"type\":\"model_output\"}}\n\n",
            "event: step.delta\ndata: {\"delta\":{\"type\":\"text\",\"text\":\"Answer\"}}\n\n",
            "event: interaction.completed\ndata: {\"interaction\":{\"id\":\"int_1\",\"model\":\"gemini-3.5-flash\",\"usage\":{\"input_tokens\":5,\"output_tokens\":1}}}\n\n",
        ]);

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));
        $provider->setHttpClient($client);

        $tokens = [];
        $provider->streamChat(
            [new Message('user', 'Think and answer')],
            function (string $token) use (&$tokens) { $tokens[] = $token; }
        );

        // Only the model_output token should be emitted, not the thought
        $this->assertEquals(['Answer'], $tokens);
    }

    /**
     * @test
     */
    public function testInteractionsStreamToolCall() {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "event: step.start\ndata: {\"step\":{\"type\":\"model_output\"}}\n\n",
            "event: step.delta\ndata: {\"delta\":{\"type\":\"function_call\",\"id\":\"call_1\",\"name\":\"get_weather\",\"arguments\":{\"location\":\"London\"}}}\n\n",
            "event: interaction.completed\ndata: {\"interaction\":{\"id\":\"int_1\",\"model\":\"gemini-3.5-flash\",\"usage\":{\"total_input_tokens\":10,\"total_output_tokens\":5}}}\n\n",
        ]);

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));
        $provider->setHttpClient($client);

        $completedResponse = null;
        $provider->streamChat(
            [new Message('user', 'Weather?')],
            function (string $token) {},
            function ($response) use (&$completedResponse) { $completedResponse = $response; }
        );

        $this->assertNotNull($completedResponse);
        $this->assertTrue($completedResponse->hasToolCalls());
        $this->assertEquals('tool_calls', $completedResponse->getFinishReason());
    }

    // ==================== VERTEX AI MODEL GARDEN ====================

    /**
     * @test
     */
    public function testModelGardenAnthropicPublisher() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hello from Claude on Vertex!']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 7],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'claude-sonnet-4-20250514',
            projectId: 'my-project',
            location: 'us-central1',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
            publisher: 'anthropic',
        ));
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Hello from Claude on Vertex!', $response->getMessage()->getContent());
        $this->assertEquals('vertex:anthropic', $provider->getName());

        // Verify it uses the rawPredict endpoint
        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('publishers/anthropic', $url);
        $this->assertStringContainsString(':rawPredict', $url);
    }

    /**
     * @test
     */
    public function testModelGardenMetaPublisher() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'meta-llama/Llama-3.1-70B-Instruct',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello from Llama!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 4],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'meta-llama/Llama-3.1-70B-Instruct',
            projectId: 'my-project',
            location: 'us-central1',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
            publisher: 'meta',
        ));
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Hello')]);

        $this->assertEquals('Hello from Llama!', $response->getMessage()->getContent());
        $this->assertEquals('vertex:meta', $provider->getName());

        $url = $client->getLastRequest()->getUrl();
        $this->assertStringContainsString('publishers/meta', $url);
        $this->assertStringContainsString(':rawPredict', $url);
    }

    /**
     * @test
     */
    public function testModelGardenGlobalLocationDefaultsToUsCentral1() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'claude-sonnet-4-20250514',
            projectId: 'my-project',
            location: 'global',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
            publisher: 'anthropic',
        ));
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hi')]);

        $url = $client->getLastRequest()->getUrl();
        // Model Garden requires a specific region, should default to us-central1
        $this->assertStringContainsString('us-central1', $url);
    }

    // ==================== MULTIPLE TOOL CALLS ====================

    /**
     * @test
     */
    public function testMultipleToolCalls() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['functionCall' => ['name' => 'get_weather', 'args' => ['location' => 'London']]],
                        ['functionCall' => ['name' => 'get_time', 'args' => ['timezone' => 'UTC']]],
                    ],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $response = $provider->chat([new Message('user', 'Weather and time?')]);

        $this->assertTrue($response->hasToolCalls());
        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertCount(2, $toolCalls);
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals('get_time', $toolCalls[1]->getName());
    }

    /**
     * @test
     */
    public function testConsecutiveToolResultsMergedIntoOneEntry() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Done.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGeminiProvider();
        $provider->setHttpClient($client);

        $messages = [
            new Message('user', 'Get weather and time'),
            new Message('assistant', '', [
                new ToolCall('call_1', 'get_weather', ['location' => 'London']),
                new ToolCall('call_2', 'get_time', ['tz' => 'UTC']),
            ]),
            new Message('tool', '', [], new ToolResult('get_weather', '{"temp":22}')),
            new Message('tool', '', [], new ToolResult('get_time', '{"time":"12:00"}')),
        ];

        $provider->chat($messages);

        $body = json_decode($client->getLastRequest()->getBody(), true);

        // Both tool results should be merged into one 'function' role entry
        $functionEntries = array_filter($body['contents'], fn($c) => $c['role'] === 'function');
        $this->assertCount(1, $functionEntries);
        $functionEntry = array_values($functionEntries)[0];
        $this->assertCount(2, $functionEntry['parts']);
    }

    // ==================== HELPERS ====================

    private function createGeminiProvider(): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-api-key',
        ));
    }

    private function createVertexProvider(): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(
            model: 'gemini-1.5-pro',
            projectId: 'my-project',
            location: 'us-central1',
            accessToken: 'test-access-token',
            api: GoogleApi::VERTEX_AI,
        ));
    }
}
