# 07 — Tool Calling

Demonstrates AI-invoked functions using the `Tool` class. Supports both manual mode (you control the loop) and auto-execute mode (the library handles it).

## What It Demonstrates

- Defining tools with the `Tool` class
- Auto-execute mode (`auto_execute_tools` option)
- Manual tool calling loop for fine-grained control
- Multiple tools in a single conversation
- Max iteration limit to prevent infinite loops
- **Connection reuse** for better performance with multiple tool calls
- **LazyTool** for deferred instantiation of expensive tools

## Performance Features

### Connection Reuse

Enable HTTP connection reuse to avoid TCP+TLS handshake overhead on subsequent requests:

```php
$client->enableConnectionReuse();
```

This saves ~300ms per request in multi-tool scenarios.

### LazyTool

Use `LazyTool` for tools with expensive constructors (database connections, API clients):

```php
use WebFiori\Ai\Tool\LazyTool;

$tool = new LazyTool(
    'search_database',
    'Search the product database',
    $parameters,
    fn() => new ExpensiveDatabaseTool($connection)  // Only created if AI calls this tool
);
```

Benefits:
- Tools defined but not called = zero initialization overhead
- Useful when you have many tools but only a few are used per request

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
