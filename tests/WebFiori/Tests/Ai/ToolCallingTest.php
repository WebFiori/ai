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
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Tests for tool/function calling support.
 *
 * @author Ibrahim
 */
class ToolCallingTest extends TestCase {
    // =========================================================================
    // Tool Class Tests
    // =========================================================================

    /**
     * @test
     */
    public function testToolConstruction() {
        $tool = new Tool(
            'get_weather',
            'Get current weather for a location',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City name'],
                    'unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']],
                ],
                'required' => ['location'],
            ],
            function (array $args): string {
                return json_encode(['temp' => 22, 'unit' => $args['unit'] ?? 'celsius']);
            }
        );

        $this->assertEquals('get_weather', $tool->getName());
        $this->assertEquals('Get current weather for a location', $tool->getDescription());
        $this->assertArrayHasKey('type', $tool->getParameters());
        $this->assertArrayHasKey('properties', $tool->getParameters());
        $this->assertArrayHasKey('required', $tool->getParameters());
    }

    /**
     * @test
     */
    public function testToolExecute() {
        $tool = new Tool(
            'add_numbers',
            'Adds two numbers',
            [
                'type' => 'object',
                'properties' => [
                    'a' => ['type' => 'number'],
                    'b' => ['type' => 'number'],
                ],
                'required' => ['a', 'b'],
            ],
            function (array $args): string {
                return (string) ($args['a'] + $args['b']);
            }
        );

        $result = $tool->execute(['a' => 5, 'b' => 3]);
        $this->assertEquals('8', $result);
    }

    /**
     * @test
     */
    public function testToolExecuteWithComplexReturn() {
        $tool = new Tool(
            'search',
            'Searches the database',
            [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
                'required' => ['query'],
            ],
            function (array $args): string {
                return json_encode(['results' => [['title' => 'Result 1']], 'query' => $args['query']]);
            }
        );

        $result = $tool->execute(['query' => 'PHP']);
        $decoded = json_decode($result, true);
        $this->assertEquals('PHP', $decoded['query']);
        $this->assertCount(1, $decoded['results']);
    }

    // =========================================================================
    // OpenAI - Tool Declaration in Request
    // =========================================================================

    /**
     * @test
     */
    public function testOpenAIToolsInRequest() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi'],
                'finish_reason' => 'stop',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $tool = new Tool(
            'get_weather',
            'Get current weather',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string'],
                ],
                'required' => ['location'],
            ],
            function (array $args): string {
                return '{"temp": 22}';
            }
        );

        $provider->chat(
            [new Message('user', 'What is the weather?')],
            ['tools' => [$tool]]
        );

        $request = $client->getLastRequest();
        $body = json_decode($request->getBody(), true);

        $this->assertArrayHasKey('tools', $body);
        $this->assertCount(1, $body['tools']);
        $this->assertEquals('function', $body['tools'][0]['type']);
        $this->assertEquals('get_weather', $body['tools'][0]['function']['name']);
        $this->assertEquals('Get current weather', $body['tools'][0]['function']['description']);
        $this->assertEquals('object', $body['tools'][0]['function']['parameters']['type']);
    }

    /**
     * @test
     */
    public function testOpenAIMultipleToolsInRequest() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi'],
                'finish_reason' => 'stop',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $tools = [
            new Tool('get_weather', 'Get weather', ['type' => 'object', 'properties' => []], function ($a) {
                return '';
            }),
            new Tool('get_time', 'Get time', ['type' => 'object', 'properties' => []], function ($a) {
                return '';
            }),
        ];

        $provider->chat(
            [new Message('user', 'What time is it?')],
            ['tools' => $tools]
        );

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertCount(2, $body['tools']);
        $this->assertEquals('get_weather', $body['tools'][0]['function']['name']);
        $this->assertEquals('get_time', $body['tools'][1]['function']['name']);
    }

    /**
     * @test
     */
    public function testOpenAINoToolsWhenEmpty() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hi'],
                'finish_reason' => 'stop',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $provider->chat([new Message('user', 'Hello')]);

        $body = json_decode($client->getLastRequest()->getBody(), true);
        $this->assertArrayNotHasKey('tools', $body);
    }

    // =========================================================================
    // Google - Tool Declaration in Request
    // =========================================================================

    /**
     * @test
     */
    public function testGoogleToolsInRequest() {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['text' => 'Hi']],
                ],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = $this->createGoogleProvider();
        $provider->setHttpClient($client);

        $tool = new Tool(
            'get_weather',
            'Get current weather',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string'],
                ],
                'required' => ['location'],
            ],
            function (array $args): string {
                return '{"temp": 22}';
            }
        );

        $provider->chat(
            [new Message('user', 'What is the weather?')],
            ['tools' => [$tool]]
        );

        $request = $client->getLastRequest();
        $body = json_decode($request->getBody(), true);

        $this->assertArrayHasKey('tools', $body);
        $this->assertCount(1, $body['tools']);
        $this->assertArrayHasKey('functionDeclarations', $body['tools'][0]);
        $this->assertEquals('get_weather', $body['tools'][0]['functionDeclarations'][0]['name']);
        $this->assertEquals('Get current weather', $body['tools'][0]['functionDeclarations'][0]['description']);
    }

    // =========================================================================
    // Auto-Execute Tool Loop Tests
    // =========================================================================

    /**
     * @test
     */
    public function testAutoExecuteSingleToolCall() {
        $client = new FakeHttpClient();

        // First response: AI requests a tool call
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_001',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"London"}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));

        // Second response: AI provides final answer after getting tool result
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'The weather in London is 22°C.',
                ],
                'finish_reason' => 'stop',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $executed = false;
        $tool = new Tool(
            'get_weather',
            'Get current weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']],
            function (array $args) use (&$executed): string {
                $executed = true;
                $this->assertEquals('London', $args['location']);

                return '{"temp": 22, "unit": "celsius"}';
            }
        );

        $response = $provider->chat(
            [new Message('user', 'What is the weather in London?')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        $this->assertTrue($executed);
        $this->assertEquals('The weather in London is 22°C.', $response->getMessage()->getContent());
        $this->assertEquals('stop', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testAutoExecuteMultipleToolCalls() {
        $client = new FakeHttpClient();

        // First response: AI requests two tool calls
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        [
                            'id' => 'call_001',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_weather',
                                'arguments' => '{"location":"London"}',
                            ],
                        ],
                        [
                            'id' => 'call_002',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_weather',
                                'arguments' => '{"location":"Paris"}',
                            ],
                        ],
                    ],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));

        // Second response: final answer
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'London: 22°C, Paris: 25°C.',
                ],
                'finish_reason' => 'stop',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $callCount = 0;
        $tool = new Tool(
            'get_weather',
            'Get current weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']],
            function (array $args) use (&$callCount): string {
                $callCount++;
                $temps = ['London' => 22, 'Paris' => 25];

                return json_encode(['temp' => $temps[$args['location']] ?? 0]);
            }
        );

        $response = $provider->chat(
            [new Message('user', 'Weather in London and Paris?')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        $this->assertEquals(2, $callCount);
        $this->assertEquals('London: 22°C, Paris: 25°C.', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testAutoExecuteMultipleIterations() {
        $client = new FakeHttpClient();

        // First response: tool call
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_001',
                        'type' => 'function',
                        'function' => ['name' => 'step_one', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));

        // Second response: another tool call
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_002',
                        'type' => 'function',
                        'function' => ['name' => 'step_two', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));

        // Third response: final answer
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Done with both steps.',
                ],
                'finish_reason' => 'stop',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $steps = [];
        $tools = [
            new Tool('step_one', 'Step one', ['type' => 'object', 'properties' => []], function ($a) use (&$steps) {
                $steps[] = 'one';

                return 'step one done';
            }),
            new Tool('step_two', 'Step two', ['type' => 'object', 'properties' => []], function ($a) use (&$steps) {
                $steps[] = 'two';

                return 'step two done';
            }),
        ];

        $response = $provider->chat(
            [new Message('user', 'Do both steps')],
            ['tools' => $tools, 'auto_execute_tools' => true]
        );

        $this->assertEquals(['one', 'two'], $steps);
        $this->assertEquals('Done with both steps.', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testAutoExecuteRespectsMaxIterations() {
        $client = new FakeHttpClient();

        // Keep returning tool calls indefinitely
        for ($i = 0; $i < 5; $i++) {
            $client->addResponse(new HttpResponse(200, [], json_encode([
                'model' => 'gpt-4o',
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => "call_$i",
                            'type' => 'function',
                            'function' => ['name' => 'infinite_tool', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ])));
        }

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $callCount = 0;
        $tool = new Tool('infinite_tool', 'Loops forever', ['type' => 'object', 'properties' => []], function ($a) use (&$callCount) {
            $callCount++;

            return 'again';
        });

        $response = $provider->chat(
            [new Message('user', 'Loop')],
            ['tools' => [$tool], 'auto_execute_tools' => true, 'max_tool_iterations' => 3]
        );

        // Should stop after 3 iterations
        $this->assertEquals(3, $callCount);
        // Response should be the last tool_calls response (loop hit limit)
        $this->assertTrue($response->hasToolCalls());
    }

    /**
     * @test
     */
    public function testAutoExecuteDisabledByDefault() {
        $client = new FakeHttpClient();

        // Response with tool call
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_001',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"location":"London"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $executed = false;
        $tool = new Tool('get_weather', 'Get weather', ['type' => 'object', 'properties' => []], function ($a) use (&$executed) {
            $executed = true;

            return '';
        });

        $response = $provider->chat(
            [new Message('user', 'Weather?')],
            ['tools' => [$tool]]
        );

        // Tool should NOT be executed — auto_execute_tools not set
        $this->assertFalse($executed);
        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('tool_calls', $response->getFinishReason());
    }

    /**
     * @test
     */
    public function testAutoExecuteUnknownToolReturnsEmptyResult() {
        $client = new FakeHttpClient();

        // AI calls a tool that doesn't exist in our list
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_001',
                        'type' => 'function',
                        'function' => ['name' => 'unknown_tool', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));

        // After getting empty result, AI gives final answer
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'I could not find that tool.',
                ],
                'finish_reason' => 'stop',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $tool = new Tool('get_weather', 'Get weather', ['type' => 'object', 'properties' => []], function ($a) {
            return 'weather data';
        });

        $response = $provider->chat(
            [new Message('user', 'Use unknown tool')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        $this->assertEquals('I could not find that tool.', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testToolCallMessagesIncludedInFollowUp() {
        $client = new FakeHttpClient();

        // First: tool call response
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_001',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"location":"Tokyo"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));

        // Second: final answer
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Tokyo is 30°C.'],
                'finish_reason' => 'stop',
            ]],
        ])));

        $provider = $this->createOpenAIProvider();
        $provider->setHttpClient($client);

        $tool = new Tool('get_weather', 'Get weather', ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']], function ($a) {
            return '{"temp": 30}';
        });

        $provider->chat(
            [new Message('user', 'Weather in Tokyo?')],
            ['tools' => [$tool], 'auto_execute_tools' => true]
        );

        // Verify the second request includes the tool call and result messages
        $lastRequest = $client->getLastRequest();
        $body = json_decode($lastRequest->getBody(), true);
        $messages = $body['messages'];

        // Should have: user, assistant (with tool_calls), tool (with result)
        $this->assertCount(3, $messages);
        $this->assertEquals('user', $messages[0]['role']);
        $this->assertEquals('assistant', $messages[1]['role']);
        $this->assertArrayHasKey('tool_calls', $messages[1]);
        $this->assertEquals('tool', $messages[2]['role']);
        $this->assertEquals('call_001', $messages[2]['tool_call_id']);
        $this->assertEquals('{"temp": 30}', $messages[2]['content']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createOpenAIProvider(): OpenAIClient {
        return new OpenAIClient([
            'api_key' => 'sk-test-key',
            'model' => 'gpt-4o',
        ]);
    }

    private function createGoogleProvider(): GoogleClient {
        return new GoogleClient([
            'api' => 'gemini',
            'access_token' => 'test-access-token',
            'model' => 'gemini-2.5-flash',
        ]);
    }
}
