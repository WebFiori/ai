<?php

/**
 * Live Test 15: GoogleRagProvider — Vertex AI RAG corpus
 *
 * Usage:
 *   source keys/env.sh && php live/15-google-rag-corpus.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Rag\GoogleRagConfig;
use WebFiori\Ai\Rag\GoogleRagProvider;

section('GoogleRagProvider — Vertex AI RAG Corpus');

$config = new GoogleRagConfig(
    projectId: GCP_PROJECT,
    location: GCP_LOCATION,
    corpusId: '4275719245444153344',
    credentials: KEY_PATH,
);

$rag = new GoogleRagProvider($config);

// ─── 1. Basic retrieve ────────────────────────────────────────────────────────
run('Retrieve from RAG corpus', function () use ($rag)
{
    $results = $rag->retrieve('What is WebFiori?', 3);

    echo "    → Results: ".count($results)."\n";

    foreach ($results as $i => $result) {
        echo "    → [$i] Score: ".round($result->getScore(), 4)
            ." | Text: ".substr($result->getText(), 0, 80)."...\n";
    }

    assert(is_array($results), 'Expected array');
});

// ─── 2. Retrieve with different query ─────────────────────────────────────────
run('Retrieve with routing query', function () use ($rag)
{
    $results = $rag->retrieve('routing in PHP frameworks', 5);

    echo "    → Results: ".count($results)."\n";

    if (!empty($results)) {
        echo "    → Top: ".substr($results[0]->getText(), 0, 100)."...\n";
        echo "    → Score: ".round($results[0]->getScore(), 4)."\n";
    }

    assert(is_array($results), 'Expected array');
});

// ─── 3. Retrieve with topK = 1 ───────────────────────────────────────────────
run('Retrieve with topK=1', function () use ($rag)
{
    $results = $rag->retrieve('database', 1);

    echo "    → Results: ".count($results)."\n";
    assert(count($results) <= 1, 'Expected at most 1 result');

    if (!empty($results)) {
        echo "    → Text: ".substr($results[0]->getText(), 0, 80)."...\n";
    }
});

// ─── 4. Ingest throws UnsupportedFeatureException ─────────────────────────────
run('Ingest throws UnsupportedFeatureException', function () use ($rag)
{
    try {
        $rag->ingest('Some test content');
        assert(false, 'Expected exception');
    } catch (WebFiori\Ai\Exception\UnsupportedFeatureException $e) {
        echo "    → Caught expected: ".substr($e->getMessage(), 0, 80)."...\n";
    }
});

// ─── 5. Use with AgentMemory (recall only) ────────────────────────────────────
run('AgentMemory recall from RAG corpus', function () use ($rag)
{
    $memory = new WebFiori\Ai\Tool\AgentMemory($rag, minScore: 0.5);
    $results = $memory->recall('WebFiori framework features');

    echo "    → Recalled: ".count($results)." memories\n";

    foreach ($results as $result) {
        echo "    → ".substr($result->getText(), 0, 80)."...\n";
    }
});

echo "\n";
