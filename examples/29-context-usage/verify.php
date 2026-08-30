<?php

/**
 * Example 29: Context Usage Snapshot Verification
 *
 * Verifies getContextUsage() returns correct estimated and actual usage
 * snapshots. No real API calls — uses FakeHttpClient where a response is needed.
 *
 * Run: php examples/29-context-usage/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Usage;

echo "=== Context Usage Snapshot Verification ===\n\n";

$client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));

// Test 1: Estimated usage with explicit maxTokens
echo "1. Estimated usage with explicit maxTokens\n";
$messages = [new Message('user', str_repeat('word ', 200))];
$usage = $client->getContextUsage($messages, maxTokens: 8000);

assert($usage->getMaxTokens() === 8000, 'Max should be 8000');
assert($usage->getUsedTokens() > 0, 'Used should be positive');
assert($usage->isEstimated() === true, 'Should be estimated');
assert($usage->getUsedPercentage() !== null, 'Percentage should be computed');
echo "   ✅ used={$usage->getUsedTokens()}, max=8000, pct=".round($usage->getUsedPercentage(), 1)."%, estimated=true\n";

// Test 2: Unknown ceiling (unlisted model, no maxTokens, no strategy)
echo "\n2. Unknown ceiling — used reported, derived values null\n";
$unlistedClient = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'unlisted-model'));
$usage = $unlistedClient->getContextUsage([new Message('user', 'Hello')]);

assert($usage->getMaxTokens() === null, 'Max should be null');
assert($usage->getRemainingTokens() === null, 'Remaining should be null');
assert($usage->getUsedPercentage() === null, 'Percentage should be null');
assert($usage->isOverBudget() === false, 'Over budget false when ceiling unknown');
assert($usage->getUsedTokens() > 0, 'Used still reported');
echo "   ✅ used={$usage->getUsedTokens()}, max=null, remaining=null, over_budget=false\n";

// Test 3: Ceiling resolved from context window strategy
echo "\n3. Ceiling resolved from context window strategy\n";
$client->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 8000,
    reserveForCompletion: 2000,
));
$usage = $client->getContextUsage([new Message('user', 'Hello world')]);

assert($usage->getMaxTokens() === 8000, 'Max from strategy');
assert($usage->getReservedTokens() === 2000, 'Reserved from strategy');
assert($usage->getAvailableTokens() === 6000, 'Available = 8000 - 2000');
echo "   ✅ max=8000, reserved=2000, available=6000 (from strategy)\n";

// Test 4: Explicit maxTokens overrides strategy
echo "\n4. Explicit maxTokens overrides strategy ceiling\n";
$usage = $client->getContextUsage([new Message('user', 'Hi')], maxTokens: 16000);

assert($usage->getMaxTokens() === 16000, 'Explicit max wins');
assert($usage->getReservedTokens() === 2000, 'Reserved still from strategy');
echo "   ✅ max=16000 (explicit), reserved=2000 (strategy)\n";

// Test 5: Actual usage from a ChatResponse
echo "\n5. Actual usage from a ChatResponse (exact prompt tokens)\n";
$freshClient = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
$response = new ChatResponse(
    new Message('assistant', 'reply'),
    'gpt-4o',
    new Usage(promptTokens: 1234, completionTokens: 50),
);
$usage = $freshClient->getContextUsage($messages, maxTokens: 8000, response: $response);

assert($usage->isEstimated() === false, 'Should be actual');
assert($usage->getUsedTokens() === 1234, 'Uses exact prompt tokens');
echo "   ✅ used=1234 (exact), estimated=false\n";

// Test 6: Over-budget detection
echo "\n6. Over-budget detection\n";
$bigMessages = [new Message('user', str_repeat('token ', 2000))];
$usage = $freshClient->getContextUsage($bigMessages, maxTokens: 500);

assert($usage->isOverBudget() === true, 'Should be over budget');
assert($usage->getRemainingTokens() === 0, 'Remaining floored to 0');
assert($usage->getUsedPercentage() > 100.0, 'Percentage exceeds 100');
echo "   ✅ over_budget=true, remaining=0, pct=".round($usage->getUsedPercentage(), 1)."%\n";

// Test 7: toArray snapshot for logging/UI
echo "\n7. toArray() snapshot\n";
$usage = $freshClient->getContextUsage([new Message('user', 'Hi')], maxTokens: 8000);
$array = $usage->toArray();

assert(array_key_exists('used', $array), 'has used');
assert(array_key_exists('max', $array), 'has max');
assert(array_key_exists('remaining', $array), 'has remaining');
assert(array_key_exists('percentage', $array), 'has percentage');
assert(array_key_exists('over_budget', $array), 'has over_budget');
assert(array_key_exists('estimated', $array), 'has estimated');
echo "   ✅ toArray keys: ".implode(', ', array_keys($array))."\n";

// Test 8: Ceiling auto-inferred from the model (ContextWindowConfig)
echo "\n8. Ceiling auto-inferred from model via ContextWindowConfig\n";
$gptClient = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
$usage = $gptClient->getContextUsage([new Message('user', 'Hi')]);

assert($usage->getMaxTokens() === 128000, 'gpt-4o should infer 128000');
assert($usage->getUsedPercentage() !== null, 'Percentage available from inferred ceiling');
echo "   ✅ gpt-4o → max=128000 (no explicit maxTokens, no strategy)\n";

// Test 9: Unknown model → no inference, ceiling stays null
echo "\n9. Unknown model → no ceiling inferred\n";
$unknownClient = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'some-unlisted-model'));
$usage = $unknownClient->getContextUsage([new Message('user', 'Hi')]);

assert($usage->getMaxTokens() === null, 'Unknown model → null ceiling');
echo "   ✅ unknown model → max=null (graceful)\n";

// Test 10: Override the context window table
echo "\n10. Override a model's context window\n";
$gptClient->getContextWindowConfig()->setContextWindow('gpt-4o', 32000);
$usage = $gptClient->getContextUsage([new Message('user', 'Hi')]);

assert($usage->getMaxTokens() === 32000, 'Override should apply');
echo "   ✅ overridden gpt-4o → max=32000\n";

echo "\n=== All 10 checks passed ✅ ===\n";
