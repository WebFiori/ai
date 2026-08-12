# Multi-Modal Support (Images and Documents in Chat)

This example demonstrates how to send images, documents, and other files alongside text in chat messages to AI models that support multi-modal input.

## Supported Providers

| Provider | Images | PDFs | Text/Code | Audio | Video |
|----------|--------|------|-----------|-------|-------|
| Google (Gemini) | ✅ | ✅ | ✅ | ✅ | ✅ |
| OpenAI (GPT-4o) | ✅ | ✅ | ✅ | ⚠️ | ⚠️ |
| Anthropic (Claude 3) | ✅ | ✅ | ✅ | ❌ | ❌ |
| Bedrock (Claude) | ✅ | ✅ | ✅ | ❌ | ❌ |

⚠️ = Limited support, ❌ = Not supported

## Content Types

```php
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;

// Text content
ContentPart::text('What is in this image?');

// Image from URL (http/https)
ContentPart::imageUrl('https://example.com/photo.jpg');

// Image from base64 data
ContentPart::imageBase64($base64Data, 'image/png');

// Any file from local path (auto-detects type)
ContentPart::file('/path/to/document.pdf');
ContentPart::file('/path/to/code.py');      // Treated as text/plain
ContentPart::file('/path/to/image.jpg');    // Detected as image

// Any file with explicit MIME type
ContentPart::document($base64Data, 'application/pdf');
ContentPart::document($base64Data, 'audio/mpeg');
ContentPart::document($base64Data, 'video/mp4');

// Google Cloud Storage URI (Google provider only)
ContentPart::gcsUri('gs://bucket/path/file.pdf', 'application/pdf');
```

## Supported File Types

### Images
- JPEG (`image/jpeg`)
- PNG (`image/png`)
- GIF (`image/gif`)
- WebP (`image/webp`)

### Documents
- PDF (`application/pdf`)
- Word (`application/vnd.openxmlformats-officedocument.wordprocessingml.document`)
- Excel (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)
- PowerPoint (`application/vnd.openxmlformats-officedocument.presentationml.presentation`)
- Plain text, HTML, CSS, CSV, Markdown, XML, JSON

### Audio (Gemini)
- MP3 (`audio/mpeg`)
- WAV (`audio/wav`)
- OGG (`audio/ogg`)
- FLAC (`audio/flac`)

### Video (Gemini)
- MP4 (`video/mp4`)
- WebM (`video/webm`)
- MOV (`video/quicktime`)

### Code Files
All code files (`.py`, `.js`, `.java`, `.php`, etc.) are treated as `text/plain`.

## Basic Usage

```php
// Analyze an image
$message = new Message('user', [
    ContentPart::text('Describe this image:'),
    ContentPart::imageUrl('https://example.com/photo.jpg'),
]);

// Analyze a PDF
$message = new Message('user', [
    ContentPart::text('Summarize this document:'),
    ContentPart::file('/path/to/report.pdf'),
]);

// Analyze code
$message = new Message('user', [
    ContentPart::text('Review this code:'),
    ContentPart::file('/path/to/main.py'),
]);

$response = $client->chat([$message]);
echo $response->getMessage()->getContent();
```

## OpenAI Detail Level

OpenAI supports a `detail` parameter for controlling image processing quality.
Pass it via the `$options` array:

```php
$response = $client->chat($messages, [
    'detail' => 'high', // 'auto', 'low', or 'high'
]);
```

## Backward Compatibility

String content still works as before:

```php
// This still works
$message = new Message('user', 'Hello, world!');
```

## Running the Examples

```bash
# Basic vision test
php examples/16-multi-modal/vision.php

# Test all file types
php examples/16-multi-modal/test_file_types.php
```
