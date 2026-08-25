<?php

declare(strict_types=1);

/**
 * Example 26: AgentMemory — Direct Client Integration
 *
 * Run: php examples/26-agent-memory/client_memory.php
 *
 * Demonstrates:
 * 1. Using setMemory() and setRememberStrategy() on a GoogleClient
 * 2. Memory is automatically recalled and injected into conversation
 * 3. New facts are automatically extracted and stored based on strategy
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\AgentMemory;
use WebFiori\Ai\Tool\KeywordRememberStrategy;

// ─── Setup ────────────────────────────────────────────────────────────────────

$client = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    apiKey: 'your-api-key',
));

$store = new InMemoryVectorStore();
$memory = new AgentMemory($store, $client, minScore: 0.5);

// ─── Configure memory on the client ──────────────────────────────────────────

$client->setMemory($memory);
$client->setRememberStrategy(new KeywordRememberStrategy());

echo "═══ AbstractClient with Memory ═══\n\n";

// ─── Pre-load some knowledge ─────────────────────────────────────────────────

echo "── Pre-loading facts into memory ──\n";

$memory->remember('The API rate limit is 1000 requests per minute per user.');
$memory->remember('Authentication uses JWT tokens with a 24-hour expiry.');
$memory->remember('The cache layer is Redis 7.x with a 5-minute default TTL.');

echo "Stored 3 facts about the system.\n\n";

// ─── Chat — memory is recalled automatically ─────────────────────────────────

echo "── Question 1: Memory auto-recall ──\n";

$response = $client->chat([
    new Message('system', 'You are a helpful technical assistant. Use any relevant knowledge from your memory.'),
    new Message('user', 'What is our API rate limit?'),
]);

echo "Q: What is our API rate limit?\n";
echo "A: ".$response->getMessage()->getContent()."\n\n";

// ─── Chat — correction triggers auto-remember ────────────────────────────────

echo "── Question 2: Correction triggers auto-learn ──\n";

$response = $client->chat([
    new Message('system', 'You are a helpful technical assistant.'),
    new Message('user', 'Actually, the rate limit was increased to 5000 requests per minute last week.'),
]);

echo "User: Actually, the rate limit was increased to 5000 requests per minute last week.\n";
echo "A: ".$response->getMessage()->getContent()."\n\n";

// ─── Verify the correction was stored ────────────────────────────────────────

echo "── Verifying learned fact ──\n";

$results = $memory->recall('API rate limit');
echo "Recall 'API rate limit':\n";

foreach ($results as $r) {
    echo "  → [{$r->getScore()}] {$r->getText()}\n";
}

echo "\n";

// ─── Next conversation uses updated knowledge ────────────────────────────────

echo "── Question 3: Uses updated knowledge ──\n";

$response = $client->chat([
    new Message('system', 'You are a helpful technical assistant. Use any relevant knowledge from your memory.'),
    new Message('user', 'Can you remind me what the current rate limit is?'),
]);

echo "Q: Can you remind me what the current rate limit is?\n";
echo "A: ".$response->getMessage()->getContent()."\n\n";

echo "Done.\n";
