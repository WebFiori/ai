# RAG Providers — RagProviderInterface

Unified provider-agnostic interface for retrieval-augmented generation.

## Implementations

| Provider | Backend | retrieve | ingest | delete |
|----------|---------|----------|--------|--------|
| `LocalRagProvider` | VectorStorageInterface + embedder | ✅ | ✅ | ✅ |
| `GoogleRagProvider` | GCP Vertex AI RAG corpus | ✅ | ❌ | ✅ |
| `BedrockKnowledgeBaseProvider` | AWS Bedrock Knowledge Bases | ✅ | ❌ | ❌ |

## Authentication

- **Google (ADC)** — pass `null` as credentials for Application Default Credentials, or provide a service account JSON path
- **AWS (SigV4)** — provide access/secret keys, or leave null for credential chain (env vars → ~/.aws/credentials → instance metadata)

## Quick Start

### Local (development)

```php
use WebFiori\Ai\Embedding\SqliteVectorStore;
use WebFiori\Ai\Rag\LocalRagProvider;

$rag = new LocalRagProvider(
    store: new SqliteVectorStore('/path/to/vectors.db'),
    embedder: $googleClient, // Any ProviderInterface with embed()
);

$id = $rag->ingest('WebFiori uses PSR-7 HTTP messages.');
$results = $rag->retrieve('What HTTP standard does WebFiori use?');
$rag->delete($id);
```

### Google RAG Corpus (production)

```php
use WebFiori\Ai\Rag\GoogleRagConfig;
use WebFiori\Ai\Rag\GoogleRagProvider;

$rag = new GoogleRagProvider(new GoogleRagConfig(
    projectId: 'my-project',
    location: 'us-central1',
    corpusId: '4275719245444153344',
    credentials: null, // ADC
));

$results = $rag->retrieve('WebFiori routing');
```

### AWS Bedrock Knowledge Base

```php
use WebFiori\Ai\Rag\BedrockKbConfig;
use WebFiori\Ai\Rag\BedrockKnowledgeBaseProvider;

$rag = new BedrockKnowledgeBaseProvider(new BedrockKbConfig(
    region: 'us-east-1',
    knowledgeBaseId: 'KB123456',
));

$results = $rag->retrieve('deployment best practices');
```

## Usage with AgentMemory

```php
use WebFiori\Ai\Tool\AgentMemory;

// Same API regardless of backing provider
$memory = new AgentMemory($rag, minScore: 0.7, topK: 5);
$memory->remember('Important fact');
$results = $memory->recall('related query');
```

## Usage with RetrievalTool

```php
use WebFiori\Ai\Rag\RetrievalTool;

$tool = new RetrievalTool($rag, name: 'search_docs');
// Model can now invoke the tool during chat
```

## Examples

- `local.php` — LocalRagProvider with InMemoryVectorStore
- `bedrock.php` — BedrockKnowledgeBaseProvider
- `agent_memory_rag.php` — AgentMemory with provider swap pattern
