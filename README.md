# WebFiori AI

A provider-agnostic AI library for PHP. Supports chat completions, embeddings, image generation, tool calling, and streaming across multiple providers (OpenAI, Google Vertex AI, Anthropic, AWS Bedrock).

<p align="center">
  <a href="https://github.com/WebFiori/ai/actions">
    <img src="https://github.com/WebFiori/ai/actions/workflows/php85.yaml/badge.svg?branch=main">
  </a>
  <a href="https://codecov.io/gh/WebFiori/ai">
    <img src="https://codecov.io/gh/WebFiori/ai/branch/main/graph/badge.svg" />
  </a>
  <a href="https://sonarcloud.io/dashboard?id=WebFiori_ai">
      <img src="https://sonarcloud.io/api/project_badges/measure?project=WebFiori_ai&metric=alert_status" />
  </a>
  <a href="https://github.com/WebFiori/ai/releases">
      <img src="https://img.shields.io/github/release/WebFiori/ai.svg?label=latest" />
  </a>
  <a href="https://packagist.org/packages/webfiori/ai">
      <img src="https://img.shields.io/packagist/dt/webfiori/ai?color=light-green">
  </a>
</p>

## Key Features

- **Provider-Agnostic** — Common interface across OpenAI, Google Vertex AI, Anthropic, and AWS Bedrock
- **Chat Completions** — Send messages and receive AI-generated responses
- **Streaming** — Token-by-token streaming via Server-Sent Events
- **Embeddings** — Generate vector embeddings for semantic search
- **Image Generation** — Generate images from text prompts
- **Tool/Function Calling** — Define tools the AI can invoke during conversation
- **Conversation Management** — Built-in conversation history with swappable storage
- **Enterprise Ready** — Retry logic, rate limiting, caching, health checks, metrics, audit logging

## Supported PHP Versions

|                                                                                        Build Status                                                                                         |
|:-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------:|
| <a target="_blank" href="https://github.com/WebFiori/ai/actions/workflows/php81.yaml"><img src="https://github.com/WebFiori/ai/actions/workflows/php81.yaml/badge.svg?branch=main"></a> |
| <a target="_blank" href="https://github.com/WebFiori/ai/actions/workflows/php82.yaml"><img src="https://github.com/WebFiori/ai/actions/workflows/php82.yaml/badge.svg?branch=main"></a> |
| <a target="_blank" href="https://github.com/WebFiori/ai/actions/workflows/php83.yaml"><img src="https://github.com/WebFiori/ai/actions/workflows/php83.yaml/badge.svg?branch=main"></a> |
| <a target="_blank" href="https://github.com/WebFiori/ai/actions/workflows/php84.yaml"><img src="https://github.com/WebFiori/ai/actions/workflows/php84.yaml/badge.svg?branch=main"></a> |
| <a target="_blank" href="https://github.com/WebFiori/ai/actions/workflows/php85.yaml"><img src="https://github.com/WebFiori/ai/actions/workflows/php85.yaml/badge.svg?branch=main"></a> |

## Installation

```bash
composer require webfiori/ai
```

## Quick Start

```php
<?php

use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Message;

$client = new OpenAIClient([
    'api_key' => 'sk-...',
    'model' => 'gpt-4o',
]);

$response = $client->chat([
    new Message('system', 'You are a helpful assistant.'),
    new Message('user', 'What is PHP?'),
]);

echo $response->getMessage()->getContent();
```

### Streaming

```php
$client->streamChat(
    messages: [
        new Message('user', 'Write a story about PHP'),
    ],
    onToken: function (string $token) {
        echo $token;
        flush();
    },
);
```

### Multiple Providers

```php
use WebFiori\Ai\Provider\VertexAI\VertexAIClient;

$client = new VertexAIClient([
    'project_id' => 'my-project',
    'location' => 'us-central1',
    'model' => 'gemini-1.5-pro',
    'credentials' => '/path/to/service-account.json',
]);

$response = $client->chat([
    new Message('user', 'What is PHP?'),
]);
```

## Documentation

- [Examples](examples/)
- [API Documentation](https://webfiori.com/docs)
- [Architecture Decision Records](https://github.com/WebFiori/docs/tree/main/adr)

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request. For major changes, please open an issue first to discuss what you would like to change.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of changes and version history.
