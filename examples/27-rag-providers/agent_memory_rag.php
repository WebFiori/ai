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
 * 3. How the same code works with VertexAISearchProvider (provider swap)
 * 4. Superseding (updating) memories
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Rag\LocalRagProvider;
use WebFiori\Ai\Rag\RagProviderInterface;
use WebFiori\Ai\Rag\VertexAISearchConfig;
use WebFiori\Ai\Rag\VertexAISearchProvider;
use WebFiori\Ai\Tool\AgentMemory;

// ─── Helper: demonstrate AgentMemory with any RagProviderInterface ───────────

/**
 * Shows AgentMemory operations with any RAG provider.
 * The same function works regardless of the underlying implementation.
 */
function demonstrateMemory(AgentMemory $memory, string $providerName): void {
    echo "─── AgentMemory with {$providerName} ───\n\n";

    // 1. Remember facts
    echo "Remembering facts...\n";

    $id1 = $memory->remember(
        'The user works with PHP 8.4 and prefers strict typing.',
        ['category' => 'preferences'],
    );
    echo "  Stored: {$id1}\n";

    $id2 = $memory->remember(
        'The user uses Composer for dependency management.',
        ['category' => 'tools'],
    );
    echo "  Stored: {$id2}\n";

    $id3 = $memory->remember(
        'The user prefers PHPUnit over Pest for testing.',
        ['category' => 'preferences'],
    );
    echo "  Stored: {$id3}\n";

    // 2. Recall relevant memories
    echo "\nRecalling: 'What testing framework does the user prefer?'\n";

    $results = $memory->recall('What testing framework does the user prefer?');

    foreach ($results as $result) {
        echo sprintf("  (score: %.4f) %s\n", $result->getScore(), $result->getText());
    }

    // 3. Supersede (update) a memory
    echo "\nSuperseding memory {$id3} with updated preference...\n";

    $id4 = $memory->remember(
        'The user now prefers Pest over PHPUnit for testing.',
        ['category' => 'preferences'],
        supersedes: $id3, // Deletes the old memory, stores the new one
    );
    echo "  New memory stored: {$id4} (old memory {$id3} deleted)\n";

    // 4. Recall again to see the updated fact
    echo "\nRecalling again: 'What testing framework?'\n";

    $results = $memory->recall('What testing framework does the user prefer?');

    foreach ($results as $result) {
        echo sprintf("  (score: %.4f) %s\n", $result->getScore(), $result->getText());
    }

    // 5. Forget a memory
    echo "\nForgetting memory: {$id1}\n";

    $forgotten = $memory->forget($id1);
    echo "  Result: ".($forgotten ? 'forgotten' : 'failed')."\n";

    echo "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// Example 1: AgentMemory with LocalRagProvider
// ═══════════════════════════════════════════════════════════════════════════════

echo "═══ AgentMemory backed by RagProviderInterface ═══\n\n";

$embedder = new GoogleClient(new GoogleClientConfig(
    apiKey: 'your-google-api-key',
    model: 'text-embedding-004',
));

$localRag = new LocalRagProvider(
    store: new InMemoryVectorStore(),
    embedder: $embedder,
    minScore: 0.0, // AgentMemory handles its own score filtering
);

$localMemory = new AgentMemory(
    ragProvider: $localRag,
    minScore: 0.7,  // Only recall memories with score >= 0.7
    topK: 5,
);

demonstrateMemory($localMemory, 'LocalRagProvider');

// ═══════════════════════════════════════════════════════════════════════════════
// Example 2: AgentMemory with VertexAISearchProvider (same interface)
// ═══════════════════════════════════════════════════════════════════════════════

// The same AgentMemory code works with any RagProviderInterface.
// Swap LocalRagProvider for VertexAISearchProvider — zero code changes.

/*
$vertexRag = new VertexAISearchProvider(new VertexAISearchConfig(
    projectId: 'my-gcp-project',
    location: 'global',
    dataStoreId: 'my-memory-datastore',
    credentials: null, // ADC
));

$vertexMemory = new AgentMemory(
    ragProvider: $vertexRag,
    minScore: 0.7,
    topK: 5,
);

// Exactly the same API — backed by managed cloud infrastructure
demonstrateMemory($vertexMemory, 'VertexAISearchProvider');
*/

echo "─── Provider Swap Demonstration ───\n\n";
echo "The code above shows that AgentMemory accepts any RagProviderInterface.\n";
echo "Switch from LocalRagProvider to VertexAISearchProvider by changing one line:\n\n";
echo "  // Local (development/testing)\n";
echo "  \$memory = new AgentMemory(new LocalRagProvider(\$store, \$embedder));\n\n";
echo "  // Cloud (production)\n";
echo "  \$memory = new AgentMemory(new VertexAISearchProvider(\$config));\n\n";
echo "All remember/recall/forget calls remain identical.\n";

// ─── Factory pattern for environment-based provider selection ─────────────────

echo "\n─── Factory Pattern: Environment-based Selection ───\n\n";

/**
 * Creates the appropriate RAG provider based on environment.
 */
function createRagProvider(): RagProviderInterface {
    $env = getenv('APP_ENV') ?: 'development';

    if ($env === 'production') {
        // Production: use managed Vertex AI Search
        return new VertexAISearchProvider(new VertexAISearchConfig(
            projectId: getenv('GCP_PROJECT_ID') ?: 'my-project',
            location: 'global',
            dataStoreId: getenv('VERTEX_DATASTORE_ID') ?: 'my-datastore',
            credentials: null, // ADC on GCE/Cloud Run
        ));
    }

    // Development: use local in-memory store
    $embedder = new GoogleClient(new GoogleClientConfig(
        apiKey: getenv('GOOGLE_API_KEY') ?: 'your-api-key',
        model: 'text-embedding-004',
    ));

    return new LocalRagProvider(
        store: new InMemoryVectorStore(),
        embedder: $embedder,
    );
}

$provider = createRagProvider();
$memory = new AgentMemory($provider, minScore: 0.75);

echo "Provider: ".get_class($provider)."\n";
echo "AgentMemory is ready — same API regardless of backend.\n";

echo "\nDone.\n";
