<?php

/**
 * Example 34: Streaming tool loop (ADR-0060)
 *
 * Run: php examples/34-streaming-tool-loop/verify.php
 *
 * Demonstrates and self-checks streaming + tool calling with FakeHttpClient
 * (no API key, no network):
 *   1. A streamed turn that calls a tool is executed mid-stream, then the model
 *      streams a final text answer — only the final answer's tokens reach the
 *      caller's onToken (intermediate tool-call turns are consumed internally).
 *   2. With FINAL_SYNTHESIS_ON_MAX_ITERATIONS, hitting the iteration cap with
 *      pending tool calls triggers one tools-off streaming turn so the model
 *      still answers in text.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\Tool;

echo "=== Streaming Tool Loop Verification ===\n\n";

function makeProvider(FakeHttpClient $http): OpenAIClient {
    $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
    $provider->setHttpClient($http);

    return $provider;
}

/** A streamed assistant turn that calls get_weather (arguments split across deltas). */
function toolCallTurn(): array {
    return [
        'data: '.json_encode(['model' => 'gpt-4o', 'choices' => [['delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '']]]], 'finish_reason' => null]]])."\n\n",
        'data: '.json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"city"']]]], 'finish_reason' => null]]])."\n\n",
        'data: '.json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => ':"Paris"}']]]], 'finish_reason' => null]]])."\n\n",
        'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]])."\n\n",
        'data: [DONE]'."\n\n",
    ];
}

/** A streamed assistant turn that returns a text answer. */
function textTurn(string $text): array {
    return [
        'data: '.json_encode(['model' => 'gpt-4o', 'choices' => [['delta' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => null]]])."\n\n",
        'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]])."\n\n",
        'data: [DONE]'."\n\n",
    ];
}

function weatherTool(bool &$ranFlag): Tool {
    return new Tool(
        'get_weather',
        'Gets the weather for a city',
        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        function (array $args) use (&$ranFlag): string {
            $ranFlag = true;

            return json_encode(['city' => $args['city'] ?? '?', 'temp_c' => 21, 'sky' => 'clear']);
        }
    );
}

// ─── 1. Streaming tool loop: tool runs mid-stream, final answer streams ───────
echo "1. Streamed tool call executed, then the final answer streams\n";
$http = new FakeHttpClient();
$http->addStreamingChunks(toolCallTurn());
$http->addStreamingChunks(textTurn('Paris is 21C and clear.'));

$toolRan = false;
$tool = weatherTool($toolRan);

$streamed = '';
$completed = null;
makeProvider($http)->streamChat(
    [new Message('user', 'weather in Paris?')],
    function (string $token) use (&$streamed): void {
        $streamed .= $token;
        echo '   → token: '.$token."\n";
    },
    function ($response) use (&$completed): void {
        $completed = $response;
    },
    null,
    [ChatOption::TOOLS => [$tool], ChatOption::AUTO_EXECUTE_TOOLS => true]
);

assert($toolRan, 'the tool should execute during the stream');
assert($completed !== null, 'onComplete should fire with the final turn');
assert(!$completed->hasToolCalls(), 'final response must be a text answer');
assert($streamed === 'Paris is 21C and clear.', 'only the final answer streams to the caller');
echo "   ✅ tool executed mid-stream; final answer streamed; no tool-call tokens leaked\n\n";

// ─── 2. Streaming final synthesis on the iteration cap ────────────────────────
echo "2. Iteration cap reached with pending tool calls → streamed synthesis\n";
$http = new FakeHttpClient();
$http->addStreamingChunks(toolCallTurn());      // initial: tool call
$http->addStreamingChunks(toolCallTurn());      // after tool: still a tool call (cap now hit)
$http->addStreamingChunks(textTurn('Based on the data, Paris is clear.')); // synthesis (tools-off)

$toolRan2 = false;
$tool2 = weatherTool($toolRan2);

$synthStreamed = '';
makeProvider($http)->streamChat(
    [new Message('user', 'weather in Paris?')],
    function (string $token) use (&$synthStreamed): void {
        $synthStreamed .= $token;
    },
    null,
    null,
    [
        ChatOption::TOOLS => [$tool2],
        ChatOption::AUTO_EXECUTE_TOOLS => true,
        ChatOption::MAX_TOOL_ITERATIONS => 1,
        ChatOption::FINAL_SYNTHESIS_ON_MAX_ITERATIONS => true,
    ]
);

assert($synthStreamed === 'Based on the data, Paris is clear.', 'synthesis answer should stream to the caller');
echo '   ✅ synthesis turn streamed: '.var_export($synthStreamed, true)."\n\n";

echo "All streaming tool loop outcomes verified.\n";
