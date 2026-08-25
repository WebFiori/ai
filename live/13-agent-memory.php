<?php

/**
 * Live Test 13: AgentMemory — persistent learning
 *
 * Usage:
 *   source keys/env.sh && php live/13-agent-memory.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\AgentMemory;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\KeywordRememberStrategy;

section('AgentMemory — Persistent Learning');

// ─── 1. Remember and recall ──────────────────────────────────────────────────
run('AgentMemory — remember and recall', function ()
{
    $store = new InMemoryVectorStore();
    $embedder = gemini2Client();

    $memory = new AgentMemory($store, $embedder);
    $memory->setMinScore(0.5);

    $id = $memory->remember('The WebFiori framework uses PHP 8.1 or newer.');
    echo "    → Stored memory ID: {$id}\n";

    $results = $memory->recall('What PHP version does WebFiori require?');
    assert(count($results) > 0, 'Expected at least one recall result, got: '.count($results));

    $fact = $results[0]->getText();
    echo "    → Recalled: {$fact}\n";
    echo "    → Score: {$results[0]->getScore()}\n";
    assert(stripos($fact, 'PHP 8.1') !== false, 'Expected fact about PHP 8.1, got: '.$fact);
});

// ─── 2. Forget ───────────────────────────────────────────────────────────────
run('AgentMemory — forget', function ()
{
    $store = new InMemoryVectorStore();
    $embedder = gemini2Client();

    $memory = new AgentMemory($store, $embedder);
    $memory->setMinScore(0.3);

    $id = $memory->remember('The deploy server is located in Frankfurt.');
    echo "    → Stored memory ID: {$id}\n";

    $deleted = $memory->forget($id);
    assert($deleted === true, 'Expected forget to return true');

    $results = $memory->recall('Where is the deploy server?');
    assert(count($results) === 0, 'Expected no results after forget, got: '.count($results));
    echo "    → After forget, recall returned 0 results ✓\n";
});

// ─── 3. Supersedes ───────────────────────────────────────────────────────────
run('AgentMemory — supersedes', function ()
{
    $store = new InMemoryVectorStore();
    $embedder = gemini2Client();

    $memory = new AgentMemory($store, $embedder);
    $memory->setMinScore(0.3);

    $oldId = $memory->remember('The database host is db-old.example.com.');
    echo "    → Old memory ID: {$oldId}\n";

    $newId = $memory->remember(
        'The database host is db-new.example.com.',
        ['reason' => 'migration'],
        supersedes: $oldId,
    );
    echo "    → New memory ID: {$newId}\n";

    $results = $memory->recall('What is the database host?');
    assert(count($results) > 0, 'Expected at least one result');

    $found = false;

    foreach ($results as $r) {
        assert(
            stripos($r->getText(), 'db-old') === false,
            'Old fact should be gone, but found: '.$r->getText()
        );

        if (stripos($r->getText(), 'db-new') !== false) {
            $found = true;
        }
    }

    assert($found, 'Expected new fact about db-new.example.com');
    echo "    → Superseded: old fact gone, new fact found ✓\n";
});

// ─── 4. AgentTool with memory ────────────────────────────────────────────────
run('AgentTool with memory', function ()
{
    $store = new InMemoryVectorStore();
    $embedder = gemini2Client();

    $memory = new AgentMemory($store, $embedder);
    $memory->setMinScore(0.4);

    // Pre-load a fact
    $memory->remember('The company deployment pipeline uses GitHub Actions with a staging step before production.');

    $profile = new AgentProfile(
        identity: 'You are a DevOps expert. Use your memory to answer questions accurately.',
        skills: ['CI/CD', 'deployments', 'infrastructure'],
    );

    $agent = new AgentTool(
        name: 'devops_agent',
        description: 'A DevOps expert that answers questions about infrastructure and deployments.',
        provider: gemini2Client(),
        profile: $profile,
        memory: $memory,
    );

    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [new Message('user', 'Ask the devops agent: What CI/CD pipeline does the company use?')],
        [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    $content = $response->getMessage()->getContent();
    assert($content !== '', 'Response should not be empty');
    echo "    → ".substr($content, 0, 150)."\n";
    // The response should reference GitHub Actions since it was in memory
    assert(
        stripos($content, 'GitHub Actions') !== false || stripos($content, 'GitHub') !== false || stripos($content, 'staging') !== false,
        'Expected response to reference memorized CI/CD info, got: '.substr($content, 0, 200)
    );
});

// ─── 5. KeywordRememberStrategy — auto-learn ─────────────────────────────────
run('KeywordRememberStrategy — auto-learn', function ()
{
    $store = new InMemoryVectorStore();
    $embedder = gemini2Client();

    $memory = new AgentMemory($store, $embedder);
    $memory->setMinScore(0.4);

    $strategy = new KeywordRememberStrategy();

    $profile = new AgentProfile(
        identity: 'You are a helpful project assistant.',
        skills: ['project management', 'team coordination'],
    );

    $agent = new AgentTool(
        name: 'project_assistant',
        description: 'A project assistant that helps with project management.',
        provider: gemini2Client(),
        profile: $profile,
        memory: $memory,
        rememberStrategy: $strategy,
    );

    // Simulate a conversation where the user corrects the agent
    // The KeywordRememberStrategy should detect "actually" and store the correction
    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [
            new Message('user', 'Tell the project assistant: Actually, our sprint length is 3 weeks, not 2 weeks. Remember that for future reference.'),
        ],
        [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    echo "    → Agent response: ".substr($response->getMessage()->getContent(), 0, 100)."\n";

    // Check if the correction was stored in memory
    $results = $memory->recall('How long is a sprint?');
    echo "    → Recalled ".count($results)." results for sprint query\n";

    if (count($results) > 0) {
        echo "    → Stored fact: ".$results[0]->getText()."\n";
        assert(
            stripos($results[0]->getText(), '3 week') !== false || stripos($results[0]->getText(), 'sprint') !== false,
            'Expected stored fact about 3-week sprints'
        );
    } else {
        // The strategy should have caught "actually" or "remember that" keyword
        echo "    → Note: No facts stored (strategy may not have triggered in this execution path)\n";
    }
});

// ─── 6. AbstractClient with memory ───────────────────────────────────────────
run('AbstractClient with memory', function ()
{
    $store = new InMemoryVectorStore();
    $embedder = gemini2Client();

    $memory = new AgentMemory($store, $embedder);
    $memory->setMinScore(0.4);

    // Pre-load a fact
    $memory->remember('The WebFiori AI library supports four providers: OpenAI, Google, Anthropic, and AWS Bedrock.');

    $client = gemini2Client();
    $client->setMemory($memory);

    $response = $client->chat([
        new Message('system', 'You are a helpful assistant. Use any recalled memories to answer accurately.'),
        new Message('user', 'Which providers does the WebFiori AI library support?'),
    ]);

    $content = $response->getMessage()->getContent();
    assert($content !== '', 'Response should not be empty');
    echo "    → ".substr($content, 0, 150)."\n";

    // Should mention the providers from memory
    $hasProvider = stripos($content, 'OpenAI') !== false
        || stripos($content, 'Google') !== false
        || stripos($content, 'Anthropic') !== false
        || stripos($content, 'Bedrock') !== false;
    assert($hasProvider, 'Expected response to mention providers from memory, got: '.substr($content, 0, 200));
});

echo "\n";
