<?php

declare(strict_types=1);

/**
 * Example 27: RAG Providers — VertexAISearchProvider
 *
 * Run: php examples/27-rag-providers/vertex_ai.php
 *
 * Demonstrates:
 * 1. Configuring VertexAISearchProvider with explicit credentials
 * 2. Using Application Default Credentials (ADC) — null credentials
 * 3. Retrieving documents from a Vertex AI Search data store
 * 4. Ingesting a document into the data store
 * 5. Using the provider with AgentMemory
 *
 * Prerequisites:
 * - A Vertex AI Search data store created in Google Cloud Console
 * - Service account with "Discovery Engine Editor" role, or ADC configured
 * - For ADC: run `gcloud auth application-default login` or set
 *   GOOGLE_APPLICATION_CREDENTIALS env var
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Rag\VertexAISearchConfig;
use WebFiori\Ai\Rag\VertexAISearchProvider;
use WebFiori\Ai\Tool\AgentMemory;

// ─── Option A: Explicit service account credentials ──────────────────────────

echo "═══ VertexAISearchProvider Example ═══\n\n";
echo "─── With Explicit Credentials ───\n\n";

$configExplicit = new VertexAISearchConfig(
    projectId: 'my-gcp-project',
    location: 'global',            // or 'us', 'eu', 'us-central1', etc.
    dataStoreId: 'my-datastore-id',
    credentials: '/path/to/service-account.json',
    collectionId: 'default_collection', // default value, usually unchanged
);

$providerExplicit = new VertexAISearchProvider($configExplicit);

echo "Created provider with explicit service account credentials.\n";
echo "  Project:    {$configExplicit->projectId}\n";
echo "  Location:   {$configExplicit->location}\n";
echo "  Data Store: {$configExplicit->dataStoreId}\n";

// ─── Option B: Application Default Credentials (ADC) ─────────────────────────

echo "\n─── With Application Default Credentials (ADC) ───\n\n";

$configAdc = new VertexAISearchConfig(
    projectId: 'my-gcp-project',
    location: 'global',
    dataStoreId: 'my-datastore-id',
    credentials: null, // Uses ADC: GOOGLE_APPLICATION_CREDENTIALS or gcloud auth
);

$providerAdc = new VertexAISearchProvider($configAdc);

echo "Created provider with ADC (credentials: null).\n";
echo "  This uses GOOGLE_APPLICATION_CREDENTIALS env var or `gcloud auth`.\n";

// ─── Retrieve documents ──────────────────────────────────────────────────────

echo "\n─── Retrieving Documents ───\n\n";

// Basic retrieval
$results = $providerExplicit->retrieve('What is PHP?', topK: 5);

echo "Query: 'What is PHP?' (top 5 results)\n\n";

foreach ($results as $i => $result) {
    echo sprintf(
        "  [%d] (score: %.4f) %s\n",
        $i + 1,
        $result->getScore(),
        substr($result->getText(), 0, 100),
    );

    if ($result->getSource() !== null) {
        echo "      Source: ".$result->getSource()."\n";
    }

    $metadata = $result->getMetadata();

    if (isset($metadata['link'])) {
        echo "      Link: ".$metadata['link']."\n";
    }
}

// Retrieval with filter
echo "\nQuery with filter:\n";

$filteredResults = $providerExplicit->retrieve(
    'PHP features',
    topK: 3,
    options: [
        'filter' => 'category: ANY("language-features")',
    ],
);

foreach ($filteredResults as $result) {
    echo "  - ".$result->getText()."\n";
}

// ─── Ingest a document ───────────────────────────────────────────────────────

echo "\n─── Ingesting a Document ───\n\n";

$documentId = $providerExplicit->ingest(
    'PHP 8.4 introduces property hooks which allow defining get/set logic inline with property declarations.',
    [
        'category' => 'language-features',
        'version' => '8.4',
        'author' => 'docs-team',
    ],
);

echo "Ingested document with ID: {$documentId}\n";
echo "  The document is now searchable in the data store.\n";

// ─── Delete a document ───────────────────────────────────────────────────────

echo "\n─── Deleting a Document ───\n\n";

$providerExplicit->delete($documentId);
echo "Deleted document: {$documentId}\n";

// ─── Use with AgentMemory ────────────────────────────────────────────────────

echo "\n─── Using with AgentMemory ───\n\n";

// AgentMemory can use VertexAISearchProvider as its backing store.
// This gives the agent long-term memory backed by a managed search service.

$memory = new AgentMemory(
    ragProvider: $providerExplicit,
    minScore: 0.7,
    topK: 5,
);

// Remember facts — stored in Vertex AI Search
$memoryId = $memory->remember(
    'The user prefers PHP 8.4 with strict typing enabled.',
    ['context' => 'user-preference', 'session' => 'abc123'],
);
echo "Stored memory: {$memoryId}\n";

// Recall relevant memories
$memories = $memory->recall('What PHP version does the user prefer?');

echo "Recalled ".count($memories)." relevant memories:\n";

foreach ($memories as $mem) {
    echo sprintf("  (score: %.4f) %s\n", $mem->getScore(), $mem->getText());
}

// Forget (delete) a memory
$forgotten = $memory->forget($memoryId);
echo "\nForgot memory {$memoryId}: ".($forgotten ? 'yes' : 'no')."\n";

echo "\nDone.\n";
