<?php

declare(strict_types=1);

/**
 * Example 26: AgentMemory — Basic Remember and Recall
 *
 * Run: php examples/26-agent-memory/basic.php
 *
 * Demonstrates:
 * 1. Creating an AgentMemory with InMemoryVectorStore
 * 2. Remembering facts with metadata
 * 3. Recalling facts via semantic similarity
 * 4. Forgetting facts
 * 5. Superseding (replacing) outdated facts
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Rag\LocalRagProvider;
use WebFiori\Ai\Tool\AgentMemory;

// ─── Setup ────────────────────────────────────────────────────────────────────

$embedder = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    apiKey: 'your-api-key',
));

$store = new InMemoryVectorStore();

$memory = new AgentMemory(
    ragProvider: new LocalRagProvider($store, $embedder),
    minScore: 0.5,  // filter out results below 50% similarity
    topK: 5,        // return at most 5 results per recall
);

// ─── Remember facts ──────────────────────────────────────────────────────────

echo "═══ AgentMemory — Basic Example ═══\n\n";

echo "── Remembering facts ──\n";

$id1 = $memory->remember(
    'The production database runs PostgreSQL 16 on AWS RDS.',
    ['source' => 'infra-docs', 'category' => 'database'],
);
echo "Stored: '{$id1}' → PostgreSQL 16 on AWS RDS\n";

$id2 = $memory->remember(
    'The staging environment uses Docker Compose with hot-reload.',
    ['source' => 'dev-guide', 'category' => 'environment'],
);
echo "Stored: '{$id2}' → Docker Compose staging\n";

$id3 = $memory->remember(
    'Deployments happen every Tuesday and Thursday at 2pm UTC.',
    ['source' => 'runbook', 'category' => 'process'],
);
echo "Stored: '{$id3}' → Deployment schedule\n";

echo "\n── Recalling facts ──\n";

// ─── Recall with semantic similarity ─────────────────────────────────────────

$results = $memory->recall('What database engine do we use in production?');
echo "Query: 'What database engine do we use in production?'\n";

foreach ($results as $result) {
    echo "  → [{$result->getScore()}] {$result->getText()}\n";
}

echo "\nQuery: 'When do we deploy?'\n";
$results = $memory->recall('When do we deploy?');

foreach ($results as $result) {
    echo "  → [{$result->getScore()}] {$result->getText()}\n";
}

// ─── Forget a fact ───────────────────────────────────────────────────────────

echo "\n── Forgetting facts ──\n";

$deleted = $memory->forget($id2);
echo "Forgot '{$id2}': ".($deleted ? 'success' : 'not found')."\n";

$results = $memory->recall('Docker staging environment');
echo "Query 'Docker staging environment' after forget: ".count($results)." results\n";

// ─── Supersede (replace) a fact ──────────────────────────────────────────────

echo "\n── Superseding facts ──\n";

echo "Original: Deployments happen every Tuesday and Thursday at 2pm UTC.\n";

$id4 = $memory->remember(
    'Deployments now happen daily at 10am UTC via automated pipeline.',
    ['source' => 'runbook-v2', 'category' => 'process'],
    supersedes: $id3,
);
echo "Superseded '{$id3}' with '{$id4}'\n";

$results = $memory->recall('When do deployments happen?');
echo "Query: 'When do deployments happen?'\n";

foreach ($results as $result) {
    echo "  → [{$result->getScore()}] {$result->getText()}\n";
}

echo "\nDone.\n";
