# 02 — Streaming

Real-time token-by-token streaming using Server-Sent Events (SSE). The web UI shows text appearing character by character, like ChatGPT.

## What It Demonstrates

- Using `streamChat()` for incremental responses
- Server-Sent Events (SSE) for real-time browser updates
- `onToken`, `onComplete`, and `onError` callbacks
- JavaScript EventSource for consuming the stream

## Files

| File | Description |
|------|-------------|
| `stream.php` | CLI script — prints tokens as they arrive |
| `index.php` | Web page — chat UI with live streaming |
| `sse.php` | SSE endpoint — streams tokens to the browser |

## Running

### CLI

```bash
php examples/02-streaming/stream.php
```

### Web

```bash
php -S localhost:8080 -t examples/02-streaming
```

Open http://localhost:8080 in your browser. Type a message and watch the response stream in real-time.
