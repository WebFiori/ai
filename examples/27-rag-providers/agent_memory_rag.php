<?php

declare(strict_types=1);

/**
 * Example 27: RAG Providers — AgentMemory backed by RagProviderInterface
 *
 * Run: php examples/27-rag-providers/agent_memory_rag.php
 *
 * Demonstrates:
 * 1. AgentMemory accepting any RagProviderInterface implementation
 * 2. Using remember/recall/forget with LocalRagProvider
 * 3. How the same code works with GoogleRagProvider (provider swap)
 * 4. Superseding (updating) memories
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Rag\GoogleRagConfig;
use WebFiori\Ai\Rag\GoogleRagProvider;
use WebFiori\Ai\Rag\LocalRagProvider;
use WebFiori\Ai\Rag\RagProviderInterface;
use WebFiori\Ai\Tool\AgentMemory;

// ─── Helper: demonstrate AgentMemory with any RagProviderInterface ───────────

/**
 * Shows AgentMemory operations with any RAG provider.
 */
function demonstrateMemory(AgentMemory $memory, string $label): void {
    echo "═══ AgentMemory with {$label} ═══\n\n";

    // Remember some facts
    $id1 = $memory->remember('WebFiori uses PSR-7 HTTP messages.');
    echo "  Remembered: '{$id1}'\n";

    $id2 = $memory->remember('The framework supports attribute-based routing since v3.');
    echo "  Remembered: '{$id2}'\n";

    // Recall
    $results = $memory->recall('How does routing work?');
    echo "  Recalled ".count($results)." result(s) for 'How does routing work?'\n";

    foreach ($results as $r) {
        echo "    → [".round($r->getScore(), 3)."] ".$r->getText()."\n";
    }

    // Supersede (correct a fact)
    $id3 = $memory->remember(
        'WebFiori v3 uses #[Route] attributes, replacing router()->addRoute().',
        ['source' => 'user_correction'],
        supersedes: $id2,
    );
    echo "  Superseded '{$id2}' with '{$id3}'\n";

    // Forget
    $memory->forget($id1);
    echo "  Forgot '{$id1}'\n\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// Example 1: AgentMemory with LocalRagProvider (local development)
// ═══════════════════════════════════════════════════════════════════════════════

$embedder = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    apiKey: 'your-api-key',
));

$localRag = new LocalRagProvider(
    store: new InMemoryVectorStore(),
    embedder: $embedder,
    minScore: 0.0,
);

$localMemory = new AgentMemory(
    ragProvider: $localRag,
    minScore: 0.7,
    topK: 5,
);

demonstrateMemory($localMemory, 'LocalRagProvider');

// ═══════════════════════════════════════════════════════════════════════════════
// Example 2: AgentMemory with GoogleRagProvider (same interface)
// ═══════════════════════════════════════════════════════════════════════════════

// The same AgentMemory code works with any RagProviderInterface.
// Swap LocalRagProvider for GoogleRagProvider — zero code changes for recall.

/*
$googleRag = new GoogleRagProvider(new GoogleRagConfig(
    projectId: 'my-gcp-project',
    location: 'us-central1',
    corpusId: '4275719245444153344',
    credentials: null, // ADC
));

$cloudMemory = new AgentMemory(
    ragProvider: $googleRag,
    minScore: 0.7,
    topK: 5,
);

// recall() works identically — backed by managed cloud infrastructure
$results = $cloudMemory->recall('WebFiori routing');
// Note: ingest/remember throws UnsupportedFeatureException for GoogleRagProvider
*/

echo "─── Provider Swap Demonstration ───\n\n";
echo "AgentMemory accepts any RagProviderInterface.\n";
echo "Switch from LocalRagProvider to GoogleRagProvider by changing one line:\n\n";
echo "  // Local (development/testing)\n";
echo "  \$memory = new AgentMemory(new LocalRagProvider(\$store, \$embedder));\n\n";
echo "  // Cloud (production — recall only, corpus managed via GCP console)\n";
echo "  \$memory = new AgentMemory(new GoogleRagProvider(\$config));\n\n";
echo "All recall() calls remain identical.\n";

// ─── Factory pattern for environment-based provider selection ─────────────────

echo "\n─── Factory Pattern: Environment-based Selection ───\n\n";

/**
 * Creates the appropriate RAG provider based on environment.
 */
function createRagProvider(): RagProviderInterface {
    $env = getenv('APP_ENV') ?: 'development';

    if ($env === 'production') {
        return new GoogleRagProvider(new GoogleRagConfig(
            projectId: getenv('GCP_PROJECT_ID') ?: 'my-project',
            location: getenv('GCP_LOCATION') ?: 'us-central1',
            corpusId: getenv('RAG_CORPUS_ID') ?: 'my-corpus-id',
            credentials: null, // ADC on GCE/Cloud Run
        ));
    }

    // Development: use local vector store
    $embedder = new GoogleClient(new GoogleClientConfig(
        model: 'gemini-2.5-flash',
        apiKey: getenv('GEMINI_API_KEY') ?: 'dev-key',
    ));

    return new LocalRagProvider(
        store: new InMemoryVectorStore(),
        embedder: $embedder,
    );
}

$provider = createRagProvider();
echo "  Environment: ".(getenv('APP_ENV') ?: 'development')."\n";
echo "  Provider: ".get_class($provider)."\n";
