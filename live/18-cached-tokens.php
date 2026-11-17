<?php

/**
 * Live Test 18: Cached input tokens (ADR-0062, epic #196)
 *
 * Sends the same long prompt twice to each provider and checks what the
 * library reports in Usage and CostResult:
 *
 *   - prompt tokens cover the whole input (not just the uncached part),
 *   - cached + cache-write tokens never exceed prompt tokens,
 *   - when the provider reports a cache hit, the cost is lower than the
 *     uncached price would give.
 *
 * Provider caching is best-effort and timing dependent. A call that reports no
 * cache hit prints a warning and still passes, because the library cannot force
 * a hit. Anthropic and Bedrock only cache when the request carries cache
 * markers, which this library does not send yet, so they normally report 0.
 *
 * Usage:
 *   source keys/env.sh && php live/18-cached-tokens.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\PricingConfig;
use WebFiori\Ai\Provider\AbstractClient;

section('Cached input tokens');

/**
 * Builds a long, deterministic system prompt (several thousand tokens) that is
 * identical across calls within one run, and different between runs so the first
 * call is a cold read.
 */
function cacheProbePrompt(): string {
    static $nonce = null;
    $nonce ??= bin2hex(random_bytes(6));

    $paragraph = 'The library routes chat requests to a provider, measures the token usage reported '
        .'in the response, and prices the request from a configurable per-million-token table. '
        .'Cached input tokens are billed at a lower rate than uncached ones. ';

    return "Reference document {$nonce}.\n".str_repeat($paragraph, 220);
}

function cacheProbeMessages(string $question): array {
    return [
        new Message('system', cacheProbePrompt()),
        new Message('user', $question),
    ];
}

function warn(string $message): void {
    echo "\033[33m  ⚠️  WARN\033[0m  {$message}\n";
}

function usageLine(string $label, ChatResponse $response): void {
    $usage = $response->getUsage();

    if ($usage === null) {
        echo "    → {$label}: no usage reported\n";

        return;
    }

    echo "    → {$label}: prompt={$usage->getPromptTokens()} cached={$usage->getCachedTokens()} "
        ."writes={$usage->getCacheWriteTokens()} completion={$usage->getCompletionTokens()}\n";
}

function checkInvariants(ChatResponse $response, int $minPrompt): void {
    $usage = $response->getUsage();

    if ($usage === null) {
        throw new RuntimeException('Response has no usage');
    }

    if ($usage->getPromptTokens() < $minPrompt) {
        throw new RuntimeException(
            "Prompt tokens {$usage->getPromptTokens()} < {$minPrompt}: the count looks like only the uncached part"
        );
    }

    if ($usage->getCachedTokens() + $usage->getCacheWriteTokens() > $usage->getPromptTokens()) {
        throw new RuntimeException('cached + cache-write tokens exceed prompt tokens');
    }
}

/**
 * Runs the two-call probe against one provider.
 *
 * @param callable(): AbstractClient $factory Creates the client.
 * @param bool $expectCache Whether this provider should cache without cache markers.
 */
function cacheProbe(string $name, callable $factory, bool $expectCache): void {
    run("{$name}: cached tokens and cost on a repeated prompt", function () use ($factory, $expectCache, $name)
    {
        $client = $factory();

        $first = $client->chat(cacheProbeMessages('Reply with the single word: one.'));
        usageLine('call 1', $first);
        checkInvariants($first, 2000);

        // Price the model that actually answered, with a deep discount on cached input.
        $model = $first->getModel();
        $client->setPricing(new PricingConfig([
            $model => ['input' => 1.00, 'output' => 4.00, 'cached_input' => 0.10, 'cache_write' => 1.25],
        ]));

        // Give the provider a moment to make the prefix available.
        sleep(3);

        $second = $client->chat(cacheProbeMessages('Reply with the single word: two.'));
        usageLine('call 2', $second);
        checkInvariants($second, 2000);

        $usage = $second->getUsage();
        $cost = $second->getCost();

        if ($cost === null) {
            throw new RuntimeException("No cost calculated for model '{$model}'");
        }

        $uncachedInput = ($usage->getPromptTokens() / 1_000_000) * 1.00;
        echo sprintf(
            "    → input cost %.6f (all-uncached would be %.6f), cached part %.6f\n",
            $cost->getInputCost(),
            $uncachedInput,
            $cost->getCachedInputCost()
        );

        if ($usage->getCachedTokens() > 0) {
            if ($cost->getInputCost() >= $uncachedInput) {
                throw new RuntimeException('Cache hit reported but the input cost is not lower');
            }
        } elseif ($expectCache) {
            warn("{$name} reported no cache hit on the second call (best-effort caching).");
        } else {
            echo "    → no cache hit reported (expected: this library sends no cache markers)\n";
        }
    });
}

/**
 * Streams the same prompt and checks that usage reaches onComplete.
 */
function streamProbe(string $name, callable $factory): void {
    run("{$name}: streaming reports usage", function () use ($factory)
    {
        $client = $factory();
        $completed = null;

        $client->streamChat(
            cacheProbeMessages('Reply with the single word: three.'),
            function (string $token) {},
            function ($response) use (&$completed)
            {
                $completed = $response;
            }
        );

        if ($completed === null) {
            throw new RuntimeException('onComplete was not called');
        }

        usageLine('stream', $completed);
        checkInvariants($completed, 2000);
    });
}

// ─── OpenAI ───────────────────────────────────────────────────────────────────
if (getenv('OPENAI_API_KEY')) {
    cacheProbe('OpenAI', 'openaiClient', true);
    streamProbe('OpenAI', 'openaiClient');
} else {
    warn('OPENAI_API_KEY not set, skipping OpenAI');
}

// ─── Gemini 2.x on Vertex AI (generateContent) ───────────────────────────────
if (is_file(KEY_PATH)) {
    cacheProbe('Gemini 2.x (generateContent)', 'gemini2Client', true);
    streamProbe('Gemini 2.x (generateContent)', 'gemini2Client');

    // ─── Gemini 3.x (Interactions API) ───────────────────────────────────────
    cacheProbe('Gemini 3.x (Interactions)', 'gemini3Client', true);
    streamProbe('Gemini 3.x (Interactions)', 'gemini3Client');
} else {
    warn('GCP key file not found, skipping Gemini');
}

// ─── Anthropic ────────────────────────────────────────────────────────────────
if (getenv('ANTHROPIC_API_KEY')) {
    cacheProbe('Anthropic', 'anthropicClient', false);
    streamProbe('Anthropic', 'anthropicClient');
} else {
    warn('ANTHROPIC_API_KEY not set, skipping Anthropic');
}

// ─── Bedrock ──────────────────────────────────────────────────────────────────
if (getenv('BEDROCK_API_KEY') || getenv('AWS_ACCESS_KEY_ID')) {
    cacheProbe('Bedrock (Converse)', 'bedrockClient', false);
    streamProbe('Bedrock (Converse)', 'bedrockClient');
} else {
    warn('Bedrock credentials not set, skipping Bedrock');
}
