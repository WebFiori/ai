# 04 — Conversation

Multi-turn conversation with persistent history. The AI remembers previous messages in the conversation.

## What It Demonstrates

- Using the `Conversation` class for stateful chat
- System message configuration
- Automatic history loading/saving via `InMemoryStorage`
- Multi-turn follow-up messages
- Max history sliding window

## Files

| File | Description |
|------|-------------|
| `chat.php` | CLI script — interactive multi-turn conversation |
| `index.php` | Web page — chat interface with session-based history |

## Running

### CLI

```bash
php examples/04-conversation/chat.php
```

Type messages and see the AI maintain context. Type `quit` to exit.

### Web

```bash
php -S localhost:8080 -t examples/04-conversation
```

Open http://localhost:8080. The conversation history persists within the PHP session.
