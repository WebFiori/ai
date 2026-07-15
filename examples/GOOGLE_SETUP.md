# Google Cloud Setup Guide

This guide explains how to set up Google Cloud credentials for running the examples that use the Google (Gemini) provider.

## Option 1: Gemini API with API Key (Simplest)

The Gemini API via Google AI Studio is the simplest way to get started. No GCP project billing is required for the free tier.

1. Go to [Google AI Studio](https://aistudio.google.com/apikey)
2. Click **Create API Key**
3. Copy the key and use it in your code:

```php
$provider = new GoogleClient([
    'api' => 'gemini',
    'api_key' => 'YOUR_API_KEY',
    'model' => 'gemini-2.5-flash',
]);
```

Or set it as an environment variable:

```bash
export GEMINI_API_KEY="your-api-key-here"
```

```php
$provider = new GoogleClient([
    'api' => 'gemini',
    'api_key' => getenv('GEMINI_API_KEY'),
    'model' => 'gemini-2.5-flash',
]);
```

## Option 2: Service Account Key (Production)

For production use or when you need access to Gemini Enterprise Agent Platform (previously Vertex AI) features, use a service account.

### Step 1: Create a GCP Project (if you don't have one)

1. Go to [GCP Console](https://console.cloud.google.com/)
2. Click the project dropdown → **New Project**
3. Enter a project name and click **Create**

### Step 2: Enable Required APIs

Enable at least one of these APIs in your project:

- **Gemini API (generativelanguage.googleapis.com):**
  [Enable here](https://console.cloud.google.com/apis/library/generativelanguage.googleapis.com)

- **Gemini Enterprise Agent Platform API (aiplatform.googleapis.com, previously Vertex AI):**
  [Enable here](https://console.cloud.google.com/apis/library/aiplatform.googleapis.com)

### Step 3: Create a Service Account

1. Go to [IAM & Admin → Service Accounts](https://console.cloud.google.com/iam-admin/serviceaccounts)
2. Click **Create Service Account**
3. Enter a name (e.g., `ai-library`) and click **Create and Continue**
4. Grant the following roles:
   - **Vertex AI User** (`roles/aiplatform.user`) — for the enterprise endpoint (previously Vertex AI)
   - Or **AI Platform Admin** if you need full access
5. Click **Done**

### Step 4: Create and Download a Key

1. Click on the service account you just created
2. Go to the **Keys** tab
3. Click **Add Key → Create new key**
4. Select **JSON** → Click **Create**
5. A `.json` file will download automatically
6. Move it to the project root:

```bash
mv ~/Downloads/your-project-*.json vertex-ai-key.json
```

> **Security:** Never commit this file to version control. It is already listed in `.gitignore`.

### Step 5: Use in Code

```php
use WebFiori\Ai\Provider\Google\GoogleClient;

// Using the Gemini API (simpler, free tier available)
$provider = new GoogleClient([
    'api' => 'gemini',
    'credentials' => __DIR__ . '/vertex-ai-key.json',
    'model' => 'gemini-2.5-flash',
]);

// Using the Gemini Enterprise Agent Platform endpoint (previously Vertex AI)
$provider = new GoogleClient([
    'api' => 'vertex_ai',
    'credentials' => __DIR__ . '/vertex-ai-key.json',
    'project_id' => 'your-project-id',
    'location' => 'us-central1',
    'model' => 'gemini-2.5-flash',
]);
```

## Option 3: Pre-fetched Access Token

If you already have an OAuth2 access token (e.g., from `gcloud`):

```bash
export GCP_ACCESS_TOKEN=$(gcloud auth print-access-token)
```

```php
$provider = new GoogleClient([
    'api' => 'gemini',
    'access_token' => getenv('GCP_ACCESS_TOKEN'),
    'model' => 'gemini-2.5-flash',
]);
```

## Available Models

| Model | Best For |
|-------|----------|
| `gemini-2.5-flash` | Fast, cost-effective responses |
| `gemini-2.5-pro` | Complex reasoning tasks |
| `text-embedding-004` | Text embeddings |

## Troubleshooting

### "Invalid JWT Signature" Error

The service account key may have been rotated or disabled. Create a new key following Step 4 above.

### "Permission denied" or 403

- Verify the service account has the correct roles (Step 3)
- Verify the API is enabled (Step 2)
- Check that billing is enabled on the project (required for the enterprise endpoint)

### "API not enabled" or 404

Enable the required API for your project (Step 2).

### Verify your setup with gcloud

```bash
# Authenticate with your service account
gcloud auth activate-service-account --key-file=vertex-ai-key.json

# Test the Gemini API
curl -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent" \
  -H "Authorization: Bearer $(gcloud auth print-access-token)" \
  -H "Content-Type: application/json" \
  -d '{"contents":[{"parts":[{"text":"Hello"}]}]}'
```

## CI/CD Configuration

If your CI/CD pipeline needs service account credentials without committing the JSON file to the repo, use one of these approaches.

### Option A: Base64-Encoded Secret (GitHub Actions, GitLab CI, etc.)

1. Encode the key file:

```bash
base64 -w 0 vertex-ai-key.json
```

2. Store the output as a CI secret (e.g., `GCP_SA_KEY_BASE64` in GitHub Actions).

3. In your workflow, decode it to a file or pass it as an array:

```yaml
# .github/workflows/test.yaml
jobs:
  test:
    runs-on: ubuntu-latest
    env:
      GCP_SA_KEY_BASE64: ${{ secrets.GCP_SA_KEY_BASE64 }}
    steps:
      - uses: actions/checkout@v4
      - name: Decode credentials
        run: echo "$GCP_SA_KEY_BASE64" | base64 -d > vertex-ai-key.json
      - name: Run tests
        run: composer test
```

In PHP, you can also pass the decoded JSON directly as an array:

```php
$provider = new GoogleClient([
    'api' => 'gemini',
    'credentials' => json_decode(base64_decode(getenv('GCP_SA_KEY_BASE64')), true),
    'model' => 'gemini-2.5-flash',
]);
```

### Option B: Raw JSON Secret

Store the entire JSON content as a secret (`GCP_SA_KEY_JSON`):

```yaml
env:
  GCP_SA_KEY_JSON: ${{ secrets.GCP_SA_KEY_JSON }}
```

```php
$provider = new GoogleClient([
    'api' => 'gemini',
    'credentials' => json_decode(getenv('GCP_SA_KEY_JSON'), true),
    'model' => 'gemini-2.5-flash',
]);
```

### Option C: Workload Identity Federation (No Secrets)

For GitHub Actions, you can use OIDC to get a short-lived token without storing any secrets:

```yaml
jobs:
  test:
    runs-on: ubuntu-latest
    permissions:
      id-token: write
      contents: read
    steps:
      - uses: actions/checkout@v4
      - uses: google-github-actions/auth@v2
        id: auth
        with:
          workload_identity_provider: 'projects/PROJECT_NUM/locations/global/workloadIdentityPools/POOL/providers/PROVIDER'
          service_account: 'webfiori-ai@webfiori.iam.gserviceaccount.com'
      - name: Run tests
        run: composer test
        env:
          GCP_ACCESS_TOKEN: ${{ steps.auth.outputs.access_token }}
```

```php
$provider = new GoogleClient([
    'api' => 'gemini',
    'access_token' => getenv('GCP_ACCESS_TOKEN'),
    'model' => 'gemini-2.5-flash',
]);
```

This is the most secure option — no long-lived credentials are stored anywhere.

### Option D: API Key Secret (Simplest for CI)

If you only need the Gemini API (not the enterprise endpoint), an API key is the simplest approach:

```yaml
env:
  GEMINI_API_KEY: ${{ secrets.GEMINI_API_KEY }}
```

```php
$provider = new GoogleClient([
    'api' => 'gemini',
    'api_key' => getenv('GEMINI_API_KEY'),
    'model' => 'gemini-2.5-flash',
]);
```
