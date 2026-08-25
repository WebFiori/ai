# RAG Providers — Unified Retrieval-Augmented Generation

Use `RagProviderInterface` to plug different retrieval backends into your AI workflows. The same interface works with local vector stores, Google Vertex AI Search, and AWS Bedrock Knowledge Bases — swap providers without changing application logic.

## Concepts

**RagProviderInterface** defines a unified contract for RAG operations:

| Method | Description |
|--------|-------------|
| `retrieve(query, topK, options)` | Semantic search for relevant documents |
| `ingest(content, metadata)` | Store new content in the knowledge base |
| `delete(id)` | Remove a document by ID |

**Implementations:**

| Provider | Backend | Ingest | Delete |
|----------|---------|--------|--------|
| `LocalRagProvider` | Local vector store + embeddings | ✅ | ✅ |
| `VertexAISearchProvider` | Google Discovery Engine | ✅ | ✅ |
| `BedrockKnowledgeBaseProvider` | AWS Bedrock Knowledge Bases | ❌ (S3 workflow) | ❌ (S3 workflow) |

**RetrievalTool** wraps any `RagProviderInterface` as a tool that chat models can invoke autonomously during conversation.

**AgentMemory** provides long-term memory (remember/recall/forget) backed by any `RagProviderInterface`.

## Authentication

### Google Cloud (Vertex AI Search)

Uses `GoogleAuth` which supports:
- **Service account JSON** — pass the file path or decoded array to `VertexAISearchConfig::$credentials`
- **Application Default Credentials (ADC)** — set `credentials: null` and configure `GOOGLE_APPLICATION_CREDENTIALS` or use `gcloud auth application-default login`

### AWS (Bedrock Knowledge Bases)

Uses `AwsSigner` (SigV4) with credential resolution via `AwsCredentialChain`:
- **Explicit credentials** — pass `accessKey`/`secretKey` in `BedrockKbConfig`
- **Environment variables** — `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`
- **Shared credentials file** — `~/.aws/credentials`
- **Instance metadata** — IAM roles on EC2/ECS/Lambda

## Quick Start

### Local RAG Provider

```php
use WebFiori\Ai\Embedding\SqliteVectorStore;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Rag\LocalRagProvider;

$store = new SqliteVectorStore('/tmp/vectors.db');
$embedder = new GoogleClient(new GoogleClientConfig(
    apiKey: 'your-api-key',
    model: 'text-embedding-004',
));

$rag = new LocalRagProvider(store: $store, embedder: $embedder, minScore: 0.7);

$id = $rag->ingest('PHP 8.4 introduced property hooks.', ['source' => 'docs']);
$results = $rag->retrieve('What are property hooks?');
$rag->delete($id);
```

### Vertex AI Search

```php
use WebFiori\Ai\Rag\VertexAISearchConfig;
use WebFiori\Ai\Rag\VertexAISearchProvider;

$provider = new VertexAISearchProvider(new VertexAISearchConfig(
    projectId: 'my-project',
    location: 'global',
    dataStoreId: 'my-datastore',
    credentials: '/path/to/service-account.json', // or null for ADC
));

$results = $provider->retrieve('What is PHP?', topK: 5);
```

### Bedrock Knowledge Bases

```php
use WebFiori\Ai\Rag\BedrockKbConfig;
use WebFiori\Ai\Rag\BedrockKnowledgeBaseProvider;

$provider = new BedrockKnowledgeBaseProvider(new BedrockKbConfig(
    region: 'us-east-1',
    knowledgeBaseId: 'KBXXXXXXXX',
    accessKey: 'AKIA...',
    secretKey: 'wJal...',
));

$results = $provider->retrieve('What is PHP?', topK: 5);
// Note: ingest() and delete() throw UnsupportedFeatureException
```

### Using with RetrievalTool

```php
use WebFiori\Ai\Rag\RetrievalTool;

$tool = new RetrievalTool($rag, name: 'search_docs');

$response = $client->chat($messages, [
    'tools' => [$tool],
    'auto_execute_tools' => true,
]);
```

### Using with AgentMemory

```php
use WebFiori\Ai\Tool\AgentMemory;

$memory = new AgentMemory($rag, minScore: 0.75);
$memory->remember('User prefers dark mode.');
$results = $memory->recall('What theme preference?');
```

## Run Examples

```bash
php examples/27-rag-providers/local.php
php examples/27-rag-providers/vertex_ai.php
php examples/27-rag-providers/bedrock.php
php examples/27-rag-providers/agent_memory_rag.php
```
