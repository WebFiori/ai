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
use stdClass;
use WebFiori\Ai\Cache\CacheConfig;
use WebFiori\Ai\Cache\CacheKeyGenerator;
use WebFiori\Ai\Cache\InMemoryCache;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\GoogleBuiltInTool;
use WebFiori\Ai\Tool\OpenAIBuiltInTool;
use WebFiori\Ai\Tool\Tool;

/**
 * The exact-match chat cache key must depend on the tool definitions (issue #204).
 */
class CacheKeyToolsTest extends TestCase {
    public function testBuiltInToolsChangeTheKey(): void {
        $none = $this->key($this->messages(), []);
        $search = $this->key($this->messages(), ['built_in_tools' => [OpenAIBuiltInTool::WEB_SEARCH]]);
        $code = $this->key($this->messages(), ['built_in_tools' => [OpenAIBuiltInTool::CODE_INTERPRETER]]);
        $google = $this->key($this->messages(), ['built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH]]);

        $this->assertCount(4, array_unique([$none, $search, $code, $google]));
    }

    public function testDifferentJsonModeMissesTheCacheEndToEnd(): void {
        [$client, $http] = $this->createClient();

        $client->chat($this->messages(), ['temperature' => 0]);
        $second = $client->chat($this->messages(), ['temperature' => 0, 'json_mode' => true]);

        $this->assertSame('second', $second->getMessage()->getContent());
        $this->assertCount(2, $http->getRequests());
    }

    public function testDifferentToolsMissTheCache(): void {
        [$client, $http] = $this->createClient();

        $first = $client->chat($this->messages(), $this->options([$this->tool('a')]));
        $second = $client->chat($this->messages(), $this->options([$this->tool('b')]));

        $this->assertSame('first', $first->getMessage()->getContent());
        $this->assertSame('second', $second->getMessage()->getContent(), 'Response for tool "a" was served for tool "b"');
        $this->assertCount(2, $http->getRequests());
    }

    public function testEmptyToolsAndBuiltInToolsKeepThePlainKey(): void {
        $plain = $this->key($this->messages(), ['temperature' => 0]);

        $this->assertSame($plain, $this->key($this->messages(), ['temperature' => 0, 'tools' => [], 'built_in_tools' => []]));
        $this->assertSame($plain, $this->key($this->messages(), ['temperature' => 0, 'json_mode' => false]));
    }

    public function testEqualToolDefinitionsGiveTheSameKey(): void {
        $this->assertSame(
            $this->key($this->messages(), ['tools' => [$this->tool('a')]]),
            $this->key($this->messages(), ['tools' => [$this->tool('a')]])
        );
    }

    public function testExtraToolChangesTheKey(): void {
        $this->assertNotSame(
            $this->key($this->messages(), ['tools' => [$this->tool('a')]]),
            $this->key($this->messages(), ['tools' => [$this->tool('a'), $this->tool('b')]])
        );
    }

    // =========================================================================
    // Key generator
    // =========================================================================

    /**
     * Requests without the newly keyed options must keep the key they had
     * before, so entries in persistent caches are not invalidated for nothing.
     */
    public function testKeyForPlainRequestIsUnchanged(): void {
        $key = $this->key(
            [new Message('system', 'Be brief.'), new Message('user', 'Q')],
            ['temperature' => 0, 'max_tokens' => 50, 'stop' => ['x']]
        );

        $this->assertSame('chat_7380411bde7f8cad132f4b391a8190fab7baf414d4188e9ee64f0060e066a3bc', $key);
    }

    public function testNonToolEntriesAreIgnored(): void {
        $this->assertSame(
            $this->key($this->messages(), ['tools' => [$this->tool('a')]]),
            $this->key($this->messages(), ['tools' => [$this->tool('a'), 'not a tool', 42, null]])
        );
        $this->assertSame(
            $this->key($this->messages(), []),
            $this->key($this->messages(), ['tools' => 'not an array', 'built_in_tools' => 'nope'])
        );
    }

    public function testOptionsThatDoNotAffectTheResponseDoNotChangeTheKey(): void {
        $this->assertSame(
            $this->key($this->messages(), ['temperature' => 0]),
            $this->key($this->messages(), ['temperature' => 0, 'request_id' => 'abc', 'audit_context' => ['u' => 1], 'max_tool_iterations' => 3])
        );
    }

    public function testOtherRequestShapingOptionsChangeTheKey(): void {
        $plain = $this->key($this->messages(), []);

        foreach ([
            ['previous_interaction_id' => 'int_1'],
            ['thinking_level' => 'high'],
            ['detail' => 'low'],
        ] as $options) {
            $this->assertNotSame($plain, $this->key($this->messages(), $options), json_encode($options));
        }

        $this->assertNotSame(
            $this->key($this->messages(), ['previous_interaction_id' => 'int_1']),
            $this->key($this->messages(), ['previous_interaction_id' => 'int_2'])
        );
    }

    public function testRequestWithToolsDoesNotReuseResponseForRequestWithoutTools(): void {
        [$client, $http] = $this->createClient();

        $client->chat($this->messages(), $this->options([]));
        $second = $client->chat($this->messages(), $this->options([$this->tool('a')]));

        $this->assertSame('second', $second->getMessage()->getContent(), 'Response without tools was served for a request with tools');
        $this->assertCount(2, $http->getRequests());
    }

    public function testResponseFormatOptionsChangeTheKey(): void {
        $plain = $this->key($this->messages(), []);
        $jsonMode = $this->key($this->messages(), ['json_mode' => true]);
        $schemaA = $this->key($this->messages(), ['json_schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]);
        $schemaB = $this->key($this->messages(), ['json_schema' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]);

        $this->assertCount(4, array_unique([$plain, $jsonMode, $schemaA, $schemaB]));
    }
    /**
     * Control: the same request twice is served from the cache.
     */
    public function testSameToolsHitTheCache(): void {
        [$client, $http] = $this->createClient();
        $tools = [$this->tool('a')];

        $first = $client->chat($this->messages(), $this->options($tools));
        $second = $client->chat($this->messages(), $this->options($tools));

        $this->assertSame('first', $first->getMessage()->getContent());
        $this->assertSame('first', $second->getMessage()->getContent());
        $this->assertCount(1, $http->getRequests());
    }

    public function testToolDescriptionChangesTheKey(): void {
        $other = new Tool('a', 'Another description', ['type' => 'object', 'properties' => new stdClass()], fn () => 'x');

        $this->assertNotSame(
            $this->key($this->messages(), ['tools' => [$this->tool('a')]]),
            $this->key($this->messages(), ['tools' => [$other]])
        );
    }

    /**
     * Order is kept as given: a different order is a miss, never a wrong hit.
     */
    public function testToolOrderChangesTheKey(): void {
        $this->assertNotSame(
            $this->key($this->messages(), ['tools' => [$this->tool('a'), $this->tool('b')]]),
            $this->key($this->messages(), ['tools' => [$this->tool('b'), $this->tool('a')]])
        );
    }

    public function testToolParametersChangeTheKey(): void {
        $other = new Tool('a', 'Tool a', [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
        ], fn () => 'x');

        $this->assertNotSame(
            $this->key($this->messages(), ['tools' => [$this->tool('a')]]),
            $this->key($this->messages(), ['tools' => [$other]])
        );
    }

    /**
     * @return array{0: OpenAIClient, 1: FakeHttpClient}
     */
    private function createClient(): array {
        $http = new FakeHttpClient();

        foreach (['first', 'second'] as $content) {
            $http->addResponse(new HttpResponse(200, [], json_encode([
                'model' => 'gpt-4o',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ]],
            ])));
        }

        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $client->setHttpClient($http);
        $client->setCache(new InMemoryCache());
        $client->setCacheConfig(new CacheConfig(enabled: true));

        return [$client, $http];
    }

    /**
     * @param Message[] $messages
     * @param array<string, mixed> $options
     */
    private function key(array $messages, array $options): string {
        return (new CacheKeyGenerator())->forChat('openai', 'gpt-4o', $messages, $options);
    }

    /**
     * @return Message[]
     */
    private function messages(): array {
        return [new Message('user', 'Q')];
    }

    /**
     * @param Tool[] $tools
     *
     * @return array<string, mixed>
     */
    private function options(array $tools): array {
        return ['temperature' => 0, 'tools' => $tools, 'auto_execute_tools' => false];
    }

    private function tool(string $name): Tool {
        return new Tool($name, 'Tool '.$name, ['type' => 'object', 'properties' => new stdClass()], fn () => 'x');
    }
}
