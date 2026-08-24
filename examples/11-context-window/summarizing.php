<?php

/**
 * Example 11: Context Window — Summarizing Strategy
 *
 * Run: source keys/env.sh && php examples/11-context-window/summarizing.php
 *
 * Demonstrates SummarizingWindowStrategy — old messages are summarized into
 * a system message instead of being discarded. The model retains the gist of
 * earlier conversation even after the context window fills up.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Context\SummarizationPrompt;
use WebFiori\Ai\Context\SummarizingWindowStrategy;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;

$apiKey = getenv('ANTHROPIC_API_KEY') ?: null;

if (!$apiKey) {
    echo "❌ Set ANTHROPIC_API_KEY to run this example.\n";
    exit(1);
}

// ─── Summarizer: cheap/fast model for summarization ───────────────────────────
// This is the model that condenses old messages.
// Use a cheaper model than your main provider to keep costs low.

$summarizer = new AnthropicClient(new AnthropicClientConfig(
    apiKey: $apiKey,
    model: getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001',
));

// ─── Main provider ────────────────────────────────────────────────────────────

$provider = new AnthropicClient(new AnthropicClientConfig(
    apiKey: $apiKey,
    model: getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001',
));

// ─── Configure summarizing strategy ──────────────────────────────────────────

$strategy = new SummarizingWindowStrategy(
    summarizer: $summarizer,
    contextWindow: 8192,      // Your model's context window size
    threshold: 0.70,          // Trigger when 70% full
    keepRecentTurns: 3,       // Keep last 3 user/assistant pairs verbatim
    reserveForCompletion: 1024,
);

$provider->setContextWindowStrategy($strategy);

echo "Strategy: SummarizingWindowStrategy\n";
echo "  Context window: 8192 tokens\n";
echo "  Trigger threshold: 70%\n";
echo "  Keep recent turns: 3\n\n";

// ─── Simulate a long conversation ─────────────────────────────────────────────
// In a real app the strategy activates automatically when needed.
// Here we demonstrate with a short conversation for illustration.

$messages = [
    new Message('system', 'You are a helpful assistant. Keep responses very concise (1 sentence max).'),
];

$topics = [
    'What is PHP?',
    'What is a class in PHP?',
    'What is an interface in PHP?',
    'What is a trait in PHP?',
    'What is a namespace in PHP?',
];

foreach ($topics as $topic) {
    $messages[] = new Message('user', $topic);
    $response = $provider->chat($messages);
    $messages[] = $response->getMessage();

    echo "User: {$topic}\n";
    echo "AI:   ".substr($response->getMessage()->getContent(), 0, 100)."\n\n";
}

// ─── Ask about something from early in conversation ───────────────────────────
// Even if old messages were summarized, the model should recall them

$messages[] = new Message('user', 'What did I ask you first?');
$response = $provider->chat($messages);

echo "User: What did I ask you first?\n";
echo "AI:   ".$response->getMessage()->getContent()."\n\n";

// ─── Custom prompt ────────────────────────────────────────────────────────────

echo "─── Custom summarization prompt ───\n\n";

$customStrategy = new SummarizingWindowStrategy(
    summarizer: $summarizer,
    contextWindow: 8192,
    threshold: 0.70,
    keepRecentTurns: 2,
    prompt: new SummarizationPrompt(
        instruction: 'Summarize this conversation in bullet points, preserving key technical facts.',
        summaryPrefix: 'Prior conversation context: '
    ),
);

$provider2 = new AnthropicClient(new AnthropicClientConfig(
    apiKey: $apiKey,
    model: getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001',
));
$provider2->setContextWindowStrategy($customStrategy);

$response = $provider2->chat([
    new Message('user', 'My name is Ibrahim and I work on WebFiori.'),
    new Message('assistant', 'Nice to meet you, Ibrahim! WebFiori is a PHP framework.'),
    new Message('user', 'What is my name?'),
]);

echo "With custom prompt — AI recalls: ".$response->getMessage()->getContent()."\n";
