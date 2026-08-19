<?php

/**
 * Live RAG test using Google Gemini (Vertex AI credentials)
 *
 * Uses gemini-2.5-flash for chat and text-embedding-004 for embeddings.
 *
 * Run: php examples/19-rag/live-google.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Embedding\SqliteVectorStore;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;

use WebFiori\Ai\Rag\RetrievalTool;
use WebFiori\Ai\Rag\Retriever;
use WebFiori\Ai\Rag\TextChunker;

// -------------------------------------------------------
// Setup: two clients — one for embedding, one for chat
// -------------------------------------------------------
$credentials = __DIR__.'/../../vertex-ai-key.json';

$embedClient = new GoogleClient([
    'api' => 'gemini',
    'credentials' => $credentials,
    'model' => 'gemini-embedding-001',
    'embedding_model' => 'gemini-embedding-001',
]);

$chatClient = new GoogleClient([
    'api' => 'gemini',
    'credentials' => $credentials,
    'model' => 'gemini-2.5-flash',
]);

// -------------------------------------------------------
// Knowledge base — a short document about GRI water standard
// -------------------------------------------------------
$document = <<<TEXT
GRI 303: Water and Effluents (2018)

GRI 303 sets out reporting requirements for an organization's interactions with water.
Organizations must disclose total water withdrawal by source, including surface water,
groundwater, seawater, produced water, and third-party water.

Water discharge must also be reported, including the total volume, destination
(surface water, groundwater, seawater, third-party water), and the quality
of the discharged water (temperature, concentration of pollutants).

Water consumption is calculated as withdrawal minus discharge. Organizations
operating in areas with high water stress must pay particular attention to
their water management practices and report accordingly.

GRI 303 also requires disclosure of any significant impacts on water bodies,
including the size, protected status, and biodiversity value of affected water bodies.
TEXT;

$dbPath = sys_get_temp_dir().'/rag-live-test-'.uniqid().'.db';

// -------------------------------------------------------
// Step 1: Chunk the document
// -------------------------------------------------------
echo "=== Step 1: Chunking document ===\n";

$chunker = new TextChunker(chunkSize: 300, overlap: 60);
$chunks = $chunker->chunk($document, ['source' => 'gri-303.txt']);

echo "Created ".count($chunks)." chunks:\n";

foreach ($chunks as $chunk) {
    echo "  [{$chunk->getIndex()}] offset={$chunk->getCharOffset()} tokens≈{$chunk->getTokenEstimate()} | ".mb_substr($chunk->getText(), 0, 60)."...\n";
}

// -------------------------------------------------------
// Step 2: Embed and store chunks
// -------------------------------------------------------
echo "\n=== Step 2: Embedding and storing chunks ===\n";

$store = new SqliteVectorStore($dbPath);

foreach ($chunks as $chunk) {
    echo "  Embedding chunk {$chunk->getIndex()}... ";
    $vector = $embedClient->embed($chunk->getText())->getVector();
    $store->store($chunk->getId(), $vector, $chunk->getAllMetadata());
    echo "done (dims=".count($vector).")\n";
}

echo "Stored ".$store->count()." vectors in SQLite.\n";

// -------------------------------------------------------
// Step 3: Setup RAG tool
// -------------------------------------------------------
echo "\n=== Step 3: Setting up RAG tool ===\n";

$retriever = new Retriever($embedClient, $store, [
    'embedding_model' => 'gemini-embedding-001',
    'min_score' => 0.5,
]);

$ragTool = new RetrievalTool(
    retriever: $retriever,
    name: 'search_gri_303',
    description: 'Search the GRI 303 Water and Effluents standard for relevant information.',
    defaultTopK: 3,
);

echo "RAG tool registered as 'search_gri_303'.\n";

// -------------------------------------------------------
// Step 4: Ask questions
// -------------------------------------------------------
$questions = [
    'What does GRI 303 require organizations to report about water withdrawal?',
    'How is water consumption calculated under GRI 303?',
];

foreach ($questions as $i => $question) {
    echo "\n=== Question ".($i + 1)." ===\n";
    echo "Q: {$question}\n\n";

    $response = $chatClient->chat(
        messages: [
            new Message('system', 'You are a sustainability reporting expert. Use the search_gri_303 tool to find relevant information before answering.'),
            new Message('user', $question),
        ],
        options: [
            'tools' => [$ragTool],
            'auto_execute_tools' => true,
        ],
    );

    echo "A: ".$response->getMessage()->getContent()."\n";
    echo "Tokens: ".($response->getUsage()?->getTotalTokens() ?? 'n/a')."\n";
}

// -------------------------------------------------------
// Cleanup
// -------------------------------------------------------
unlink($dbPath);

if (file_exists($dbPath.'-wal')) {
    unlink($dbPath.'-wal');
}

if (file_exists($dbPath.'-shm')) {
    unlink($dbPath.'-shm');
}

echo "\n✅ Done.\n";
