<?php

/**
 * Example 31: Mid-Loop Context Truncation Verification
 *
 * Demonstrates ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS (ADR-0056):
 * re-applying the context window strategy *between* tool-execution iterations
 * so a long tool chain stays within budget — using pairing-safe group
 * truncation that never orphans a tool_call or tool result.
 *
 * Uses FakeHttpClient, so no API key is required.
 *
 * Run: php examples/31-mid-loop-truncation/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Context\ToolLoopOverflowBehavior;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\Tool;

echo "=== Mid-Loop Context Truncation Verification ===\n\n";

/**
 * Builds a FakeHttpClient scripted to request `$toolRounds` tool calls and then
 * return a final text completion — mimicking OpenAI's tool-calling protocol.
 */
function scriptedOpenAiHttp(int $toolRounds): FakeHttpClient {
    $http = new FakeHttpClient();

    for ($i = 0; $i < $toolRounds; $i++) {
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_'.$i,
                        'type' => 'function',
                        'function' => [
                            'name' => 'extract_document',
                            'arguments' => json_encode(['doc_id' => 'doc-'.$i]),
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])));
    }

    $http->addResponse(new HttpResponse(200, [], json_encode([
        'model' => 'gpt-4o',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'All documents summarized.'],
            'finish_reason' => 'stop',
        ]],
    ])));

    return $http;
}

// A tool returning large (but individually bounded) output. Accumulated across
// several rounds, it would blow past the budget without mid-loop truncation.
$bigChunk = str_repeat('Detailed financial figures for the quarter. ', 200);
$extract = new Tool(
    'extract_document',
    'Extracts the text content of a document by id.',
    [
        'type' => 'object',
        'properties' => ['doc_id' => ['type' => 'string']],
        'required' => ['doc_id'],
    ],
    fn (array $args): string => 'DOC '.$args['doc_id'].': '.$bigChunk
);

$systemAndUser = [
    new Message('system', 'You are a document auditor.'),
    new Message('user', 'Extract doc-0, doc-1, doc-2, then summarize.'),
];

// ── Case A: WITHOUT the opt-in → context accumulates every tool-call group ──
echo "A. Default (no mid-loop truncation)\n";
$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
$provider->setContextWindowStrategy(new SlidingWindowStrategy(maxTokens: 4000, reserveForCompletion: 500));
$httpA = scriptedOpenAiHttp(3);
$provider->setHttpClient($httpA);

$provider->chat($systemAndUser, [
    ChatOption::TOOLS => [$extract],
    ChatOption::AUTO_EXECUTE_TOOLS => true,
    ChatOption::MAX_TOOL_ITERATIONS => 5,
]);

$lastBodyA = json_decode($httpA->getLastRequest()->getBody(), true);
$countA = count($lastBodyA['messages']);
echo "   Final request carried {$countA} messages (full accumulated history)\n\n";

// ── Case B: WITH the opt-in → context stays bounded, pairing preserved ──
echo "B. With TRUNCATE_BETWEEN_TOOL_ITERATIONS = true\n";
$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
$provider->setContextWindowStrategy(new SlidingWindowStrategy(maxTokens: 4000, reserveForCompletion: 500));
$httpB = scriptedOpenAiHttp(3);
$provider->setHttpClient($httpB);

$provider->chat($systemAndUser, [
    ChatOption::TOOLS => [$extract],
    ChatOption::AUTO_EXECUTE_TOOLS => true,
    ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,
    ChatOption::TOOL_LOOP_OVERFLOW_BEHAVIOR => ToolLoopOverflowBehavior::DROP_OLDEST,
    ChatOption::MAX_TOOL_ITERATIONS => 5,
]);

$lastBodyB = json_decode($httpB->getLastRequest()->getBody(), true);
$countB = count($lastBodyB['messages']);
echo "   Final request carried {$countB} messages (bounded)\n";

// Assert pairing integrity on every request sent in case B.
$pairingOk = true;

foreach ($httpB->getRequests() as $req) {
    $body = json_decode($req->getBody(), true);
    $callIds = [];
    $resultIds = [];

    foreach ($body['messages'] ?? [] as $m) {
        foreach ($m['tool_calls'] ?? [] as $tc) {
            $callIds[$tc['id']] = true;
        }

        if (($m['role'] ?? '') === 'tool' && isset($m['tool_call_id'])) {
            $resultIds[$m['tool_call_id']] = true;
        }
    }

    foreach ($callIds as $id => $_) {
        if (!isset($resultIds[$id])) {
            $pairingOk = false;
        }
    }

    foreach ($resultIds as $id => $_) {
        if (!isset($callIds[$id])) {
            $pairingOk = false;
        }
    }
}

echo '   Pairing integrity across all requests: '.($pairingOk ? "✅ intact" : "❌ orphaned")."\n\n";

// ── Results ──
if ($countB < $countA && $pairingOk) {
    echo "=== PASS: mid-loop truncation bounded the context ({$countB} < {$countA}) "
        ."with no orphaned tool calls/results ===\n";
    exit(0);
}

echo "=== FAIL: expected bounded, pairing-safe context ===\n";
exit(1);
