<?php

/**
 * Example 11: Sliding Window Strategy
 *
 * Demonstrates automatic context truncation when messages exceed the limit.
 *
 * Run: php examples/11-context-window/sliding.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;


$apiKey = getenv('GOOGLE_API_KEY');
$credentials = __DIR__.'/../../vertex-ai-key.json';

if (!$apiKey && !file_exists($credentials)) {
    echo "Please set GOOGLE_API_KEY or provide vertex-ai-key.json\n";
    exit(1);
}

$provider = new GoogleClient([
    'api' => 'gemini',
    'api_key' => $apiKey ?: null,
    'credentials' => !$apiKey ? $credentials : null,
    'model' => 'gemini-3.5-flash',
]);

// Set up sliding window with a small limit to demonstrate truncation
$provider->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 500,              // Small limit for demo
    reserveForCompletion: 100,
    preserveSystemMessage: true,
));

// Enable logging to see truncation warnings
$provider->setLogCallback(function ($level, $message, $context)
{
    if ($level === 'warning') {
        echo "[WARNING] {$message}\n";

        if (isset($context['removed_messages'])) {
            echo "  Removed: {$context['removed_messages']} messages\n";
            echo "  Removed: {$context['removed_tokens']} tokens\n";
        }
    }
});

echo "=== Sliding Window Strategy Demo ===\n\n";
echo "Context limit: 500 tokens (small for demo)\n";
echo "Reserved for completion: 100 tokens\n";
echo "Available for input: 400 tokens\n\n";

// Build a conversation that will exceed the limit
$messages = [
    new Message('system', 'You are a helpful assistant. Keep responses very brief.'),
];

// Add several exchanges that will exceed the limit
$exchanges = [
    ['What is PHP?', 'PHP is a server-side scripting language.'],
    ['What about Python?', 'Python is a general-purpose programming language.'],
    ['And JavaScript?', 'JavaScript is primarily used for web development.'],
    ['What is TypeScript?', 'TypeScript is a typed superset of JavaScript.'],
];

foreach ($exchanges as [$q, $a]) {
    $messages[] = new Message('user', $q);
    $messages[] = new Message('assistant', $a);
}

// Add final question
$messages[] = new Message('user', 'Which one would you recommend for a beginner?');

$totalTokens = $provider->countTokens($messages);
echo "Total messages: ".count($messages)."\n";
echo "Total tokens before truncation: {$totalTokens}\n\n";

echo "Sending request (truncation will occur if needed)...\n\n";

$response = $provider->chat($messages);

echo "\nResponse: ".$response->getMessage()->getContent()."\n";
