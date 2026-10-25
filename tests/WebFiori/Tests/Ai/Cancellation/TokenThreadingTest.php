<?php

namespace WebFiori\Tests\Ai\Cancellation;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Cross-provider guard (issue #178): a CancellationToken placed in $options
 * must reach the HttpRequest that the shared AbstractClient dispatch point
 * sends, for every provider — proving the central wiring is provider-agnostic
 * and that no provider silently drops it.
 */
class TokenThreadingTest extends TestCase {
    public function testOpenAiChatCarriesTokenOnSentRequest(): void {
        $fake = new FakeHttpClient();
        $fake->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'hi'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));

        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $client->setHttpClient($fake);

        $token = (new CancellationTokenSource())->getToken();
        $client->chat([new Message('user', 'hi')], [ChatOption::CANCELLATION_TOKEN => $token]);

        $this->assertSame($token, $fake->getLastRequest()->getCancellationToken());
    }

    public function testAnthropicChatCarriesTokenOnSentRequest(): void {
        $fake = new FakeHttpClient();
        $fake->addResponse(new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $client = new AnthropicClient(new AnthropicClientConfig(apiKey: 'test-key', model: 'claude-sonnet-4-20250514'));
        $client->setHttpClient($fake);

        $token = (new CancellationTokenSource())->getToken();
        $client->chat([new Message('user', 'hi')], [ChatOption::CANCELLATION_TOKEN => $token]);

        $this->assertSame($token, $fake->getLastRequest()->getCancellationToken());
    }

    public function testNoTokenMeansNullOnSentRequest(): void {
        $fake = new FakeHttpClient();
        $fake->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'hi'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));

        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $client->setHttpClient($fake);

        $client->chat([new Message('user', 'hi')]);

        $this->assertNull($fake->getLastRequest()->getCancellationToken());
    }

    public function testNonTokenValueUnderOptionIsIgnored(): void {
        $fake = new FakeHttpClient();
        $fake->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'hi'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));

        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $client->setHttpClient($fake);

        // A garbage value under the option key must not break and must not be
        // treated as a token.
        $client->chat([new Message('user', 'hi')], [ChatOption::CANCELLATION_TOKEN => 'not-a-token']);

        $this->assertNull($fake->getLastRequest()->getCancellationToken());
    }
}
