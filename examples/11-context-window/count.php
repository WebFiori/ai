<?php

/**
 * Example 11: Token Counting
 *
 * Demonstrates how to estimate token counts for messages and tools.
 *
 * Run: php examples/11-context-window/count.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Tool\Tool;

$provider = new OpenAIClient([
    'api_key' => 'not-needed-for-counting',
    'model' => 'gpt-4o',
]);

// Set up strategy to enable getRemainingTokens()
$provider->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 128000,
    reserveForCompletion: 4096,
));

echo "=== Token Counting Demo ===\n\n";

// Simple message
$messages = [
    new Message('user', 'Hello, how are you?'),
];

$tokens = $provider->countTokens($messages);
echo "Simple message: {$tokens} tokens\n";

// Longer conversation
$messages = [
    new Message('system', 'You are a helpful assistant that provides detailed explanations.'),
    new Message('user', 'What is machine learning?'),
    new Message('assistant', 'Machine learning is a subset of artificial intelligence that enables systems to learn and improve from experience without being explicitly programmed.'),
    new Message('user', 'Can you give me an example?'),
];

$tokens = $provider->countTokens($messages);
echo "Conversation (4 messages): {$tokens} tokens\n";

// With tools
$tools = [
    new Tool(
        'get_weather',
        'Gets the current weather for a given location',
        [
            'type' => 'object',
            'properties' => [
                'location' => [
                    'type' => 'string',
                    'description' => 'The city and state, e.g. San Francisco, CA',
                ],
                'unit' => [
                    'type' => 'string',
                    'enum' => ['celsius', 'fahrenheit'],
                ],
            ],
            'required' => ['location'],
        ],
        fn($args) => 'Sunny, 72°F'
    ),
];

$tokensWithTools = $provider->countTokens($messages, $tools);
echo "Conversation + 1 tool: {$tokensWithTools} tokens\n";
echo "Tool overhead: ".($tokensWithTools - $tokens)." tokens\n";

// Check remaining capacity
$remaining = $provider->getRemainingTokens($messages, $tools);
echo "\nRemaining tokens for completion: {$remaining}\n";
echo "(Max: 128,000 - Reserved: 4,096 - Used: {$tokensWithTools})\n";

// Demonstrate with a very long message
echo "\n=== Long Content Demo ===\n";

$longContent = str_repeat('This is a sentence that will be repeated many times to simulate a long document. ', 100);
$longMessages = [
    new Message('system', 'Summarize the following text.'),
    new Message('user', $longContent),
];

$longTokens = $provider->countTokens($longMessages);
echo "Long document: {$longTokens} tokens\n";
echo "Remaining after long doc: ".$provider->getRemainingTokens($longMessages)." tokens\n";
