<?php

/**
 * Example 38: Cached Input Tokens Verification
 *
 * Run: php examples/38-cached-tokens/verify.php
 *
 * Shows how cached input tokens are reported and priced, using FakeHttpClient
 * (no API key, no network):
 *   1. OpenAI: prompt_tokens_details.cached_tokens
 *   2. Anthropic: cache reads/writes are added to the prompt total
 *   3. Pricing: cached input price, with fallback to the input price
 *   4. Clamping and serialization of old payloads
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\PricingConfig;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Usage;

echo "=== Cached Input Tokens Verification ===\n\n";

$failures = 0;

function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '  ✅ ' : '  ❌ ').$label."\n";

    if (!$ok) {
        $failures++;
    }
}

// ─── 1. OpenAI ───────────────────────────────────────────────────────────────
echo "1. OpenAI reports cached tokens\n";
$http = new FakeHttpClient();
$http->addResponse(new HttpResponse(200, [], json_encode([
    'model' => 'gpt-4o',
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']],
    'usage' => [
        'prompt_tokens' => 2006,
        'completion_tokens' => 300,
        'prompt_tokens_details' => ['cached_tokens' => 1920],
    ],
])));
$openai = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
$openai->setHttpClient($http);
$usage = $openai->chat([new Message('user', 'Hello')])->getUsage();

check('prompt tokens are the full input (2006)', $usage->getPromptTokens() === 2006);
check('cached tokens read from prompt_tokens_details (1920)', $usage->getCachedTokens() === 1920);
check('no cache-write count reported (0)', $usage->getCacheWriteTokens() === 0);

// ─── 2. Anthropic ────────────────────────────────────────────────────────────
echo "\n2. Anthropic cache counters are added to the prompt total\n";
$http = new FakeHttpClient();
$http->addResponse(new HttpResponse(200, [], json_encode([
    'model' => 'claude-sonnet-4-20250514',
    'content' => [['type' => 'text', 'text' => 'Hi']],
    'stop_reason' => 'end_turn',
    'usage' => [
        'input_tokens' => 50,
        'cache_read_input_tokens' => 2000,
        'cache_creation_input_tokens' => 300,
        'output_tokens' => 80,
    ],
])));
$anthropic = new AnthropicClient(new AnthropicClientConfig(apiKey: 'test-key', model: 'claude-sonnet-4-20250514'));
$anthropic->setHttpClient($http);
$usage = $anthropic->chat([new Message('user', 'Hello')])->getUsage();

check('prompt = 50 + 2000 + 300 = 2350', $usage->getPromptTokens() === 2350);
check('cached tokens = cache_read_input_tokens', $usage->getCachedTokens() === 2000);
check('cache-write tokens = cache_creation_input_tokens', $usage->getCacheWriteTokens() === 300);

// ─── 3. Pricing ──────────────────────────────────────────────────────────────
echo "\n3. Cost uses the cached input price\n";
$pricing = new PricingConfig([
    'gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'cached_input' => 1.25],
]);

$http = new FakeHttpClient();
$http->addResponse(new HttpResponse(200, [], json_encode([
    'model' => 'gpt-4o',
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']],
    'usage' => [
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 0,
        'prompt_tokens_details' => ['cached_tokens' => 800_000],
    ],
])));
$openai->setHttpClient($http);
$openai->setPricing($pricing);
$cost = $openai->chat([new Message('user', 'Hello')])->getCost();

// 200k uncached at 2.50 + 800k cached at 1.25 = 0.50 + 1.00
check('input cost = 1.50 (not 2.50)', abs($cost->getInputCost() - 1.50) < 1e-9);
check('cached part of the input cost = 1.00', abs($cost->getCachedInputCost() - 1.00) < 1e-9);

$noCachedPrice = new PricingConfig(['gpt-4o' => ['input' => 2.50, 'output' => 10.00]]);
$http = new FakeHttpClient();
$http->addResponse(new HttpResponse(200, [], json_encode([
    'model' => 'gpt-4o',
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']],
    'usage' => [
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 0,
        'prompt_tokens_details' => ['cached_tokens' => 800_000],
    ],
])));
$openai->setHttpClient($http);
$openai->setPricing($noCachedPrice);
$cost = $openai->chat([new Message('user', 'Hello')])->getCost();
check('no cached price configured: cost unchanged (2.50)', abs($cost->getInputCost() - 2.50) < 1e-9);

// ─── 4. Clamping and old payloads ────────────────────────────────────────────
echo "\n4. Clamping and serialization\n";
$usage = new Usage(100, 5, 70, 50);
check('cached + writes are clamped to the prompt total', $usage->getCachedTokens() + $usage->getCacheWriteTokens() === 100);
check('cached tokens are kept first (70), writes reduced (30)', $usage->getCachedTokens() === 70 && $usage->getCacheWriteTokens() === 30);

$old = Usage::fromArray(['prompt_tokens' => 10, 'completion_tokens' => 5]);
check('payload without cache keys loads with zeros', $old->getCachedTokens() === 0 && $old->getCacheWriteTokens() === 0);
check('toArray() always includes the cache keys', array_key_exists('cached_tokens', $old->toArray()) && array_key_exists('cache_write_tokens', $old->toArray()));

echo "\n".($failures === 0 ? "All checks passed.\n" : "{$failures} check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
