# Multi-Modal Support (Images in Chat)

This example demonstrates how to send images alongside text in chat messages
to vision-capable AI models.

## Supported Providers

| Provider | Support | Notes |
|----------|---------|-------|
| OpenAI | ✅ | GPT-4o, GPT-4 Vision models |
| Google | ✅ | Gemini 1.5+, Gemini 2.0 |
| Anthropic | ✅ | Claude 3+ models |
| Bedrock | ✅ | Claude via Converse API, Claude via Invoke |

## Content Types

```php
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;

// Text content
ContentPart::text('What is in this image?');

// Image from URL (http/https)
ContentPart::imageUrl('https://example.com/photo.jpg');

// Image from local file (auto-encoded to base64)
ContentPart::file('/path/to/image.png');

// Image from base64 data
ContentPart::imageBase64($base64Data, 'image/png');

// Google Cloud Storage URI (Google provider only)
ContentPart::gcsUri('gs://bucket/path/image.jpg', 'image/jpeg');
```

## Basic Usage

```php
$message = new Message('user', [
    ContentPart::text('Describe this image:'),
    ContentPart::imageUrl('https://example.com/photo.jpg'),
]);

$response = $client->chat([$message]);
echo $response->getMessage()->getContent();
```

## Supported Image Formats

- JPEG (`image/jpeg`)
- PNG (`image/png`)
- GIF (`image/gif`)
- WebP (`image/webp`)

## OpenAI Detail Level

OpenAI supports a `detail` parameter for controlling image processing quality.
Pass it via the `$options` array:

```php
$response = $client->chat($messages, [
    'detail' => 'high', // 'auto', 'low', or 'high'
]);
```

- `low`: 512px, fixed 85 tokens (faster, cheaper)
- `high`: up to 2048px, more tokens (better for fine details)
- `auto`: API decides based on image size (default)

## Multiple Images

You can include multiple images in a single message:

```php
$message = new Message('user', [
    ContentPart::text('Compare these two images:'),
    ContentPart::imageUrl('https://example.com/image1.jpg'),
    ContentPart::imageUrl('https://example.com/image2.jpg'),
]);
```

## Backward Compatibility

String content still works as before:

```php
// This still works
$message = new Message('user', 'Hello, world!');

// This is new
$message = new Message('user', [
    ContentPart::text('Hello, world!'),
]);
```

## Running the Example

```bash
php examples/16-multi-modal/vision.php
```
