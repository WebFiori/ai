# AWS Bedrock Examples

This directory contains examples for using the AWS Bedrock provider.

## Setup

1. Get AWS credentials with Bedrock access from your AWS account
2. Set the environment variables:
   ```bash
   export AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
   export AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
   export AWS_REGION=us-east-1
   ```

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
$client = new BedrockClient([
    'access_key' => 'AKIA...',   // Required: AWS access key
    'secret_key' => 'wJal...',   // Required: AWS secret key
    'region' => 'us-east-1',     // Required: AWS region
    'model' => 'anthropic.claude-3-5-sonnet-20241022-v2:0', // Default model
    'max_tokens' => 4096,        // Default max tokens
]);
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
