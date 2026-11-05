<?php

namespace WebFiori\Tests\Ai\Streaming;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\Tool;

/**
 * OpenAI streaming tool-call parsing + the streaming tool loop (issue #190,
 * ADR-0060). OpenAI previously dropped streamed tool_calls; the loop needs them.
 */
class OpenAIStreamingToolLoopTest extends TestCase {
    public function testStreamedToolCallsAreParsedAndExecuted(): void {
        $client = new FakeHttpClient();
        $client->addStreamingChunks($this->toolCallTurn());
        $client->addStreamingChunks($this->textTurn('Paris is 24C and sunny.'));

        $provider = $this->provider($client);

        $toolArgs = null;
        $tool = new Tool(
            'get_weather',
            'Gets weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            function (array $args) use (&$toolArgs): string {
                $toolArgs = $args;

                return json_encode(['temp' => 24]);
            }
        );

        $streamed = '';
        $completed = null;
        $provider->streamChat(
            [new Message('user', 'weather in Paris?')],
            function (string $token) use (&$streamed): void {
                $streamed .= $token;
            },
            function ($response) use (&$completed): void {
                $completed = $response;
            },
            null,
            [ChatOption::TOOLS => [$tool], ChatOption::AUTO_EXECUTE_TOOLS => true]
        );

        $this->assertSame(['location' => 'Paris'], $toolArgs, 'streamed tool_calls arguments must be assembled from deltas');
        $this->assertNotNull($completed);
        $this->assertFalse($completed->hasToolCalls());
        $this->assertSame('Paris is 24C and sunny.', $streamed);
        $this->assertCount(2, $client->getRequests());
    }

    private function provider(FakeHttpClient $client): OpenAIClient {
        $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $provider->setHttpClient($client);

        return $provider;
    }

    /**
     * A streamed assistant turn that calls a tool, with id/name and argument
     * fragments split across deltas (as the OpenAI API does).
     *
     * @return string[]
     */
    private function toolCallTurn(): array {
        return [
            'data: '.json_encode(['model' => 'gpt-4o', 'choices' => [['delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '']]]], 'finish_reason' => null]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"location"']]]], 'finish_reason' => null]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => ':"Paris"}']]]], 'finish_reason' => null]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]])."\n\n",
            'data: [DONE]'."\n\n",
        ];
    }

    /**
     * @return string[]
     */
    private function textTurn(string $text): array {
        return [
            'data: '.json_encode(['model' => 'gpt-4o', 'choices' => [['delta' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => null]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]])."\n\n",
            'data: [DONE]'."\n\n",
        ];
    }
}
