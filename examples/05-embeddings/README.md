# 05 — Embeddings

Generate vector embeddings from text, compute similarity, and perform semantic search with vector storage.

## What It Demonstrates

- Generating embeddings using `embed()`
- Batch embedding multiple texts
- Computing cosine similarity between vectors
- Storing vectors in `InMemoryVectorStore` with metadata
- Querying by semantic similarity
- Filtering query results by metadata

## Files

| File | Description |
|------|-------------|
| `embed.php` | CLI script — embed texts and compute similarity |
| `search.php` | CLI script — semantic search with vector storage |
| `index.php` | Web page — semantic similarity calculator |

## Running

### CLI

```bash
php examples/05-embeddings/embed.php
php examples/05-embeddings/search.php
```

### Web

```bash
php -S localhost:8080 -t examples/05-embeddings
```

Open http://localhost:8080. Enter two texts and see their semantic similarity score.
