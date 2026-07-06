# 01 — Basic Chat

A minimal example demonstrating a single chat completion request to OpenAI.

## What It Demonstrates

- Creating an OpenAI provider with configuration
- Sending a chat message with system and user roles
- Reading the response content, model, finish reason, and token usage

## Files

| File | Description |
|------|-------------|
| `chat.php` | CLI script — run from terminal |
| `index.php` | Web page — simple chat form |

## Running

### CLI

```bash
php examples/01-basic-chat/chat.php
```

### Web

Start PHP's built-in server from the project root:

```bash
php -S localhost:8080 -t examples/01-basic-chat
```

Then open http://localhost:8080 in your browser.

## Expected Output (CLI)

```
Response: PHP is a server-side scripting language designed for web development.
Model: gpt-4o
Finish reason: stop
Tokens — Prompt: 25, Completion: 14, Total: 39
```
