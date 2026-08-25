<?php

declare(strict_types=1);

/**
 * Example 26: AgentMemory — AgentTool with Memory and KeywordRememberStrategy
 *
 * Run: php examples/26-agent-memory/agent_with_memory.php
 *
 * Demonstrates:
 * 1. Creating an AgentTool with persistent memory
 * 2. KeywordRememberStrategy auto-detecting corrections
 * 3. The full loop: question → correction → future recall
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\AgentMemory;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\KeywordRememberStrategy;

// ─── Setup ────────────────────────────────────────────────────────────────────

$client = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    apiKey: 'your-api-key',
));

$store = new InMemoryVectorStore();
$memory = new AgentMemory($store, $client, minScore: 0.5);

// ─── Create agent with memory and auto-learn strategy ─────────────────────────

$profile = new AgentProfile(
    identity: 'You are a team assistant that tracks project details and preferences.',
    skills: ['project tracking', 'team coordination', 'meeting notes'],
    instructions: [
        'Use your memory to provide accurate, up-to-date information.',
        'Acknowledge corrections gracefully.',
    ],
);

$strategy = new KeywordRememberStrategy();
// Default patterns detect: "actually", "correction:", "remember that",
// "no, it's", "important:", "note:", "fyi", etc.

$agent = new AgentTool(
    name: 'team_assistant',
    description: 'A team assistant that remembers project details and learns from corrections.',
    provider: $client,
    profile: $profile,
    memory: $memory,
    rememberStrategy: $strategy,
);

$orchestrator = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    apiKey: 'your-api-key',
));

echo "═══ AgentTool with Memory — Auto-Learn Example ═══\n\n";

// ─── Session 1: Initial question (no memory yet) ─────────────────────────────

echo "── Session 1: Initial question ──\n";

$response = $orchestrator->chat(
    [new Message('user', 'Ask the team assistant: What is our sprint length?')],
    [
        ChatOption::TOOLS => [$agent],
        ChatOption::AUTO_EXECUTE_TOOLS => true,
    ]
);

echo "Q: What is our sprint length?\n";
echo "A: ".substr($response->getMessage()->getContent(), 0, 150)."\n\n";

// ─── Session 2: User corrects the agent ──────────────────────────────────────

echo "── Session 2: Correction (triggers auto-learn) ──\n";

$response = $orchestrator->chat(
    [
        new Message('user', 'Tell the team assistant: Actually, our sprint length is 3 weeks. Remember that for next time.'),
    ],
    [
        ChatOption::TOOLS => [$agent],
        ChatOption::AUTO_EXECUTE_TOOLS => true,
    ]
);

echo "User: Actually, our sprint length is 3 weeks. Remember that for next time.\n";
echo "A: ".substr($response->getMessage()->getContent(), 0, 150)."\n\n";

// ─── Verify auto-learn stored the correction ─────────────────────────────────

echo "── Verifying memory ──\n";

$results = $memory->recall('sprint length');
echo "Recall query: 'sprint length'\n";
echo "Results: ".count($results)."\n";

foreach ($results as $r) {
    echo "  → [{$r->getScore()}] {$r->getText()}\n";
}

echo "\n";

// ─── Session 3: Future question uses recalled memory ─────────────────────────

echo "── Session 3: Next session recalls the correction ──\n";

$response = $orchestrator->chat(
    [new Message('user', 'Ask the team assistant: How long is each sprint?')],
    [
        ChatOption::TOOLS => [$agent],
        ChatOption::AUTO_EXECUTE_TOOLS => true,
    ]
);

echo "Q: How long is each sprint?\n";
echo "A: ".substr($response->getMessage()->getContent(), 0, 200)."\n\n";

echo "Done.\n";
