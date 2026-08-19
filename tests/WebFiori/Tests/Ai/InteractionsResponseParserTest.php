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
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\InteractionsResponseParser;

/**
 * Tests for #102: InteractionsResponseParser.
 */
class InteractionsResponseParserTest extends TestCase {
    private InteractionsResponseParser $parser;

    protected function setUp(): void {
        $this->parser = new InteractionsResponseParser();
    }

    // =========================================================================
    // Text step parsing
    // =========================================================================

    public function testParsesTextStepIntoContent(): void {
        $data = [
            'id' => 'int_001',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'text', 'text' => 'The answer is 42.'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $this->assertEquals('The answer is 42.', $response->getMessage()->getContent());
        $this->assertEquals('gemini-3.5-flash', $response->getModel());
        $this->assertEquals('stop', $response->getFinishReason());
    }

    public function testConcatenatesMultipleTextSteps(): void {
        $data = [
            'id' => 'int_002',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'text', 'text' => 'First part. '],
                ['type' => 'text', 'text' => 'Second part.'],
            ],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5, 'total_tokens' => 10],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $this->assertEquals('First part. Second part.', $response->getMessage()->getContent());
    }

    // =========================================================================
    // Thought step parsing
    // =========================================================================

    public function testIgnoresThoughtStepsInContent(): void {
        $data = [
            'id' => 'int_003',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'thought', 'text' => 'Let me think about this...'],
                ['type' => 'text', 'text' => 'The answer is PHP.'],
            ],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5, 'total_tokens' => 10],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        // Thought should not appear in content
        $this->assertEquals('The answer is PHP.', $response->getMessage()->getContent());
        $this->assertStringNotContainsString('Let me think', $response->getMessage()->getContent());
    }

    public function testThoughtStepsPreservedInRawSteps(): void {
        $data = [
            'id' => 'int_003',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'thought', 'text' => 'Reasoning...'],
                ['type' => 'text', 'text' => 'Answer.'],
            ],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5, 'total_tokens' => 10],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');
        $rawSteps = $response->getMessage()->getRawSteps();

        $this->assertNotNull($rawSteps);
        $this->assertCount(2, $rawSteps);
        $this->assertEquals('thought', $rawSteps[0]['type']);
        $this->assertEquals('text', $rawSteps[1]['type']);
    }

    // =========================================================================
    // Function call step parsing
    // =========================================================================

    public function testParsesFunctionCallStep(): void {
        $data = [
            'id' => 'int_004',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                [
                    'type' => 'function_call',
                    'id' => 'call_001',
                    'name' => 'get_weather',
                    'arguments' => ['location' => 'NYC'],
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 8, 'total_tokens' => 18],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $this->assertTrue($response->hasToolCalls());
        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertEquals('call_001', $toolCalls[0]->getId());
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals(['location' => 'NYC'], $toolCalls[0]->getArguments());
        $this->assertEquals('tool_calls', $response->getFinishReason());
    }

    public function testParsesParallelFunctionCalls(): void {
        $data = [
            'id' => 'int_005',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['city' => 'NYC']],
                ['type' => 'function_call', 'id' => 'call_2', 'name' => 'get_time', 'arguments' => ['zone' => 'UTC']],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10, 'total_tokens' => 20],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertCount(2, $toolCalls);
        $this->assertEquals('get_weather', $toolCalls[0]->getName());
        $this->assertEquals('get_time', $toolCalls[1]->getName());
    }

    public function testFunctionCallRawPartPreserved(): void {
        $step = [
            'type' => 'function_call',
            'id' => 'call_001',
            'name' => 'search',
            'arguments' => ['q' => 'PHP'],
        ];
        $data = [
            'id' => 'int_006',
            'model' => 'gemini-3.5-flash',
            'steps' => [$step],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5, 'total_tokens' => 10],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $toolCalls = $response->getMessage()->getToolCalls();
        $this->assertEquals($step, $toolCalls[0]->getRawPart());
    }

    // =========================================================================
    // Mixed steps (thought + function_call + text)
    // =========================================================================

    public function testMixedStepsResponse(): void {
        $data = [
            'id' => 'int_007',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'thought', 'text' => 'I should look up the weather.'],
                ['type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['city' => 'Paris']],
                ['type' => 'text', 'text' => 'Based on the data, it is sunny.'],
            ],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 15, 'total_tokens' => 35],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        // Content includes only text steps
        $this->assertEquals('Based on the data, it is sunny.', $response->getMessage()->getContent());

        // Tool calls parsed
        $this->assertCount(1, $response->getMessage()->getToolCalls());

        // Raw steps include all 3
        $this->assertCount(3, $response->getMessage()->getRawSteps());

        // Finish reason is tool_calls because there's a function_call
        $this->assertEquals('tool_calls', $response->getFinishReason());
    }

    // =========================================================================
    // Usage and metadata
    // =========================================================================

    public function testParsesUsageMetadata(): void {
        $data = [
            'id' => 'int_008',
            'model' => 'gemini-3.5-flash',
            'steps' => [['type' => 'text', 'text' => 'Hello']],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20, 'total_tokens' => 70],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $this->assertNotNull($response->getUsage());
        $this->assertEquals(50, $response->getUsage()->getPromptTokens());
        $this->assertEquals(20, $response->getUsage()->getCompletionTokens());
    }

    public function testUsageIsNullWhenNotPresent(): void {
        $data = [
            'id' => 'int_009',
            'model' => 'gemini-3.5-flash',
            'steps' => [['type' => 'text', 'text' => 'Hello']],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $this->assertNull($response->getUsage());
    }

    public function testInteractionIdStoredAsRequestId(): void {
        $data = [
            'id' => 'interaction_abc123',
            'model' => 'gemini-3.5-flash',
            'steps' => [['type' => 'text', 'text' => 'Hello']],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3, 'total_tokens' => 8],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $this->assertEquals('interaction_abc123', $response->getRequestId());
    }

    public function testEmptyStepsReturnsEmptyResponse(): void {
        $data = [
            'id' => 'int_010',
            'model' => 'gemini-3.5-flash',
            'steps' => [],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 0, 'total_tokens' => 5],
        ];

        $response = $this->parser->parse($data, 'gemini-3.5-flash');

        $this->assertEquals('', $response->getMessage()->getContent());
        $this->assertFalse($response->hasToolCalls());
        $this->assertEquals('stop', $response->getFinishReason());
    }

    public function testUsesDefaultModelWhenNotInResponse(): void {
        $data = [
            'steps' => [['type' => 'text', 'text' => 'Hi']],
        ];

        $response = $this->parser->parse($data, 'my-default-model');

        $this->assertEquals('my-default-model', $response->getModel());
    }

    // =========================================================================
    // Integration: GoogleClient uses parser for gemini-3.x responses
    // =========================================================================

    public function testGoogleClientParsesInteractionsResponse(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'interaction_xyz',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'thought', 'text' => 'Let me think...'],
                ['type' => 'text', 'text' => 'PHP stands for PHP: Hypertext Preprocessor.'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 8, 'total_tokens' => 18],
        ])));
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'What is PHP?')]);

        $this->assertEquals('PHP stands for PHP: Hypertext Preprocessor.', $response->getMessage()->getContent());
        $this->assertEquals('interaction_xyz', $response->getRequestId());
        $this->assertNotNull($response->getUsage());
        $this->assertEquals(10, $response->getUsage()->getPromptTokens());
    }

    public function testGoogleClientParsesInteractionsToolCallResponse(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'interaction_tools',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['location' => 'London']],
            ],
            'usage' => ['input_tokens' => 15, 'output_tokens' => 10, 'total_tokens' => 25],
        ])));
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Weather in London?')]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('get_weather', $response->getMessage()->getToolCalls()[0]->getName());
        $this->assertEquals('interaction_tools', $response->getRequestId());
    }
}
