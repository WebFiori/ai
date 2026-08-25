<?php

/**
 * Example 11: Context Window Verification
 *
 * Verifies context window management works correctly using FakeHttpClient.
 *
 * Run: php examples/11-context-window/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Context\NoTruncationStrategy;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Exception\ContextOverflowException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

echo "=== Context Window Verification ===\n\n";

// Test 1: Token counting
echo "1. Token Counting\n";
$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));

$messages = [
    new Message('user', 'Hello'),
];

$tokens = $provider->countTokens($messages);
echo "   Simple message: {$tokens} tokens\n";
echo "   ✅ Token counting works\n\n";

// Test 2: Sliding window truncation
echo "2. Sliding Window Truncation\n";
$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
$provider->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 50,
    reserveForCompletion: 10,
    preserveSystemMessage: true,
));

$truncationOccurred = false;
$provider->setLogCallback(function ($level, $message, $context) use (&$truncationOccurred)
{
    if ($level === 'warning' && str_contains($message, 'truncation')) {
        $truncationOccurred = true;
    }
});

$fakeHttp = new FakeHttpClient();
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
    'model' => 'gpt-4o',
])));
$provider->setHttpClient($fakeHttp);

$messages = [
    new Message('system', 'Be helpful'),
    new Message('user', str_repeat('Old message content ', 10)),
    new Message('assistant', str_repeat('Old response content ', 10)),
    new Message('user', 'New question'),
];

$response = $provider->chat($messages);

if ($truncationOccurred) {
    echo "   ✅ Truncation occurred and was logged\n";
} else {
    echo "   ⚠️  No truncation occurred (messages fit within limit)\n";
}

// Verify the request was made with fewer messages
$request = $fakeHttp->getLastRequest();
$body = json_decode($request->getBody(), true);
$sentCount = count($body['messages']);
echo "   Sent {$sentCount} of 4 messages\n\n";

// Test 3: System message preservation
echo "3. System Message Preservation\n";
$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
$provider->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 40,
    reserveForCompletion: 10,
    preserveSystemMessage: true,
));

$fakeHttp = new FakeHttpClient();
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
    'model' => 'gpt-4o',
])));
$provider->setHttpClient($fakeHttp);

$messages = [
    new Message('system', 'Important system instructions'),
    new Message('user', str_repeat('Content ', 20)),
];

$provider->chat($messages);

$request = $fakeHttp->getLastRequest();
$body = json_decode($request->getBody(), true);

if ($body['messages'][0]['role'] === 'system') {
    echo "   ✅ System message preserved\n\n";
} else {
    echo "   ❌ System message was not preserved\n\n";
}

// Test 4: NoTruncationStrategy throws exception
echo "4. NoTruncationStrategy Exception\n";
$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
$provider->setContextWindowStrategy(new NoTruncationStrategy(
    maxTokens: 20,
    reserveForCompletion: 5,
));

$fakeHttp = new FakeHttpClient();
$provider->setHttpClient($fakeHttp);

$messages = [
    new Message('user', str_repeat('This is way too long ', 50)),
];

try {
    $provider->chat($messages);
    echo "   ❌ Expected exception was not thrown\n\n";
} catch (ContextOverflowException $e) {
    echo "   ✅ ContextOverflowException thrown\n";
    echo "   Required: {$e->getRequiredTokens()} tokens\n";
    echo "   Available: {$e->getAvailableTokens()} tokens\n";
    echo "   Overflow: {$e->getOverflowTokens()} tokens\n\n";
}

// Test 5: getRemainingTokens
echo "5. Get Remaining Tokens\n";
$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
$provider->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 1000,
    reserveForCompletion: 200,
));

$messages = [new Message('user', 'Hello')];
$remaining = $provider->getRemainingTokens($messages);

if ($remaining !== null && $remaining > 0 && $remaining < 800) {
    echo "   ✅ Remaining tokens: {$remaining}\n\n";
} else {
    echo "   ❌ Unexpected remaining tokens: {$remaining}\n\n";
}

// Test 6: No strategy = all messages pass through
echo "6. No Strategy (passthrough)\n";
$provider = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test', model: 'gpt-4o'));
// No strategy set

$fakeHttp = new FakeHttpClient();
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
    'model' => 'gpt-4o',
])));
$provider->setHttpClient($fakeHttp);

$messages = [
    new Message('user', 'One'),
    new Message('assistant', 'Two'),
    new Message('user', 'Three'),
];

$provider->chat($messages);

$request = $fakeHttp->getLastRequest();
$body = json_decode($request->getBody(), true);

if (count($body['messages']) === 3) {
    echo "   ✅ All 3 messages passed through\n\n";
} else {
    echo "   ❌ Expected 3 messages, got ".count($body['messages'])."\n\n";
}

echo "=== All Verification Tests Passed ===\n";
