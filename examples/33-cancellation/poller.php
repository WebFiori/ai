<?php

/**
 * Example 33b: External stop signal via the CancellationTokenSource poller
 *
 * Run: php examples/33-cancellation/poller.php
 *
 * `cancel()` only works inside the process that is blocked on the request. A
 * real "stop" button lives in a *different* HTTP request, which writes a flag
 * to a shared store (cache, database, file). The running request notices that
 * flag through a poller handed to the CancellationTokenSource.
 *
 * This script needs no API key and no network: a FakeHttpClient simulates a
 * slow provider, and an in-memory store stands in for the shared store the
 * "stop" request would write to. It self-checks with assertions.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Exception\OperationCancelledException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

echo "=== External stop signal (poller) ===\n\n";

/**
 * Stands in for a shared store (cache/DB/file) that a separate "stop" request
 * would write to. Keyed by a per-turn id so a stale flag never leaks into the
 * next turn.
 */
final class StopFlagStore {
    /** @var array<string, bool> */
    private array $stopped = [];

    public function requestStop(string $turnId): void {
        $this->stopped[$turnId] = true;
    }

    public function isStopRequested(string $turnId): bool {
        return $this->stopped[$turnId] ?? false;
    }
}

$store = new StopFlagStore();
$turnId = 'turn-42';

// ─── 1. Build the source with a poller ───────────────────────────────────────
// The poller must be cheap: it can run inside the cURL progress callback. An
// exception thrown by it is treated as "not cancelled" and reported once per
// failure streak through the log callback — so a broken store doesn't silently
// disable the stop button.
echo "1. CancellationTokenSource with a poller reading a shared store\n";

$pollerCalls = 0;
$source = new CancellationTokenSource(
    poller: function () use ($store, $turnId, &$pollerCalls): bool {
        $pollerCalls++;

        return $store->isStopRequested($turnId);
    },
    // 0 here so this instant offline demo polls on every checkpoint. In
    // production use ~500ms: while a real request waits, libcurl calls its
    // progress callback about once per second, so the token is re-checked at
    // roughly that cadence (or ~100ms on the curl_multi path), and the poll
    // interval only throttles how often the poller itself runs.
    pollIntervalMs: 0,
    clock: null, // defaults to a monotonic clock; override only for tests
    logCallback: function (string $level, string $message, array $context): void {
        fprintf(STDERR, "[%s] %s\n", $level, $message);
    },
);
$token = $source->getToken();

assert(!$token->isCancelled(), 'no stop requested yet');
echo "   → poller consulted, not cancelled (calls so far: {$pollerCalls})\n\n";

// ─── 2. The "stop" request writes the flag ────────────────────────────────────
// In production this happens in a *different* PHP request/process. Here we just
// call it directly to simulate that out-of-band write.
echo "2. A separate 'stop' request writes the flag to the store\n";
$store->requestStop($turnId);
echo "   → store now reports a stop for {$turnId}\n\n";

// ─── 3. The running request observes the stop ─────────────────────────────────
// Every library checkpoint (the cURL progress callback, the tool loop, the
// retry backoff) goes through isCancelled(), so they all see the external stop
// with no extra wiring. A cancelled non-streaming chat() throws.
echo "3. The in-flight chat() observes the stop and aborts\n";

$http = new FakeHttpClient();
$http->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'unreachable'], 'finish_reason' => 'stop']],
    'model' => 'gpt-4o',
    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
])));

$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
$provider->setHttpClient($http);

$cancelled = false;

try {
    $provider->chat(
        [new Message('user', 'Write a very long essay...')],
        [ChatOption::CANCELLATION_TOKEN => $token]
    );
} catch (OperationCancelledException $e) {
    $cancelled = true;
}

assert($cancelled, 'chat() should abort once the poller reports the external stop');
assert($source->isCancelled(), 'the source should now be cancelled via the poller');
echo "   ✅ chat() threw OperationCancelledException from the external stop\n\n";

// ─── 4. A throwing poller fails safe ──────────────────────────────────────────
// If the store is down, the poller throws. The request is treated as "not
// cancelled" (so a broken store cannot wedge every request), and the failure is
// logged once per streak via the log callback.
echo "4. A throwing poller is treated as 'not cancelled' (fail-safe)\n";

$brokenSource = new CancellationTokenSource(
    poller: function (): bool {
        throw new RuntimeException('store unreachable');
    },
    pollIntervalMs: 0,
    clock: null,
    logCallback: function (string $level, string $message): void {
        echo "   → logged once: [{$level}] {$message}\n";
    },
);

assert(!$brokenSource->getToken()->isCancelled(), 'a throwing poller must not cancel the request');
// Further checks in the same failure streak do not re-log.
$brokenSource->getToken()->isCancelled();
echo "   ✅ broken store did not cancel the request, and logged only once\n\n";

echo "Poller example verified.\n";
echo "\nReal wiring: replace StopFlagStore with your cache/DB and set the poller\n";
echo "to read it (remember clearstatcache() first if you poll a flag file).\n";
