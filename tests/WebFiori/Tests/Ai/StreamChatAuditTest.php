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
use WebFiori\Ai\Audit\AuditConfig;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;

/**
 * Covers AbstractClient::streamChat() audit + error wrapping paths, which fire
 * only when audit logging is enabled and when the stream fails. Fully offline
 * via FakeHttpClient.
 */
class StreamChatAuditTest extends TestCase {
    private function provider(): AnthropicClient {
        return new AnthropicClient(new AnthropicClientConfig(
            apiKey: 'test-api-key',
            model: 'claude-sonnet-4-20250514',
        ));
    }

    private function streamingChunks(): array {
        return [
            'data: '.json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 3]]])."\n\n",
            'data: '.json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hi']])."\n\n",
            'data: '.json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]])."\n\n",
            "data: [DONE]\n\n",
        ];
    }

    public function testStreamChat_EmitsAuditEntryWithMessagesAndResponse(): void {
        $http = new FakeHttpClient();
        $http->addStreamingChunks($this->streamingChunks());

        $provider = $this->provider();
        $provider->setHttpClient($http);
        $provider->setAuditConfig(new AuditConfig(includeMessages: true, includeResponse: true));

        $auditEntries = [];
        $provider->setAuditCallback(function (array $entry) use (&$auditEntries): void {
            $auditEntries[] = $entry;
        });

        $tokens = '';
        $completed = false;
        $provider->streamChat(
            [new Message('user', 'Hello')],
            function (string $t) use (&$tokens): void {
                $tokens .= $t;
            },
            function ($response) use (&$completed): void {
                $completed = true;
            }
        );

        $this->assertTrue($completed);
        $this->assertSame('Hi', $tokens);

        // A success audit entry for streamChat must have been emitted with
        // the serialized messages and response content included.
        $success = array_values(array_filter(
            $auditEntries,
            fn (array $e): bool => ($e['operation'] ?? null) === 'streamChat' && ($e['status'] ?? null) === 'success'
        ));

        $this->assertNotEmpty($success);
        $this->assertArrayHasKey('messages', $success[0]);
        $this->assertArrayHasKey('response', $success[0]);
    }

    public function testStreamChat_TransportExceptionPropagates(): void {
        $http = new FakeHttpClient();
        // No streaming chunks queued -> FakeHttpClient::sendStreaming() throws.
        // A transport-level failure propagates out of streamChat (it is not
        // routed through the onError callback, which is reserved for parser
        // errors surfaced by the provider stream handler).
        $provider = $this->provider();
        $provider->setHttpClient($http);

        $this->expectException(\RuntimeException::class);

        $provider->streamChat(
            [new Message('user', 'Hello')],
            function (string $t): void {
            }
        );
    }
}
