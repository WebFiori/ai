# 03 — Google (Gemini)

Chat completion using Google's Gemini models. Demonstrates provider swapping — the same application code structure works with a different provider.

## What It Demonstrates

- Configuring the Google provider (credentials, model)
- System messages handled as `systemInstruction`
- Role mapping (assistant → model) handled transparently
- Same `chat()` interface as OpenAI
- Both Gemini API and Gemini Enterprise Agent Platform (previously Vertex AI) endpoint support

## Files

| File | Description |
|------|-------------|
| `chat.php` | CLI script — chat with Gemini |
| `index.php` | Web page — side-by-side comparison with OpenAI |

## Running

### CLI

```bash
php examples/03-google/chat.php
```

By default, uses `vertex-ai-key.json` from the project root with the Gemini API.

### Web

```bash
php -S localhost:8080 -t examples/03-google
```

Open http://localhost:8080 to see the same prompt sent to both OpenAI and Google for comparison.

## Configuration

The example supports two API modes via the `GCP_API` environment variable:

```bash
# Gemini API (default, simpler, free tier)
export GCP_API=gemini

# Gemini Enterprise Agent Platform (previously Vertex AI, requires project_id and location)
export GCP_API=vertex_ai
export GCP_PROJECT_ID=my-project
export GCP_LOCATION=us-central1
```
