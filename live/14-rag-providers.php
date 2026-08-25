<?php

/**
 * Live Test 14: RagProviderInterface — unified RAG providers
 *
 * Usage:
 *   source keys/env.sh && php live/14-rag-providers.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Message;
use WebFiori\Ai\Rag\LocalRagProvider;
use WebFiori\Ai\Rag\RetrievalTool;
use WebFiori\Ai\Tool\AgentMemory;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;

section('LocalRagProvider — Unified RAG Providers');

// ─── 1. Ingest and retrieve ──────────────────────────────────────────────────
run('LocalRagProvider — ingest and retrieve', function ()
{
    $store = new InMemoryVectorStore();
    $provider = new LocalRagProvider(
        store: $store,
        embedder: gemini2Client(),
    );

    // Ingest 3 facts about different topics
    $id1 = $provider->ingest('PHP was created by Rasmus Lerdorf in 1995 and is a server-side scripting language.');
    $id2 = $provider->ingest('The Great Wall of China is over 13,000 miles long and was built over many centuries.');
    $id3 = $provider->ingest('Jupiter is the largest planet in our solar system with a mass of 1.898 × 10^27 kg.');

    echo "    → Ingested 3 facts: {$id1}, {$id2}, {$id3}\n";

    // Retrieve with a related query
    $results = $provider->retrieve('What programming language did Rasmus Lerdorf create?', topK: 3);

    assert(count($results) > 0, 'Should return at least one result');

    $topResult = $results[0];
    echo "    → Query: 'What programming language did Rasmus Lerdorf create?'\n";
    echo "    → Top result (score={$topResult->getScore()}): ".substr($topResult->getText(), 0, 80)."\n";

    assert(
        stripos($topResult->getText(), 'PHP') !== false || stripos($topResult->getText(), 'Rasmus') !== false,
        'Top result should mention PHP or Rasmus, got: '.$topResult->getText()
    );
});

// ─── 2. Delete ───────────────────────────────────────────────────────────────
run('LocalRagProvider — delete', function ()
{
    $store = new InMemoryVectorStore();
    $provider = new LocalRagProvider(
        store: $store,
        embedder: gemini2Client(),
    );

    // Ingest a fact
    $id = $provider->ingest('The speed of light is approximately 299,792,458 meters per second.');
    echo "    → Ingested fact with ID: {$id}\n";

    // Verify it can be retrieved
    $results = $provider->retrieve('speed of light', topK: 1);
    assert(count($results) > 0, 'Should find the fact before deletion');
    echo "    → Before delete: found ".count($results)." result(s)\n";

    // Delete it
    $provider->delete($id);
    echo "    → Deleted document {$id}\n";

    // Verify retrieve returns empty
    $results = $provider->retrieve('speed of light', topK: 1);
    assert(count($results) === 0, 'Should return empty after deletion, got '.count($results).' results');
    echo "    → After delete: found ".count($results)." result(s) ✓\n";
});

// ─── 3. minScore filtering ───────────────────────────────────────────────────
run('LocalRagProvider — minScore filtering', function ()
{
    $store = new InMemoryVectorStore();
    $provider = new LocalRagProvider(
        store: $store,
        embedder: gemini2Client(),
        minScore: 0.9,
    );

    // Ingest facts
    $provider->ingest('Bananas are a good source of potassium and fiber.');
    $provider->ingest('Python is a popular programming language created by Guido van Rossum.');
    $provider->ingest('Mount Everest is the tallest mountain on Earth at 8,849 meters.');

    echo "    → Ingested 3 facts with minScore=0.9\n";

    // Query something loosely related — high threshold should filter most results
    $results = $provider->retrieve('What are some healthy fruits to eat?', topK: 3);

    echo "    → Query: 'What are some healthy fruits to eat?'\n";
    echo "    → Results with minScore=0.9: ".count($results)." result(s)\n";

    foreach ($results as $r) {
        echo "      - score={$r->getScore()}: ".substr($r->getText(), 0, 60)."\n";
    }

    // With a very high threshold, loosely related queries should return fewer results
    // (compared to without threshold). We verify the filtering is active.
    // Now lower the threshold and verify more results come through
    $provider->setMinScore(0.0);
    $resultsNoFilter = $provider->retrieve('What are some healthy fruits to eat?', topK: 3);
    echo "    → Results with minScore=0.0: ".count($resultsNoFilter)." result(s)\n";

    assert(
        count($resultsNoFilter) >= count($results),
        'Lowering minScore should return same or more results'
    );
});

// ─── 4. RetrievalTool with LocalRagProvider ──────────────────────────────────
run('RetrievalTool with LocalRagProvider', function ()
{
    $store = new InMemoryVectorStore();
    $ragProvider = new LocalRagProvider(
        store: $store,
        embedder: gemini2Client(),
    );

    // Ingest knowledge
    $ragProvider->ingest('WebFiori Framework is a PHP web framework focused on simplicity and performance.');
    $ragProvider->ingest('Composer is the dependency manager for PHP projects.');

    // Create RetrievalTool
    $tool = new RetrievalTool($ragProvider, name: 'search_docs');

    echo "    → Tool name: {$tool->getName()}\n";
    echo "    → Tool description: ".substr($tool->getDescription(), 0, 60)."...\n";

    // Execute with a query
    $jsonResponse = $tool->execute(['query' => 'What is WebFiori?']);
    $decoded = json_decode($jsonResponse, true);

    echo "    → Response JSON keys: ".implode(', ', array_keys($decoded))."\n";
    echo "    → Total found: ".($decoded['total_found'] ?? 0)."\n";

    assert(isset($decoded['results']), 'Response should contain results key');
    assert(isset($decoded['total_found']), 'Response should contain total_found key');
    assert($decoded['total_found'] > 0, 'Should find at least one result');

    $firstResult = $decoded['results'][0];
    echo "    → First result: ".substr($firstResult['text'] ?? '', 0, 80)."\n";
    assert(
        stripos($firstResult['text'] ?? '', 'WebFiori') !== false,
        'First result should mention WebFiori'
    );
});

// ─── 5. AgentMemory with LocalRagProvider ────────────────────────────────────
run('AgentMemory with LocalRagProvider', function ()
{
    $store = new InMemoryVectorStore();
    $ragProvider = new LocalRagProvider(
        store: $store,
        embedder: gemini2Client(),
    );

    $memory = new AgentMemory($ragProvider, minScore: 0.5);

    // Remember a fact
    $id = $memory->remember('The user prefers dark mode for all applications.');
    echo "    → Remembered fact with ID: {$id}\n";

    // Recall it
    $results = $memory->recall('What theme does the user prefer?');
    assert(count($results) > 0, 'Should recall the remembered fact');
    echo "    → Recall query: 'What theme does the user prefer?'\n";
    echo "    → Recalled ".count($results)." result(s): ".substr($results[0]->getText(), 0, 60)."\n";

    assert(
        stripos($results[0]->getText(), 'dark mode') !== false,
        'Recalled fact should mention dark mode'
    );

    // Forget it
    $forgotten = $memory->forget($id);
    assert($forgotten === true, 'Forget should return true');
    echo "    → Forgot fact {$id}\n";

    // Verify recall returns empty after forget
    $results = $memory->recall('What theme does the user prefer?');
    assert(count($results) === 0, 'Should return empty after forget, got '.count($results));
    echo "    → After forget: recall returned ".count($results)." result(s) ✓\n";
});

// ─── 6. AgentTool with RAG-backed memory ─────────────────────────────────────
run('AgentTool with RAG-backed memory', function ()
{
    $store = new InMemoryVectorStore();
    $ragProvider = new LocalRagProvider(
        store: $store,
        embedder: gemini2Client(),
    );

    $memory = new AgentMemory($ragProvider, minScore: 0.5);

    // Pre-remember a fact
    $memory->remember('The company headquarters is located in Amman, Jordan and was founded in 2018.');
    echo "    → Pre-remembered: company HQ is in Amman, Jordan, founded 2018\n";

    $profile = new AgentProfile(
        identity: 'You are a company knowledge assistant. Answer questions using your memory.',
        skills: ['company information', 'organizational knowledge'],
    );

    $agent = new AgentTool(
        name: 'company_assistant',
        description: 'A company knowledge assistant that knows organizational facts.',
        provider: gemini2Client(),
        profile: $profile,
        memory: $memory,
    );

    // Send a task related to the memorized fact
    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [new Message('user', 'Ask the company assistant: Where is the company headquarters located?')],
        [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    $content = $response->getMessage()->getContent();
    echo "    → Agent response: ".substr($content, 0, 150)."\n";

    assert($content !== '', 'Response should not be empty');
    assert(
        stripos($content, 'Amman') !== false || stripos($content, 'Jordan') !== false,
        'Response should reference Amman or Jordan from memorized info, got: '.substr($content, 0, 200)
    );
});

echo "\n";
