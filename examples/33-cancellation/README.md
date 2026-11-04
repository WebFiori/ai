# 33 — Request Cancellation

Abort an in-flight AI request with a `CancellationToken`. Cancellation tears
down the HTTP request to the provider (stopping work and token cost), is aware
of the auto-execute tool loop, and — for streaming — surfaces the partial
response so far.

> **Since 1.5.0**

See [ADR-0057](https://github.com/WebFiori/docs/blob/main/adr/0057-ai-request-cancellation.md)
and the [Cancellation docs](https://github.com/WebFiori/docs/blob/main/ai-cancellation.md).

## What It Demonstrates

- Passing a token via `$options[ChatOption::CANCELLATION_TOKEN]`
- Non-streaming `chat()` → throws `OperationCancelledException`
- `streamChat()` → invokes `ChatOption::ON_CANCEL` with the partial
  `ChatResponse` (and does **not** call `onComplete`)
- Auto-execute tool loop → stops promptly and throws `OperationCancelledException`

The verification script uses `FakeHttpClient`, so it needs **no API key and no
network**.

## Running

```bash
php examples/33-cancellation/verify.php
```

Expected: each of the three outcomes prints a `✅` line and the script ends with
"All cancellation outcomes verified."

## Files

| File | Description |
|------|-------------|
| `verify.php` | Offline, self-checking demo of all three cancellation outcomes |
| `poller.php` | Offline demo of the external stop signal via the `CancellationTokenSource` poller |

## External stop signal (another request pressing "stop")

`cancel()` only takes effect in the process that is blocked on the request. A
real stop button lives in a **different** request, which writes a flag to a
shared store (cache, database, file). Give the source a **poller** that reads
that store, and every checkpoint (the cURL progress callback, the tool loop, the
retry backoff) observes the stop with no extra wiring:

```php
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;

$source = new CancellationTokenSource(
    poller: fn (): bool => $store->isStopRequested($turnId),
    pollIntervalMs: 500,
    logCallback: $logger, // fn(string $level, string $message, array $context)
);

$client->chat($messages, [
    ChatOption::CANCELLATION_TOKEN => $source->getToken(),
]);
```

Notes:

- The poller must be **cheap** — it can run inside the cURL progress callback.
- A **throwing** poller is treated as "not cancelled" and logged once per
  failure streak via `logCallback`, so a broken store never silently disables
  the stop button.
- Key the flag **per turn** so a stale flag doesn't cancel the next request.
- If you poll a flag **file**, call `clearstatcache(true, $path)` first.

Run it:

```bash
php examples/33-cancellation/poller.php
```

## Wiring `cancel()` to a real "stop" signal

In a real application you cancel from a different signal than the request
itself — a UI "stop" button hitting an endpoint, or the server detecting that
the client disconnected. The token's `onCancel` listeners let each layer react.

```php
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ChatResponse;

$source = new CancellationTokenSource();
$token  = $source->getToken();

// Subscribe cleanup/logging to the cancel signal (pub/sub; multiple allowed).
$token->onCancel(function (): void {
    error_log('AI request cancelled by client');
});

// Stream to the browser; stop as soon as the client goes away.
$client->streamChat(
    [new \WebFiori\Ai\Message('user', $prompt)],
    function (string $chunk) use ($source): void {
        echo $chunk;
        flush();

        // PHP marks the connection aborted once the client disconnects.
        if (connection_aborted() === 1) {
            $source->cancel();   // tears down the provider request
        }
    },
    onComplete: null,
    onError: null,
    options: [
        ChatOption::CANCELLATION_TOKEN => $token,
        ChatOption::ON_CANCEL => function (ChatResponse $partial): void {
            // Persist/assess whatever was generated before the stop.
            savePartialTranscript($partial->getMessage()->getContent());
        },
    ],
);
```

For a non-streaming call, wrap it and catch the cancellation:

```php
use WebFiori\Ai\Exception\OperationCancelledException;

try {
    $response = $client->chat($messages, [
        ChatOption::CANCELLATION_TOKEN => $token,
    ]);
} catch (OperationCancelledException $e) {
    // The request (and its token cost) was stopped.
}
```

## Guarantee

Cancellation is a **real teardown**: the HTTP request to the provider is aborted
and the socket closed, so the model stops working and token cost stops accruing.
A request carrying a token runs on a `curl_multi` loop that re-checks the token
about every 100 ms, so cancellation takes effect within roughly 100 ms of
`cancel()` (or, with a poller, roughly the poll interval plus ~100 ms). It is not
a literally-instantaneous kill. Requests without a token keep the plain blocking
transfer path, unchanged.
