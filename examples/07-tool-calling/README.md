# 07 — Tool Calling

Demonstrates AI-invoked functions using the `Tool` class. Supports both manual mode (you control the loop) and auto-execute mode (the library handles it).

## What It Demonstrates

- Defining tools with the `Tool` class
- Auto-execute mode (`auto_execute_tools` option)
- Manual tool calling loop for fine-grained control
- Multiple tools in a single conversation
- Max iteration limit to prevent infinite loops

## Files

| File | Description |
|------|-------------|
| `tools.php` | CLI script — demonstrates both auto-execute and manual modes |
| `index.php` | Web page — interactive auto-execute tool calling |

## Running

### CLI

```bash
php examples/07-tool-calling/tools.php
```

### Web

```bash
php -S localhost:8080 -t examples/07-tool-calling
```

Open http://localhost:8080. Ask about the weather or time and watch the AI invoke tools automatically.
