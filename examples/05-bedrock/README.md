# AWS Bedrock Examples

This directory contains examples for using the AWS Bedrock provider.

## Setup

The library resolves AWS credentials automatically using the standard credential chain. You do not need to provide explicit keys in production environments.

### Credential Resolution Order

**1. Explicit configuration (highest priority)**
```php
$client = new BedrockClient([
    'access_key' => 'AKIA...',
    'secret_key' => 'wJal...',
    'region'     => 'us-east-1',
]);
```

**2. Environment variables**
```bash
export AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
export AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
export AWS_SESSION_TOKEN=optional-session-token  # for temporary credentials
export AWS_REGION=us-east-1
```

**3. AWS credentials file**

Linux/Mac: `~/.aws/credentials`
Windows: `%USERPROFILE%\.aws\credentials`

```ini
[default]
aws_access_key_id = AKIAIOSFODNN7EXAMPLE
aws_secret_access_key = wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY

[staging]
aws_access_key_id = STAGINGKEY
aws_secret_access_key = STAGINGSECRET
```

Use `AWS_PROFILE` env var to select a named profile:
```bash
export AWS_PROFILE=staging
```

**4. EC2 / ECS / Lambda instance metadata (zero-config)**

No configuration needed when running on AWS infrastructure with an IAM role attached. Temporary credentials are retrieved automatically from the metadata service and refreshed as needed.

## Examples

### Basic Chat

```bash
php examples/05-bedrock/chat.php
```

Simple request/response chat completion with Claude on Bedrock.

## Available Models

### Anthropic Claude
- `anthropic.claude-3-5-sonnet-20241022-v2:0` (recommended)
- `anthropic.claude-3-opus-20240229-v1:0`
- `anthropic.claude-3-haiku-20240307-v1:0`

### Meta Llama
- `meta.llama3-70b-instruct-v1:0`
- `meta.llama3-8b-instruct-v1:0`

### Mistral
- `mistral.mistral-large-2407-v1:0`
- `mistral.mixtral-8x7b-instruct-v0:1`

## Configuration Options

```php
// Explicit credentials
$client = new BedrockClient([
    'access_key' => 'AKIA...',   // Optional: AWS access key (uses credential chain if omitted)
    'secret_key' => 'wJal...',   // Required when access_key is provided
    'session_token' => '...',    // Optional: for temporary IAM role credentials
    'region' => 'us-east-1',     // Required: AWS region
    'model' => 'anthropic.claude-3-5-sonnet-20241022-v2:0', // Default model
    'max_tokens' => 4096,        // Default max tokens
]);

// Zero-config on EC2/ECS/Lambda with IAM role
$client = new BedrockClient(['region' => 'us-east-1']);
```

## Differences from Direct Anthropic API

1. **Authentication**: Uses AWS SigV4 signing instead of API key header.
2. **Endpoint**: Uses Bedrock Runtime endpoint, not Anthropic's API.
3. **Model IDs**: Prefixed with provider name (e.g., `anthropic.claude-...`).
4. **Pricing**: Billed through AWS, may differ from direct Anthropic pricing.

## IAM Permissions Required

Your AWS credentials need the following IAM permissions:
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "bedrock:InvokeModel",
                "bedrock:InvokeModelWithResponseStream"
            ],
            "Resource": "arn:aws:bedrock:*::foundation-model/*"
        }
    ]
}
```

## Current Limitations

- Embeddings are not yet implemented (use OpenAI or Google)
- Image generation is not yet implemented (use OpenAI or Google)
- Streaming is implemented but may have edge cases with some models
