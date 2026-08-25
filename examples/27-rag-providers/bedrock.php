<?php

declare(strict_types=1);

/**
 * Example 27: RAG Providers — BedrockKnowledgeBaseProvider
 *
 * Run: php examples/27-rag-providers/bedrock.php
 *
 * Demonstrates:
 * 1. Configuring BedrockKnowledgeBaseProvider with explicit credentials
 * 2. Using auto-resolved credentials (IAM role, env vars, ~/.aws/credentials)
 * 3. Retrieving documents from a Bedrock Knowledge Base
 * 4. Handling UnsupportedFeatureException for ingest/delete
 *
 * Prerequisites:
 * - A Bedrock Knowledge Base created in the AWS console
 * - S3 data source configured and synced with the knowledge base
 * - IAM permissions: bedrock:Retrieve on the knowledge base resource
 *
 * Note on ingestion/deletion:
 * Bedrock Knowledge Bases use S3 as their data source. To add or remove
 * documents, upload/delete files in the configured S3 bucket and then
 * start an ingestion job via the AWS console or `bedrock-agent` API:
 *
 *   aws bedrock-agent start-ingestion-job \
 *       --knowledge-base-id KBXXXXXXXX \
 *       --data-source-id DSXXXXXXXX
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Rag\BedrockKbConfig;
use WebFiori\Ai\Rag\BedrockKnowledgeBaseProvider;

// ─── Option A: Explicit credentials ──────────────────────────────────────────

echo "═══ BedrockKnowledgeBaseProvider Example ═══\n\n";
echo "─── With Explicit Credentials ───\n\n";

$configExplicit = new BedrockKbConfig(
    region: 'us-east-1',
    knowledgeBaseId: 'KBXXXXXXXX',
    accessKey: 'AKIAIOSFODNN7EXAMPLE',
    secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    sessionToken: null, // optional, for temporary credentials (STS AssumeRole)
    modelArn: null,     // optional, for retrieval with foundation model
);

$provider = new BedrockKnowledgeBaseProvider($configExplicit);

echo "Created provider with explicit credentials.\n";
echo "  Region:          {$configExplicit->region}\n";
echo "  Knowledge Base:  {$configExplicit->knowledgeBaseId}\n";

// ─── Option B: Auto-resolved credentials ─────────────────────────────────────

echo "\n─── With Auto-resolved Credentials ───\n\n";

$configAutoResolved = new BedrockKbConfig(
    region: 'us-east-1',
    knowledgeBaseId: 'KBXXXXXXXX',
    // accessKey and secretKey are null — resolved via AwsCredentialChain:
    // 1. AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY environment variables
    // 2. ~/.aws/credentials shared credentials file
    // 3. ECS task role / EC2 instance metadata (IMDS)
);

echo "Config with auto-resolved credentials (IAM role, env vars, etc.).\n";
echo "  Credentials resolved via AwsCredentialChain.\n";

// ─── Retrieve from knowledge base ────────────────────────────────────────────

echo "\n─── Retrieving Documents ───\n\n";

// Basic retrieval
$results = $provider->retrieve('What is PHP?', topK: 5);

echo "Query: 'What is PHP?' (top 5 results)\n\n";

foreach ($results as $i => $result) {
    echo sprintf(
        "  [%d] (score: %.4f) %s\n",
        $i + 1,
        $result->getScore(),
        substr($result->getText(), 0, 100),
    );

    if ($result->getSource() !== null) {
        echo "      S3 Source: ".$result->getSource()."\n";
    }
}

// Retrieval with options
echo "\nRetrieval with options (HYBRID search + metadata filter):\n\n";

$filteredResults = $provider->retrieve(
    'PHP dependency management',
    topK: 3,
    options: [
        'search_type' => 'HYBRID', // 'SEMANTIC' (default) or 'HYBRID'
        'filter' => [
            'andAll' => [
                [
                    'equals' => [
                        'key' => 'category',
                        'value' => 'tools',
                    ],
                ],
            ],
        ],
    ],
);

foreach ($filteredResults as $result) {
    echo "  - (score: {$result->getScore()}) ".$result->getText()."\n";
}

// ─── Ingest/Delete: UnsupportedFeatureException ──────────────────────────────

echo "\n─── Ingest & Delete (Not Supported) ───\n\n";

echo "Bedrock Knowledge Bases manage documents through S3 data sources.\n";
echo "Direct ingestion and deletion are not available via the Retrieve API.\n\n";

// Attempting to ingest throws UnsupportedFeatureException
try {
    $provider->ingest('Some content to store');
} catch (UnsupportedFeatureException $e) {
    echo "ingest() → UnsupportedFeatureException: {$e->getMessage()}\n";
}

// Attempting to delete throws UnsupportedFeatureException
try {
    $provider->delete('some-document-id');
} catch (UnsupportedFeatureException $e) {
    echo "delete() → UnsupportedFeatureException: {$e->getMessage()}\n";
}

echo "\nTo add documents to a Bedrock Knowledge Base:\n";
echo "  1. Upload files to the configured S3 bucket\n";
echo "  2. Start an ingestion job via AWS console or CLI:\n";
echo "     aws bedrock-agent start-ingestion-job \\\n";
echo "         --knowledge-base-id KBXXXXXXXX \\\n";
echo "         --data-source-id DSXXXXXXXX\n";
echo "\nTo remove documents:\n";
echo "  1. Delete the source file from S3\n";
echo "  2. Re-sync the data source (start a new ingestion job)\n";

echo "\nDone.\n";
