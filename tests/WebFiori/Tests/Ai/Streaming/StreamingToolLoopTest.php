<?php

namespace WebFiori\Tests\Ai\Streaming;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Tool\Tool;

/**
 * Streaming auto-execute tool loop (issue #190, ADR-0060).
 *
 * Uses Anthropic, whose stream handler already surfaces tool_use blocks.
 */
class StreamingToolLoopTest extends TestCase {
    public function testStreamToolLoopExecutesToolThenStreamsFinalAnswer(): void {
        $client = new FakeHttpClient();
        // Turn 1: the model streams a tool_use block.
        $client->addStreamingChunks($this->toolUseTurn());
        // Turn 2: after the tool result, the model streams a text answer.
        $client->addStreamingChunks($this->textTurn('It is sunny in Paris.'));

        $provider = $this->provider($client);

        $toolRan = false;
        $tool = new Tool(
            'get_weather',
            'Gets weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            function (array $args) use (&$toolRan): string {
                $toolRan = true;

                return json_encode(['temp' => 24, 'sky' => 'sunny']);
            }
        );

        $finalText = '';
        $completed = null;
        $provider->streamChat(
            [new Message('user', 'weather in Paris?')],
            function (string $token) use (&$finalText): void {
                $finalText .= $token;
            },
            function ($response) use (&$completed): void {
                $completed = $response;
            },
            null,
            [
                ChatOption::TOOLS => [$tool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
            ]
        );

        $this->assertTrue($toolRan, 'the tool should have executed between streamed turns');
        $this->assertNotNull($completed, 'onComplete must fire with the final turn');
        $this->assertFalse($completed->hasToolCalls(), 'final response must be a text answer, not tool calls');
        $this->assertStringContainsString('sunny in Paris', $completed->getMessage()->getContent());
        // Two streamed turns were requested.
        $this->assertCount(2, $client->getRequests());
    }

    public function testStreamWithoutToolsIsSingleTurn(): void {
        $client = new FakeHttpClient();
        $client->addStreamingChunks($this->textTurn('Hello there.'));

        $provider = $this->provider($client);

        $completed = null;
        $provider->streamChat(
            [new Message('user', 'hi')],
            function (): void {
            },
            function ($response) use (&$completed): void {
                $completed = $response;
            }
        );

        $this->assertNotNull($completed);
        $this->assertCount(1, $client->getRequests(), 'no tools → single streamed turn, unchanged');
    }

    private function provider(FakeHttpClient $client): AnthropicClient {
        $provider = new AnthropicClient(new AnthropicClientConfig(
            apiKey: 'test-api-key',
            model: 'claude-sonnet-4-20250514',
        ));
        $provider->setHttpClient($client);

        return $provider;
    }

    /**
     * @return string[]
     */
    private function toolUseTurn(): array {
        return [
            'data: '.json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 20]]])."\n\n",
            'data: '.json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'get_weather']])."\n\n",
            'data: '.json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"location"']])."\n\n",
            'data: '.json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'input_json_delta', 'partial_json' => ':"Paris"}']])."\n\n",
            'data: '.json_encode(['type' => 'content_block_stop', 'index' => 0])."\n\n",
            'data: '.json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 10]])."\n\n",
            'data: '.json_encode(['type' => 'message_stop'])."\n\n",
        ];
    }

    /**
     * @return string[]
     */
    private function textTurn(string $text): array {
        return [
            'data: '.json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_2', 'model' => 'claude-sonnet-4-20250514', 'usage' => ['input_tokens' => 30]]])."\n\n",
            'data: '.json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])."\n\n",
            'data: '.json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $text]])."\n\n",
            'data: '.json_encode(['type' => 'content_block_stop', 'index' => 0])."\n\n",
            'data: '.json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]])."\n\n",
            'data: '.json_encode(['type' => 'message_stop'])."\n\n",
        ];
    }
}
