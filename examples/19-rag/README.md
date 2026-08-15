# RAG (Retrieval-Augmented Generation) Examples

This directory demonstrates how to build a RAG pipeline using the webfiori/ai library.

## How It Works

RAG is implemented as a **tool the chat model can invoke**, not as a pipeline that wraps `chat()`. The model decides when retrieval is needed:

```
User question
     │
     ▼
Chat model (e.g. gemini-2.5-flash)
     │  decides to call search_knowledge("water withdrawal")
     ▼
RetrievalTool::execute()
     ├── Embedding model embeds the query → float[]
     ├── VectorStore searches by cosine similarity
     └── Returns JSON: {"results": [{"text": "...", "score": 0.89}]}
     │
     ▼
Chat model reads retrieved chunks, formulates final answer
```

The chat model and embedding model are completely independent. The chat model never sees vectors — it only receives the tool result as structured JSON text.

See [ADR-0033](https://github.com/WebFiori/docs/blob/main/adr/0033-ai-rag-as-tool.md) for the full rationale.

## Components

| Component | Purpose |
|-----------|---------|
| `TextChunker` | Splits documents into overlapping chunks |
| `FileVectorStore` / `SqliteVectorStore` | Persists embeddings for retrieval |
| `Retriever` | Embeds queries and searches the store |
| `RetrievalTool` | Exposes retrieval as a tool for chat |

## Examples

### 1. Ingest Documents (`01-ingest-documents.php`)

Shows how to:
- Chunk a document into overlapping pieces
- Generate embeddings for each chunk
- Store vectors with metadata

### 2. Query Knowledge (`02-query-knowledge.php`)

Shows how to:
- Create a retriever
- Search for relevant chunks
- Apply minimum score thresholds

### 3. Chat with RAG Tool (`03-chat-with-rag-tool.php`)

Shows how to:
- Wrap the retriever as a tool
- Let the model decide when to search
- Get answers with source citations

### 4. File Vector Store (`04-file-vector-store.php`)

Shows how to:
- Use file-based persistence
- Handle concurrent access
- Query with metadata filters

### 5. SQLite Vector Store (`05-sqlite-vector-store.php`)

Shows how to:
- Use SQLite for better performance
- Handle larger datasets
- Use WAL mode for concurrency

### 6. Verify (`verify.php`)

Runnable tests using `FakeHttpClient` that verify all RAG functionality without requiring API keys.

## Quick Start

```php
<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Embedding\SqliteVectorStore;
use WebFiori\Ai\Rag\TextChunker;
use WebFiori\Ai\Rag\Retriever;
use WebFiori\Ai\Rag\RetrievalTool;
use WebFiori\Ai\Message;

// Setup
$embedProvider = new OpenAIClient([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'text-embedding-3-small',
]);

$chatProvider = new OpenAIClient([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

$store = new SqliteVectorStore(__DIR__ . '/knowledge.db');
$chunker = new TextChunker(chunkSize: 2000, overlap: 400);
$retriever = new Retriever($embedProvider, $store);

// Ingest a document (do this once)
$document = file_get_contents('your-document.txt');
$chunks = $chunker->chunk($document, ['source' => 'your-document.txt']);

foreach ($chunks as $chunk) {
    $vector = $embedProvider->embed($chunk->getText())->getVector();
    $store->store($chunk->getId(), $vector, $chunk->getAllMetadata());
}

// Add RAG tool to chat
$ragTool = new RetrievalTool($retriever);
$chatProvider->addTool($ragTool, fn($args) => $ragTool->execute($args));

// Chat - model will search knowledge base when needed
$response = $chatProvider->chat([
    new Message('system', 'You are a helpful assistant. Use the search_knowledge tool to find relevant information before answering questions.'),
    new Message('user', 'What does the document say about X?'),
]);

echo $response->getMessage()->getContent();
```

## Vector Store Comparison

| Store | Best For | Vectors | Persistence |
|-------|----------|---------|-------------|
| `InMemoryVectorStore` | Tests, tiny datasets | < 1,000 | None |
| `FileVectorStore` | Small datasets | < 10,000 | JSON files |
| `SqliteVectorStore` | Medium datasets | < 500,000 | SQLite |
| Custom implementation | Large datasets | > 500,000 | pgvector, Pinecone, etc. |

## Chunking Guidelines

| Document Type | Chunk Size | Overlap |
|---------------|------------|---------|
| Technical docs | 1500-2000 | 300-400 |
| Prose/articles | 2000-3000 | 400-600 |
| Code | 500-1000 | 100-200 |
| FAQ/Q&A | 500-1000 | 100-200 |

Smaller chunks = more precise retrieval but may lose context.
Larger chunks = more context but may include irrelevant information.
