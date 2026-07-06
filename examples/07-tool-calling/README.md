# 07 — Tool Calling

Demonstrates AI-invoked functions. The AI can request to call tools you define, use the results, and formulate a response.

## What It Demonstrates

- Defining tools with the `ToolInterface`
- Detecting tool calls in the response
- Executing tools and sending results back
- The full tool calling loop (user → AI → tool → AI → user)

## Files

| File | Description |
|------|-------------|
| `tools.php` | CLI script — weather lookup tool calling demo |
| `index.php` | Web page — interactive tool calling with live execution |

## Running

### CLI

```bash
php examples/07-tool-calling/tools.php
```

### Web

```bash
php -S localhost:8080 -t examples/07-tool-calling
```

Open http://localhost:8080. Ask about the weather and watch the AI invoke the weather tool.
