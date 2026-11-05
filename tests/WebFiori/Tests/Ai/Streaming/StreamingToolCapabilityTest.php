<?php

namespace WebFiori\Tests\Ai\Streaming;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Bedrock\ApiMethod;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;
use WebFiori\Ai\Tool\Tool;

/**
 * Capability signal for the streaming tool loop (issue #190, ADR-0060):
 * providers whose streaming path cannot surface tool calls must throw
 * UnsupportedFeatureException rather than silently never progressing.
 */
class StreamingToolCapabilityTest extends TestCase {
    public function testBedrockInvokeStreamingWithToolsThrows(): void {
        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            apiKey: 'test-bedrock-key',
            apiMethod: ApiMethod::INVOKE,
        ));
        $provider->setHttpClient(new FakeHttpClient());

        $tool = new Tool('t', 'desc', ['type' => 'object', 'properties' => []], fn (array $a): string => '{}');

        $this->expectException(UnsupportedFeatureException::class);
        $provider->streamChat(
            [new Message('user', 'go')],
            function (): void {
            },
            null,
            null,
            [ChatOption::TOOLS => [$tool], ChatOption::AUTO_EXECUTE_TOOLS => true]
        );
    }

    public function testBedrockInvokeStreamingWithoutToolsIsUnaffected(): void {
        // No tools → no loop → no capability check. Should not throw
        // UnsupportedFeatureException (it will try to stream normally).
        $provider = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            apiKey: 'test-bedrock-key',
            apiMethod: ApiMethod::INVOKE,
        ));
        $client = new FakeHttpClient();
        $client->addStreamingChunks(["\n"]); // minimal; stream completes with empty content
        $provider->setHttpClient($client);

        $threw = null;
        try {
            $provider->streamChat([new Message('user', 'hi')], function (): void {
            });
        } catch (UnsupportedFeatureException $e) {
            $threw = $e;
        } catch (\Throwable $e) {
            // Any other error is fine for this assertion's purpose.
        }

        $this->assertNull($threw, 'no-tools streaming must not trip the streaming-tool capability guard');
    }
}
