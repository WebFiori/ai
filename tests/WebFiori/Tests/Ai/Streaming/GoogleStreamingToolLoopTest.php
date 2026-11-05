<?php

namespace WebFiori\Tests\Ai\Streaming;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\Tool;

/**
 * Google generateContent (gemini-2.x) streaming tool-call parsing + the
 * streaming tool loop (issue #190, ADR-0060). generateContent streaming
 * previously dropped functionCall parts.
 */
class GoogleStreamingToolLoopTest extends TestCase {
    public function testStreamedFunctionCallIsParsedAndExecuted(): void {
        $client = new FakeHttpClient();
        $client->addStreamingChunks($this->functionCallTurn());
        $client->addStreamingChunks($this->textTurn('Paris is 24C.'));

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

        $this->assertSame(['location' => 'Paris'], $toolArgs);
        $this->assertNotNull($completed);
        $this->assertFalse($completed->hasToolCalls());
        $this->assertSame('Paris is 24C.', $streamed);
        $this->assertCount(2, $client->getRequests());
    }

    private function provider(FakeHttpClient $client): GoogleClient {
        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-api-key',
        ));
        $provider->setHttpClient($client);

        return $provider;
    }

    /**
     * @return string[]
     */
    private function functionCallTurn(): array {
        return [
            'data: '.json_encode(['candidates' => [[
                'content' => ['parts' => [['functionCall' => ['name' => 'get_weather', 'args' => ['location' => 'Paris']]]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]]])."\n\n",
        ];
    }

    /**
     * @return string[]
     */
    private function textTurn(string $text): array {
        return [
            'data: '.json_encode(['candidates' => [[
                'content' => ['parts' => [['text' => $text]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]]])."\n\n",
        ];
    }
}
