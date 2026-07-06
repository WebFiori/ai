# 03 — Vertex AI

Chat completion using Google Cloud Vertex AI with Gemini models. Demonstrates provider swapping — the same application code structure works with a different provider.

## What It Demonstrates

- Configuring the Vertex AI provider (project, location, credentials)
- System messages handled as Vertex AI's `systemInstruction`
- Role mapping (assistant → model) handled transparently
- Same `chat()` interface as OpenAI

## Files

| File | Description |
|------|-------------|
| `chat.php` | CLI script — chat with Gemini |
| `index.php` | Web page — side-by-side comparison with OpenAI |

## Running

### CLI

```bash
export GCP_PROJECT_ID="my-project"
export GCP_LOCATION="us-central1"
export GCP_ACCESS_TOKEN="ya29...."

php examples/03-vertex-ai/chat.php
```

### Web

```bash
php -S localhost:8080 -t examples/03-vertex-ai
```

Open http://localhost:8080 to see the same prompt sent to both OpenAI and Vertex AI for comparison.
