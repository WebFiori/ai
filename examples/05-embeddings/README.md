# 05 — Embeddings

Generate vector embeddings from text and compute similarity between texts.

## What It Demonstrates

- Generating embeddings using `embed()`
- Batch embedding multiple texts
- Computing cosine similarity between vectors
- Practical semantic search use case

## Files

| File | Description |
|------|-------------|
| `embed.php` | CLI script — embed texts and compute similarity |
| `index.php` | Web page — semantic similarity calculator |

## Running

### CLI

```bash
php examples/05-embeddings/embed.php
```

### Web

```bash
php -S localhost:8080 -t examples/05-embeddings
```

Open http://localhost:8080. Enter two texts and see their semantic similarity score.
