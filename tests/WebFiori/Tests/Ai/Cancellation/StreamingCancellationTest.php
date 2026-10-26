<?php

namespace WebFiori\Tests\Ai\Cancellation;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Streaming cancellation tests (issue #179): a cancelled streamChat() invokes
 * the ChatOption::ON_CANCEL callback with the accumulated partial ChatResponse,
 * and does not invoke onComplete or onError.
 */
class StreamingCancellationTest extends TestCase {
    public function testOnCancelConstant(): void {
        $this->assertSame('on_cancel', ChatOption::ON_CANCEL);
    }

    public function testCancelMidStreamInvokesOnCancelWithPartial(): void {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => ['content' => ' world'], 'finish_reason' => null]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'model' => 'gpt-4o'])."\n\n",
        ]);

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);

        $source = new CancellationTokenSource();
        $seen = '';
        $completeCalled = false;
        $errorCalled = false;
        $partial = null;

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$seen, $source) {
                $seen .= $token;
                // User hits "stop" after the first token arrives.
                $source->cancel();
            },
            function ($response) use (&$completeCalled) {
                $completeCalled = true;
            },
            function (\Throwable $e) use (&$errorCalled) {
                $errorCalled = true;
            },
            [
                ChatOption::CANCELLATION_TOKEN => $source->getToken(),
                ChatOption::ON_CANCEL => function (ChatResponse $p) use (&$partial) {
                    $partial = $p;
                },
            ]
        );

        $this->assertFalse($completeCalled, 'onComplete must not be called on cancel');
        $this->assertFalse($errorCalled, 'onError must not be called on cancel');
        $this->assertInstanceOf(ChatResponse::class, $partial);
        // Only the first chunk was delivered before cancel aborted the stream.
        $this->assertSame('Hello', $partial->getMessage()->getContent());
        $this->assertSame('cancelled', $partial->getFinishReason());
    }

    public function testCancelBeforeFirstChunkGivesEmptyPartial(): void {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]])."\n\n",
        ]);

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);

        // Pre-cancelled: FakeHttpClient aborts before emitting any chunk.
        $source = new CancellationTokenSource();
        $source->cancel();

        $tokensSeen = 0;
        $partial = null;
        $completeCalled = false;

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) use (&$tokensSeen) {
                $tokensSeen++;
            },
            function ($response) use (&$completeCalled) {
                $completeCalled = true;
            },
            null,
            [
                ChatOption::CANCELLATION_TOKEN => $source->getToken(),
                ChatOption::ON_CANCEL => function (ChatResponse $p) use (&$partial) {
                    $partial = $p;
                },
            ]
        );

        $this->assertSame(0, $tokensSeen, 'No tokens should be delivered when cancelled before first chunk');
        $this->assertFalse($completeCalled);
        $this->assertInstanceOf(ChatResponse::class, $partial);
        $this->assertSame('', $partial->getMessage()->getContent());
    }

    public function testNormalStreamStillCompletes(): void {
        $client = new FakeHttpClient();
        $client->addStreamingChunks([
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'model' => 'gpt-4o'])."\n\n",
            "data: [DONE]\n\n",
        ]);

        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);

        $completed = null;
        $cancelCalled = false;

        // Token present but never cancelled: must complete normally.
        $source = new CancellationTokenSource();

        $provider->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {},
            function ($response) use (&$completed) {
                $completed = $response;
            },
            null,
            [
                ChatOption::CANCELLATION_TOKEN => $source->getToken(),
                ChatOption::ON_CANCEL => function (ChatResponse $p) use (&$cancelCalled) {
                    $cancelCalled = true;
                },
            ]
        );

        $this->assertFalse($cancelCalled, 'ON_CANCEL must not fire on a normal stream');
        $this->assertNotNull($completed);
        $this->assertSame('Hello', $completed->getMessage()->getContent());
    }
}
