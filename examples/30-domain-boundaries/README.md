# Example 30: Domain Boundaries — Guardrails for Off-Topic Questions

Redirect off-topic questions **before** they reach the model — saving tokens and
keeping agents on-topic. A `DomainBoundaries` guardrail turns the domain an
`AgentProfile` already describes into an enforceable boundary.

## Run

```bash
php examples/30-domain-boundaries/basic.php
```

Set `OPENAI_API_KEY` in your environment for the in-scope paths that call the
model. Off-topic paths never hit the API, so they work without a key.

## What it demonstrates

1. Attaching `DomainBoundaries` to an `AgentProfile` — the profile owns its domain.
2. Off-topic questions redirected with **zero** provider/API calls.
3. Adjacency override: tangential questions ("how do market factors affect our
   forecast") pass through even though they touch a blocked keyword.
4. The optional `ChatOption::DOMAIN_BOUNDARIES` entry point for raw `chat()`.
5. Tracing decisions via `evaluate()` — `reason`, `matched_pattern`, `strategy`.

## Strategies

| Strategy | How it decides | Threshold |
|----------|----------------|-----------|
| `regex` (default) | Block-list patterns, with `adjacent_allowed` override | none |
| `keyword` | Positive scope: question must share vocabulary with `in_scope` | none |
| `semantic` | Cosine similarity of embeddings vs. domain, needs an embedder | yes |

## Observability

Both enforcement points (`AgentTool::execute()` and `AbstractClient::chat()`)
emit:

- Status events: `Status::BOUNDARY_REDIRECT` / `Status::BOUNDARY_ALLOWED`
- Metrics: `boundary.blocked` / `boundary.allowed` with `reason`,
  `matched_pattern`, `strategy`, and `score` (semantic only)

Use `logOnly: true` (shadow mode) to record what *would* be blocked without
actually blocking — the recommended way to tune patterns and the semantic
threshold in production before enforcing.

## JSON profile

See `agent-profile.json` for a declarative profile with a `domain_boundaries`
block, loadable via `AgentProfile::fromFile()`.
