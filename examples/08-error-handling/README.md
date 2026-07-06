# 08 — Error Handling

Demonstrates how to handle errors from AI providers using the typed exception hierarchy.

## What It Demonstrates

- Catching specific exception types (rate limit, auth, provider errors)
- Extracting retry-after information from rate limit exceptions
- Handling invalid configuration
- Graceful degradation patterns

## Files

| File | Description |
|------|-------------|
| `errors.php` | CLI script — error handling patterns |

## Running

```bash
php examples/08-error-handling/errors.php
```
