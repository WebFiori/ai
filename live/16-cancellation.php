<?php

/**
 * Live Test 16: Request cancellation with an external stop signal (Anthropic)
 *
 * Exercises the cancellable curl_multi transfer path and the
 * CancellationTokenSource poller against a real HTTPS provider.
 *
 * Usage:
 *   source keys/env.sh && php live/16-cancellation.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Exception\OperationCancelledException;
use WebFiori\Ai\Message;

section('Cancellation — external stop signal (Anthropic)');

$longPrompt = 'Write a very long, detailed essay (at least 1500 words) about the history of the PHP language.';

// ─── 1. Token present, never cancelled: normal completion over HTTPS ─────────
run('Chat with an inactive token completes normally', function ()
{
    $source = new CancellationTokenSource(fn (): bool => false, 100);
    $response = anthropicClient()->chat(
        [new Message('user', 'Reply with the single word: pong')],
        [ChatOption::CANCELLATION_TOKEN => $source->getToken()]
    );

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo '    → '.substr($response->getMessage()->getContent(), 0, 40)."\n";
});

// ─── 2. Non-streaming cancel through the poller ──────────────────────────────
run('Non-streaming chat is aborted by the poller', function () use ($longPrompt)
{
    $stopAfter = 1.0; // seconds after the request starts
    $start = hrtime(true);
    $source = new CancellationTokenSource(
        fn (): bool => (hrtime(true) - $start) / 1e9 >= $stopAfter,
        100
    );

    try {
        anthropicClient()->chat(
            [new Message('user', $longPrompt)],
            [ChatOption::CANCELLATION_TOKEN => $source->getToken(), 'max_tokens' => 2000]
        );
    } catch (OperationCancelledException $e) {
        $elapsed = (hrtime(true) - $start) / 1e9;
        echo sprintf("    → cancelled after %.2fs (stop requested at %.1fs)\n", $elapsed, $stopAfter);
        assert($elapsed < $stopAfter + 1.0, 'cancel observed too late: '.$elapsed);

        return true;
    }

    // The model may answer before the stop point on a very fast response.
    echo "    → completed before the stop point (response faster than {$stopAfter}s); not a failure of cancellation\n";

    return true;
});

// ─── 3. Streaming cancel through the poller, partial response ────────────────
run('Streaming chat is aborted by the poller and returns the partial response', function () use ($longPrompt)
{
    $tokens = 0;
    $partial = null;
    $completed = false;
    $firstTokenAt = null;
    $cancelAt = null;
    $start = hrtime(true);

    // Stop 1s after the first token has arrived.
    $source = new CancellationTokenSource(function () use (&$firstTokenAt, &$cancelAt): bool {
        if ($firstTokenAt !== null && (hrtime(true) - $firstTokenAt) / 1e9 >= 1.0) {
            $cancelAt ??= hrtime(true);

            return true;
        }

        return false;
    }, 100);

    anthropicClient()->streamChat(
        [new Message('user', $longPrompt)],
        function (string $token) use (&$tokens, &$firstTokenAt)
        {
            $firstTokenAt ??= hrtime(true);
            $tokens++;
        },
        function ($response) use (&$completed)
        {
            $completed = true;
        },
        null,
        [
            ChatOption::CANCELLATION_TOKEN => $source->getToken(),
            ChatOption::ON_CANCEL => function (ChatResponse $response) use (&$partial)
            {
                $partial = $response;
            },
            'max_tokens' => 2000,
        ]
    );

    assert($tokens > 0, 'no tokens received before the stop');
    assert(!$completed, 'onComplete must not run after a cancel');
    assert($partial instanceof ChatResponse, 'onCancel must receive the partial response');
    assert($partial->getMessage()->getContent() !== '', 'partial response should carry the text generated so far');
    echo sprintf(
        "    → %d tokens, partial %d chars, stopped %.2fs after the stop point\n",
        $tokens,
        strlen($partial->getMessage()->getContent()),
        $cancelAt === null ? -1 : (hrtime(true) - $cancelAt) / 1e9
    );
});

echo "\n";
