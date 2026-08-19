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
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for #103: Interactions API tool execution loop.
 */
class InteractionsToolLoopTest extends TestCase {
    // =========================================================================
    // Basic tool loop
    // =========================================================================

    public function testSingleToolCallAndResult(): void {
        $fakeHttp = new FakeHttpClient();

        // First response: function_call
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_001',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['location' => 'NYC']],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ])));

        // Second response: final text
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_002',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'text', 'text' => 'The weather in NYC is 72°F and sunny.'],
            ],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'total_tokens' => 30],
        ])));

        $weatherTool = new Tool(
            'get_weather',
            'Get weather for a location',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']],
            fn(array $args) => '72°F and sunny'
        );

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $response = $client->chat(
            [new Message('user', 'What is the weather in NYC?')],
            ['tools' => [$weatherTool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('The weather in NYC is 72°F and sunny.', $response->getMessage()->getContent());
        $this->assertEquals(2, count($fakeHttp->getRequests()));
    }

    public function testToolResultSentAsInteractionsInput(): void {
        $fakeHttp = new FakeHttpClient();

        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_001',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['location' => 'Paris']],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ])));

        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_002',
            'model' => 'gemini-3.5-flash',
            'steps' => [['type' => 'text', 'text' => 'It is sunny in Paris.']],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 8, 'total_tokens' => 28],
        ])));

        $weatherTool = new Tool(
            'get_weather',
            'Get weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            fn(array $args) => 'Sunny, 25°C'
        );

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $client->chat(
            [new Message('user', 'Weather in Paris?')],
            ['tools' => [$weatherTool], 'auto_execute_tools' => true]
        );

        // Check second request contains function_result in input
        $requests = $fakeHttp->getRequests();
        $secondBody = json_decode($requests[1]->getBody(), true);

        $this->assertArrayHasKey('input', $secondBody);

        // Find function_result in input
        $functionResults = array_filter($secondBody['input'], fn($item) => ($item['type'] ?? '') === 'function_result');
        $this->assertNotEmpty($functionResults);

        $result = array_values($functionResults)[0];
        $this->assertEquals('function_result', $result['type']);
        $this->assertEquals('get_weather', $result['name']);
        $this->assertEquals('call_1', $result['call_id']);
        $this->assertEquals('Sunny, 25°C', $result['result'][0]['text']);
    }

    public function testModelStepsPreservedInNextTurn(): void {
        $fakeHttp = new FakeHttpClient();

        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_001',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'thought', 'text' => 'I should call the weather tool.'],
                ['type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['city' => 'Tokyo']],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 8, 'total_tokens' => 18],
        ])));

        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_002',
            'model' => 'gemini-3.5-flash',
            'steps' => [['type' => 'text', 'text' => 'Tokyo is 22°C.']],
            'usage' => ['input_tokens' => 25, 'output_tokens' => 6, 'total_tokens' => 31],
        ])));

        $weatherTool = new Tool(
            'get_weather',
            'Get weather',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            fn(array $args) => '22°C'
        );

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $client->chat(
            [new Message('user', 'Weather in Tokyo?')],
            ['tools' => [$weatherTool], 'auto_execute_tools' => true]
        );

        $requests = $fakeHttp->getRequests();
        $secondBody = json_decode($requests[1]->getBody(), true);

        // The second request's input should contain the model's steps
        // (thought + function_call) before the function_result
        $inputTypes = array_column($secondBody['input'], 'type');

        $this->assertContains('thought', $inputTypes, 'Thought step should be in next turn input');
        $this->assertContains('function_call', $inputTypes, 'Function call step should be in next turn input');
        $this->assertContains('function_result', $inputTypes, 'Function result should be in next turn input');
    }

    // =========================================================================
    // Parallel tool calls
    // =========================================================================

    public function testParallelToolCalls(): void {
        $fakeHttp = new FakeHttpClient();

        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_001',
            'model' => 'gemini-3.5-flash',
            'steps' => [
                ['type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['city' => 'NYC']],
                ['type' => 'function_call', 'id' => 'call_2', 'name' => 'get_weather', 'arguments' => ['city' => 'London']],
            ],
            'usage' => ['input_tokens' => 15, 'output_tokens' => 10, 'total_tokens' => 25],
        ])));

        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_002',
            'model' => 'gemini-3.5-flash',
            'steps' => [['type' => 'text', 'text' => 'NYC: 72°F, London: 60°F.']],
            'usage' => ['input_tokens' => 30, 'output_tokens' => 10, 'total_tokens' => 40],
        ])));

        $weatherTool = new Tool(
            'get_weather',
            'Get weather',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            fn(array $args) => $args['city'] === 'NYC' ? '72°F' : '60°F'
        );

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $response = $client->chat(
            [new Message('user', 'Compare weather NYC and London.')],
            ['tools' => [$weatherTool], 'auto_execute_tools' => true]
        );

        // Both tools should have been called
        $requests = $fakeHttp->getRequests();
        $secondBody = json_decode($requests[1]->getBody(), true);

        $functionResults = array_values(array_filter(
            $secondBody['input'],
            fn($item) => ($item['type'] ?? '') === 'function_result'
        ));

        $this->assertCount(2, $functionResults);
        $this->assertEquals('NYC: 72°F, London: 60°F.', $response->getMessage()->getContent());
    }

    // =========================================================================
    // Max iterations
    // =========================================================================

    public function testRespectsMaxToolIterations(): void {
        $fakeHttp = new FakeHttpClient();

        // Queue many function_call responses to trigger max_tool_iterations
        for ($i = 0; $i < 5; $i++) {
            $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
                'id' => "int_00{$i}",
                'model' => 'gemini-3.5-flash',
                'steps' => [
                    ['type' => 'function_call', 'id' => "call_{$i}", 'name' => 'loop_tool', 'arguments' => []],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
            ])));
        }

        $loopTool = new Tool(
            'loop_tool',
            'A tool that keeps being called',
            ['type' => 'object', 'properties' => []],
            fn(array $args) => 'loop'
        );

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $response = $client->chat(
            [new Message('user', 'Go')],
            ['tools' => [$loopTool], 'auto_execute_tools' => true, 'max_tool_iterations' => 3]
        );

        // Should stop after 3 iterations (1 initial + 3 loops = 4 requests)
        $this->assertLessThanOrEqual(4, count($fakeHttp->getRequests()));
    }

    // =========================================================================
    // No tool calls (passthrough)
    // =========================================================================

    public function testNoToolCallsPassesThrough(): void {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'int_001',
            'model' => 'gemini-3.5-flash',
            'steps' => [['type' => 'text', 'text' => 'Hello!']],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3, 'total_tokens' => 8],
        ])));

        $tool = new Tool('unused', 'Never called', ['type' => 'object', 'properties' => []], fn() => '');

        $client = $this->createGemini3Client();
        $client->setHttpClient($fakeHttp);

        $response = $client->chat(
            [new Message('user', 'Hi')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        // Only one request should be made
        $this->assertEquals(1, count($fakeHttp->getRequests()));
        $this->assertEquals('Hello!', $response->getMessage()->getContent());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createGemini3Client(): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));
    }
}
