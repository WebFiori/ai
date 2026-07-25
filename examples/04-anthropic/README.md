# Anthropic Claude Examples

This directory contains examples for using the Anthropic Claude provider.

## Setup

1. Get an API key from [Anthropic Console](https://console.anthropic.com/)
2. Set the environment variable:
   ```bash
   export ANTHROPIC_API_KEY=sk-ant-your-key-here
   ```

## Examples

### Basic Chat

```bash
php examples/04-anthropic/chat.php
```

Simple request/response chat completion.

### Streaming

```bash
php examples/04-anthropic/stream.php
```

Token-by-token streaming output.

### Tool Calling

```bash
php examples/04-anthropic/tools.php
```

Function/tool calling with automatic execution.

## Available Models

- `claude-sonnet-4-20250514` (recommended, default)
- `claude-3-5-sonnet-20241022`
- `claude-3-opus-20240229`
- `claude-3-haiku-20240307`

## Configuration Options

```php
$client = new AnthropicClient([
    'api_key' => 'sk-ant-...',           // Required
    'model' => 'claude-sonnet-4-20250514',       // Default model
    'max_tokens' => 4096,                // Default max tokens (required by Anthropic)
    'anthropic_version' => '2023-06-01', // API version
    'base_url' => 'https://api.anthropic.com', // API endpoint
]);
```

## Differences from OpenAI

1. **System message**: Handled as a separate top-level parameter (the library handles this automatically).
2. **max_tokens**: Required by Anthropic (defaults to 4096 if not specified).
3. **No embeddings**: Anthropic doesn't offer embeddings. Use OpenAI or Google.
4. **No image generation**: Anthropic doesn't offer image generation. Use OpenAI or Google.
