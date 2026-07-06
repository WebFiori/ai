<?php

/**
 * Example 09: Testing with FakeHttpClient
 *
 * Run: vendor/bin/phpunit examples/09-testing/ExampleTest.php
 *
 * Demonstrates testing patterns for code that uses the AI library.
 */

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

class ExampleTest extends TestCase {
    /**
     * Test that your application correctly uses the AI response.
     */
    public function testChatResponseHandling(): void {
        // Arrange: create a fake client with a pre-defined response
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'PHP is a scripting language.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 6, 'total_tokens' => 16],
        ])));

        $provider = new OpenAIClient(['api_key' => 'sk-test']);
        $provider->setHttpClient($client);

        // Act: call your application code that uses the provider
        $response = $provider->chat([
            new Message('user', 'What is PHP?'),
        ]);

        // Assert: verify the response
        $this->assertEquals('PHP is a scripting language.', $response->getMessage()->getContent());
        $this->assertEquals(16, $response->getUsage()->getTotalTokens());
    }

    /**
     * Test error handling in your application.
     */
    public function testHandlesRateLimitGracefully(): void {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(429, ['Retry-After' => '30'], json_encode([
            'error' => ['message' => 'Rate limit exceeded', 'type' => 'rate_limit_error'],
        ])));

        $provider = new OpenAIClient(['api_key' => 'sk-test']);
        $provider->setHttpClient($client);

        $this->expectException(WebFiori\Ai\Exception\RateLimitException::class);
        $provider->chat([new Message('user', 'Hello')]);
    }

    /**
     * Test that your code sends the correct request format.
     */
    public function testRequestFormat(): void {
        $client = new FakeHttpClient();
        $client->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'OK'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));

        $provider = new OpenAIClient(['api_key' => 'sk-my-key', 'model' => 'gpt-4o']);
        $provider->setHttpClient($client);

        $provider->chat(
            [new Message('user', 'Hello')],
            ['temperature' => 0.5]
        );

        // Assert on the request that was sent
        $request = $client->getLastRequest();
        $this->assertEquals('POST', $request->getMethod());
        $this->assertStringContainsString('/chat/completions', $request->getUrl());
        $this->assertEquals('Bearer sk-my-key', $request->getHeader('Authorization'));

        $body = json_decode($request->getBody(), true);
        $this->assertEquals('gpt-4o', $body['model']);
        $this->assertEquals(0.5, $body['temperature']);
        $this->assertEquals('Hello', $body['messages'][0]['content']);
    }

    /**
     * Test streaming responses.
     */
    public function testStreaming(): void {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}],\"model\":\"gpt-4o\"}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\" World\"},\"finish_reason\":null}],\"model\":\"gpt-4o\"}\n\n",
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"model\":\"gpt-4o\"}\n\n",
            "data: [DONE]\n\n",
        ]);

        $provider = new OpenAIClient(['api_key' => 'sk-test']);
        $provider->setHttpClient($client);

        $tokens = [];

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$tokens)
            {
                $tokens[] = $token;
            }
        );

        $this->assertEquals(['Hello', ' World'], $tokens);
    }
}
