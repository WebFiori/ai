<?php

namespace WebFiori\Tests\Ai\Streaming;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\Bedrock\ApiMethod;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\Tool;

/**
 * Google Interactions (gemini-3.x) streaming tool loop + a cross-provider
 * capability guard (issue #190, ADR-0060).
 */
class StreamingToolLoopCrossProviderTest extends TestCase {
    public function testGoogleInteractionsStreamingToolLoop(): void {
        $client = new FakeHttpClient();
        // Turn 1: a function_call step (Interactions API).
        $client->addStreamingChunks([
            "event: step.start\ndata: {\"step\":{\"type\":\"model_output\"}}\n\n",
            "event: step.delta\ndata: {\"delta\":{\"type\":\"function_call\",\"id\":\"call_1\",\"name\":\"get_weather\",\"arguments\":{\"location\":\"London\"}}}\n\n",
            "event: interaction.completed\ndata: {\"interaction\":{\"id\":\"int_1\",\"model\":\"gemini-3.5-flash\",\"usage\":{\"total_input_tokens\":10,\"total_output_tokens\":5}}}\n\n",
        ]);
        // Turn 2: the final text answer.
        $client->addStreamingChunks([
            "event: step.start\ndata: {\"step\":{\"type\":\"model_output\"}}\n\n",
            "event: step.delta\ndata: {\"delta\":{\"type\":\"text\",\"text\":\"London is rainy.\"}}\n\n",
            "event: interaction.completed\ndata: {\"interaction\":{\"id\":\"int_2\",\"model\":\"gemini-3.5-flash\",\"usage\":{\"total_input_tokens\":12,\"total_output_tokens\":4}}}\n\n",
        ]);

        $provider = new GoogleClient(new GoogleClientConfig(model: 'gemini-3.5-flash', apiKey: 'test-key'));
        $provider->setHttpClient($client);

        $toolRan = false;
        $tool = new Tool(
            'get_weather',
            'Gets weather',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
            function (array $args) use (&$toolRan): string {
                $toolRan = true;

                return json_encode(['sky' => 'rainy']);
            }
        );

        $streamed = '';
        $completed = null;
        $provider->streamChat(
            [new Message('user', 'weather in London?')],
            function (string $token) use (&$streamed): void {
                $streamed .= $token;
            },
            function ($response) use (&$completed): void {
                $completed = $response;
            },
            null,
            [ChatOption::TOOLS => [$tool], ChatOption::AUTO_EXECUTE_TOOLS => true]
        );

        $this->assertTrue($toolRan);
        $this->assertNotNull($completed);
        $this->assertFalse($completed->hasToolCalls());
        $this->assertSame('London is rainy.', $streamed);
        $this->assertCount(2, $client->getRequests());
    }

    /**
     * Cross-provider guard: the mainstream streaming paths report support for
     * streamed tool calls; the Bedrock Invoke path does not. Uses a tiny probe
     * subclass per provider to read the protected capability method.
     */
    public function testStreamingToolCallCapabilityAcrossProviders(): void {
        $openai = new class (new OpenAIClientConfig(apiKey: 'k', model: 'gpt-4o')) extends OpenAIClient {
            public function probe(): bool {
                return $this->supportsStreamingToolCalls();
            }
        };
        $anthropic = new class (new AnthropicClientConfig(apiKey: 'k', model: 'claude-sonnet-4-20250514')) extends AnthropicClient {
            public function probe(): bool {
                return $this->supportsStreamingToolCalls();
            }
        };
        $google = new class (new GoogleClientConfig(model: 'gemini-2.5-flash', apiKey: 'k')) extends GoogleClient {
            public function probe(): bool {
                return $this->supportsStreamingToolCalls();
            }
        };
        $bedrockConverse = new class (new BedrockClientConfig(region: 'us-east-1', model: 'anthropic.claude-3-5-sonnet-20241022-v2:0', apiKey: 'k', apiMethod: ApiMethod::CONVERSE)) extends BedrockClient {
            public function probe(): bool {
                return $this->supportsStreamingToolCalls();
            }
        };
        $bedrockInvoke = new class (new BedrockClientConfig(region: 'us-east-1', model: 'anthropic.claude-3-5-sonnet-20241022-v2:0', apiKey: 'k', apiMethod: ApiMethod::INVOKE)) extends BedrockClient {
            public function probe(): bool {
                return $this->supportsStreamingToolCalls();
            }
        };

        $this->assertTrue($openai->probe(), 'OpenAI supports streamed tool calls');
        $this->assertTrue($anthropic->probe(), 'Anthropic supports streamed tool calls');
        $this->assertTrue($google->probe(), 'Google supports streamed tool calls');
        $this->assertTrue($bedrockConverse->probe(), 'Bedrock Converse supports streamed tool calls');
        $this->assertFalse($bedrockInvoke->probe(), 'Bedrock Invoke does not surface streamed tool calls');
    }
}
