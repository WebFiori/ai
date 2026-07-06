# 06 — Image Generation

Generate images from text prompts using DALL-E.

## What It Demonstrates

- Using `generateImage()` with an `ImageRequest`
- Configuring size, quality, and style
- Handling the response (URL or base64)
- Displaying revised prompts (DALL-E rewrites prompts for better results)

## Files

| File | Description |
|------|-------------|
| `generate.php` | CLI script — generate an image and output the URL |
| `index.php` | Web page — image generation form with preview |

## Running

### CLI

```bash
php examples/06-image-generation/generate.php
```

### Web

```bash
php -S localhost:8080 -t examples/06-image-generation
```

Open http://localhost:8080. Enter a prompt, choose options, and see the generated image.
