# 03 — Google (Gemini)

Chat completion using Google's Gemini models via two available endpoints.

## Gemini API vs Vertex AI

Both endpoints give access to the same Gemini models. Choose based on your context:

| | Gemini API | Vertex AI |
|--|-----------|-----------|
| **Auth** | API key | Service account, ADC, IAM roles |
| **GCP project required** | No | Yes |
| **Billing required** | No (free tier available) | Yes |
| **Best for** | Individuals, prototyping, startups | Enterprises on GCP |
| **Enterprise features** | No | VPC, data residency, audit logs, SLAs |
| **Config** | `api: 'gemini'` | `api: 'vertex_ai'` |

**Simple rule:** Not on GCP → use Gemini API. Running on GCP with enterprise requirements → use Vertex AI.

## What It Demonstrates

- Configuring the Google provider (credentials, model)
- System messages handled as `systemInstruction`
- Role mapping (assistant → model) handled transparently
- Same `chat()` interface as OpenAI
- Both Gemini API and Vertex AI endpoint support

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

The example supports multiple authentication methods. The library tries them in order:

### 1. Explicit credentials (highest priority)

```bash
export GCP_ACCESS_TOKEN=ya29.your-token
# or
export GCP_CREDENTIALS=/path/to/service-account-key.json
```

### 2. Application Default Credentials (ADC)

ADC is supported for the **Vertex AI endpoint only**. The free Gemini API (`generativelanguage.googleapis.com`) does not accept user OAuth tokens — use an API key or service account for that endpoint.

**gcloud CLI (local development):**
```bash
gcloud auth application-default login
# Credentials saved to:
# Linux/Mac: ~/.config/gcloud/application_default_credentials.json
# Windows:   %APPDATA%\gcloud\application_default_credentials.json
```

Then use Vertex AI with zero credentials config:
```php
$client = new GoogleClient([
    'api'        => 'vertex_ai',
    'project_id' => 'my-project',
    'model'      => 'gemini-2.5-flash',
    // No credentials — ADC used automatically
]);
```

**GCE / GKE / Cloud Run (zero-config):**
No configuration needed on GCP infrastructure — credentials are automatically retrieved from the instance metadata server. Vertex AI endpoint only.

### 3. API key (Gemini API only)

```bash
export GCP_API_KEY=your-gemini-api-key
```

The two API modes via the `GCP_API` environment variable:

```bash
# Gemini API (default, simpler, free tier)
export GCP_API=gemini

# Gemini Enterprise Agent Platform (previously Vertex AI, requires project_id)
export GCP_API=vertex_ai
export GCP_PROJECT_ID=my-project
# Location defaults to 'global'; set for regional data residency requirements
# export GCP_LOCATION=us-central1
```
