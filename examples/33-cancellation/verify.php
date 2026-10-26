<?php

/**
 * Example 33: Request Cancellation Verification
 *
 * Run: php examples/33-cancellation/verify.php
 *
 * Demonstrates and self-checks all three cancellation outcomes using
 * FakeHttpClient (no API key, no network):
 *   1. Non-streaming chat() -> OperationCancelledException
 *   2. streamChat() -> ChatOption::ON_CANCEL with the partial ChatResponse
 *      (onComplete is NOT called)
 *   3. Auto-execute tool loop -> OperationCancelledException, loop stops
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Exception\OperationCancelledException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\Tool;

echo "=== Request Cancellation Verification ===\n\n";

function makeProvider(FakeHttpClient $http): OpenAIClient {
    $provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
    $provider->setHttpClient($http);

    return $provider;
}

// ─── 1. Non-streaming cancellation ───────────────────────────────────────────
echo "1. Non-streaming chat() with a pre-cancelled token\n";
$http = new FakeHttpClient();
$http->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'hi'], 'finish_reason' => 'stop']],
    'model' => 'gpt-4o',
    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
])));

$source = new CancellationTokenSource();
$source->cancel(); // simulate "stop" pressed before the call completes

$cancelled = false;

try {
    makeProvider($http)->chat(
        [new Message('user', 'Hello')],
        [ChatOption::CANCELLATION_TOKEN => $source->getToken()]
    );
} catch (OperationCancelledException $e) {
    $cancelled = true;
}

assert($cancelled, 'Non-streaming chat() should throw OperationCancelledException');
echo "   ✅ chat() threw OperationCancelledException\n\n";

// ─── 2. Streaming cancellation surfaces the partial ───────────────────────────
echo "2. streamChat() cancelled mid-stream surfaces the partial response\n";
$http = new FakeHttpClient();
$http->addStreamingChunks([
    'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]])."\n\n",
    'data: '.json_encode(['choices' => [['delta' => ['content' => ' world'], 'finish_reason' => null]]])."\n\n",
    'data: [DONE]'."\n\n",
]);

$source = new CancellationTokenSource();
$partial = null;
$completeCalled = false;

makeProvider($http)->streamChat(
    [new Message('user', 'Say hello world')],
    function (string $token) use ($source): void {
        echo '   → token: '.$token."\n";
        $source->cancel(); // user hits "stop" after the first token
    },
    function ($response) use (&$completeCalled): void {
        $completeCalled = true;
    },
    null,
    [
        ChatOption::CANCELLATION_TOKEN => $source->getToken(),
        ChatOption::ON_CANCEL => function (ChatResponse $p) use (&$partial): void {
            $partial = $p;
        },
    ]
);

assert($partial instanceof ChatResponse, 'onCancel should receive a partial ChatResponse');
assert(!$completeCalled, 'onComplete must not be called on cancel');
assert($partial->getMessage()->getContent() === 'Hello', 'partial should hold the text seen before cancel');
echo "   ✅ onCancel got partial text ".var_export($partial->getMessage()->getContent(), true).", onComplete not called\n\n";

// ─── 3. Tool-loop cancellation ────────────────────────────────────────────────
echo "3. Auto-execute tool loop cancelled while a tool runs\n";
$http = new FakeHttpClient();
$http->addResponse(new HttpResponse(200, [], json_encode([
    'model' => 'gpt-4o',
    'choices' => [[
        'message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'slow_tool', 'arguments' => '{}'],
            ]],
        ],
        'finish_reason' => 'tool_calls',
    ]],
    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
])));
// A second response is queued but must never be reached.
$http->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'unreachable'], 'finish_reason' => 'stop']],
    'model' => 'gpt-4o',
    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
])));

$source = new CancellationTokenSource();
$tool = new Tool(
    'slow_tool',
    'A tool that triggers cancellation while running',
    ['type' => 'object', 'properties' => []],
    function (array $args) use ($source): string {
        $source->cancel(); // user cancels during tool execution

        return '{}';
    }
);

$cancelled = false;

try {
    makeProvider($http)->chat(
        [new Message('user', 'use the tool')],
        [
            ChatOption::TOOLS => [$tool],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::CANCELLATION_TOKEN => $source->getToken(),
        ]
    );
} catch (OperationCancelledException $e) {
    $cancelled = true;
}

assert($cancelled, 'Tool-loop cancellation should surface OperationCancelledException');
assert(count($http->getRequests()) === 1, 'the loop must not issue a second request after cancel');
echo "   ✅ tool loop stopped: OperationCancelledException, no second request\n\n";

echo "All cancellation outcomes verified.\n";
