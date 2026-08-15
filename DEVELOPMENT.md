# Development Guide

This document describes the development workflow and conventions used in this repository.

## Workflow

Every feature follows this pattern:

### 1. Plan

Before writing any code, clarify the design with the team. Key questions to answer:

- What problem does this solve?
- What are the edge cases?
- Where does it integrate with existing code?
- Does it need a new interface or can it extend an existing one?
- Does it follow the zero-dependency principle?

### 2. ADR

Significant architectural decisions get an ADR in [WebFiori/docs](https://github.com/WebFiori/docs/tree/main/adr).

Create the ADR **before** implementation. Use the template at `adr/0000-template.md` in that repo.

ADRs should document:
- **Context** — why this decision was needed
- **Decision** — what was decided, with code examples
- **Alternatives Considered** — what was rejected and why
- **Consequences** — what becomes easier or harder

### 3. Feature Branch

Always work on a feature branch:

```bash
git checkout main && git pull
git checkout -b feat/your-feature-name
```

Never commit directly to `main`.

### 4. Implementation

- Follow existing code style and conventions
- Match the patterns used by existing classes (interfaces, traits, DTOs)
- Zero external dependencies — no new `require` entries in `composer.json`
- Add `use` statements alphabetically in imports

### 5. Tests

Tests are **not optional**. Every feature must have:

- Unit tests for all new classes
- Integration tests through the provider layer using `FakeHttpClient`
- Edge case coverage (empty input, null values, error paths)
- A `verify.php` script in `examples/` that runs end-to-end without a real API key

Run the full suite before committing:

```bash
./vendor/bin/phpunit --configuration tests/phpunit.xml
```

### 6. Example

Every feature gets an entry under `examples/`:

- `examples/NN-feature-name/verify.php` — runnable verification using `FakeHttpClient`
- `examples/NN-feature-name/README.md` — explains usage, options, and edge cases
- Update `examples/README.md` to add the new entry to the table

### 7. Commit

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat(scope): short description

- Bullet points for key changes
- Keep it factual and specific

Closes #issue-number
```

Common scopes: `cache`, `context`, `audit`, `redaction`, `metrics`, `http`, `google`, `openai`, `bedrock`, `anthropic`

### 8. Pull Request

```bash
git push -u origin feat/your-feature-name
gh pr create --title "feat(scope): description" --body "..."
```

PR description must include:
- Summary of changes
- How to test / verify
- Breaking changes (if any)
- Checklist (see PR template in WebFiori/.github)
- `Closes #issue-number`

---

## Code Conventions

### Zero Dependencies

The library has no runtime dependencies beyond PHP's built-in extensions (`curl`, `json`). Do not add any.

If you need functionality that would typically require a library, implement a minimal version inline or design an interface that developers can implement with their preferred library.

### Interface-First Design

New capabilities should be defined as interfaces first:

- `CacheInterface` — pluggable cache backends
- `HttpClientInterface` — pluggable HTTP transport
- `ConversationStorageInterface` — pluggable conversation storage
- `ContextWindowStrategyInterface` — pluggable truncation strategies

This lets developers swap implementations without changing their code.

### Decorator Pattern for HTTP

HTTP middleware (retry, rate limiting) wraps the `HttpClientInterface` via the decorator pattern. New HTTP-level concerns should follow this pattern rather than adding logic to `AbstractClient`.

### Trait Pattern for Cross-Cutting Concerns

Observability features (logging, metrics, audit, redaction) are implemented as traits mixed into `AbstractClient`:

- `LoggerTrait` — logging via callback
- `MetricsTrait` — metrics events via callback
- `AuditTrait` — structured audit entries via callback

New observability features should follow this pattern.

### Provider Implementation

All providers extend `AbstractClient` and implement the abstract methods:

| Method | Purpose |
|--------|---------|
| `buildChatRequest()` | Build HTTP request for chat |
| `buildStreamChatRequest()` | Build HTTP request for streaming chat |
| `buildEmbedRequest()` | Build HTTP request for embeddings |
| `buildImageRequest()` | Build HTTP request for image generation |
| `parseChatResponse()` | Parse HTTP response into ChatResponse |
| `parseEmbedResponse()` | Parse HTTP response into EmbeddingResponse |
| `parseImageResponse()` | Parse HTTP response into ImageResponse |
| `handleErrorResponse()` | Map HTTP errors to typed exceptions |
| `healthCheck()` | Verify provider availability |

### Response DTOs

Response DTOs (`ChatResponse`, `EmbeddingResponse`, `ImageResponse`) are immutable value objects. New fields should be added as optional constructor parameters with `null` default to preserve backward compatibility.

---

## Testing Conventions

### FakeHttpClient

Use `FakeHttpClient` to test provider behavior without real API calls:

```php
$fakeHttp = new FakeHttpClient();
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([...])));
$provider->setHttpClient($fakeHttp);
```

### verify.php

Every feature has a `verify.php` that:
1. Uses `FakeHttpClient` (no API key needed)
2. Runs assertions with clear ✅/❌ output
3. Demonstrates all key scenarios from the README

### Test Structure

Tests live in `tests/WebFiori/Tests/Ai/`. Group by feature:

- `CacheTest.php` — cache feature tests
- `MetricsTest.php` — metrics feature tests
- `AuditTest.php` — audit feature tests
- etc.

Each test class covers:
1. The feature's own classes (unit tests)
2. Integration with the provider (`OpenAIClient` + `FakeHttpClient`)

---

## Architecture Decision Records

ADRs relevant to this library live in [WebFiori/docs/adr](https://github.com/WebFiori/docs/tree/main/adr).

| ADR | Decision |
|-----|---------|
| 0029 | cURL behind HttpClientInterface |
| 0030 | Exceptions over result objects |
| 0031 | Logging via callback |
| 0033 | Caching interface — zero-dependency, own interface |
| 0034 | Token counting — character-ratio estimation |
| 0035 | Health checks — minimal completion for providers without free endpoints |
| 0036 | Metrics collection — separate callback, synchronous |
| 0037 | PII redaction — RedactionService, mandatory api_key/bearer rules |
| 0038 | Structured audit logging — AuditTrait, static+dynamic context |
| 0033 | RAG — retrieval as a tool, not a pipeline wrapper |
| 0034 | SQLite3 class over PDO for SqliteVectorStore |
