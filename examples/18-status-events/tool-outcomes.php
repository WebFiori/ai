<?php

/**
 * Example: Per-tool success/failure status events (#164)
 *
 * Run: php examples/18-status-events/tool-outcomes.php
 *
 * Demonstrates that the status stream reports whether each tool call
 * succeeded or failed — not just that it "completed":
 *   1. A successful tool  → Status::TOOL_COMPLETED with ok = true
 *   2. A tool returning ToolResponse::error() → Status::TOOL_FAILED (tool_error)
 *   3. A tool that throws  → Status::TOOL_FAILED (exception) — batch continues
 *
 * This is emitter-agnostic: the same context flows to SSE, callbacks, and the
 * StatusMessageFormatter. Here we use a CallbackStatusEmitter (the non-SSE path)
 * and switch on the status, as a programmatic consumer would.
 *
 * Keys are read from the repo `keys/` folder or the environment — never
 * hard-coded. See keys/env.sh.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Status;
use WebFiori\Ai\StatusMessageFormatter;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolResponse;

// ─── Resolve API key without embedding it in source ──────────────────────────
$keysDir = __DIR__.'/../../keys';
$apiKey = getenv('OPENAI_API_KEY') ?: null;

if ($apiKey === null && is_readable($keysDir.'/OpenAIKey.txt')) {
    $apiKey = trim((string) file_get_contents($keysDir.'/OpenAIKey.txt'));
}

if (!$apiKey) {
    fwrite(STDERR, "No OpenAI API key found. Set OPENAI_API_KEY or add keys/OpenAIKey.txt.\n");
    exit(1);
}

$model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';

$client = new OpenAIClient(new OpenAIClientConfig(
    apiKey: $apiKey,
    model: $model,
));

// ─── Status consumer: switch on outcome (non-SSE, callback path) ─────────────
$formatter = new StatusMessageFormatter();

$client->setStatusEmitter(new CallbackStatusEmitter(
    function (string $status, array $context) use ($formatter): void
    {
        switch ($status) {
            case Status::TOOL_COMPLETED:
                printf(
                    "  ✅ %s [%s] ok in %dms\n",
                    $context['tool'],
                    $context['tool_call_id'] ?? '?',
                    $context['duration_ms'] ?? 0
                );
                break;

            case Status::TOOL_FAILED:
                printf(
                    "  ❌ %s [%s] FAILED (%s) in %dms: %s\n",
                    $context['tool'],
                    $context['tool_call_id'] ?? '?',
                    $context['error_type'] ?? 'unknown',
                    $context['duration_ms'] ?? 0,
                    $context['error'] ?? ''
                );
                break;

            default:
                echo '  '.$formatter->format($status, $context).PHP_EOL;
        }
    }
));

// ─── Tools: one succeeds, one returns an error, one throws ───────────────────
$objSchema = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]];

$goodTool = new Tool(
    'lookup_ok',
    'A tool that always succeeds.',
    $objSchema,
    fn (array $args): string => json_encode(['result' => 'found'])
);

$errorTool = new Tool(
    'lookup_missing',
    'A tool that reports a domain error via ToolResponse::error().',
    $objSchema,
    fn (array $args): ToolResponse => ToolResponse::error('No record matched the query.')
);

$throwingTool = new Tool(
    'lookup_broken',
    'A tool that throws an exception.',
    $objSchema,
    function (array $args): string
    {
        throw new RuntimeException('Upstream service unavailable');
    }
);

echo "User: Call lookup_ok, lookup_missing, and lookup_broken with q='test'.\n\n";

$response = $client->chat(
    [
        new Message('system', 'You must call all three tools: lookup_ok, lookup_missing, and lookup_broken, each with q="test".'),
        new Message('user', 'Please call all three tools now.'),
    ],
    [
        'tools' => [$goodTool, $errorTool, $throwingTool],
        'auto_execute_tools' => true,
    ]
);

echo PHP_EOL.'AI: '.$response->getMessage()->getContent().PHP_EOL;
