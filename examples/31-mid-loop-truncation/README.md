# 31 — Mid-Loop Context Truncation

Keep the context window bounded **during** a multi-step tool chain by
re-applying the context window strategy between tool-execution iterations
(ADR-0056).

## The Problem

A context window strategy (e.g. `SlidingWindowStrategy`) is applied once,
*before* the tool-execution loop. When `auto_execute_tools` chains several tool
calls that each return large output, the conversation grows across iterations
and the whole accumulated prompt is re-sent on every round-trip — blowing past
the configured `maxTokens` even though each individual tool output was bounded.

## The Fix

Opt in with `ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS`. The strategy is then
re-applied after each tool result is appended, keeping the conversation within
budget throughout the turn. Truncation is **tool-call-pair aware**: it drops
whole tool-call groups (an assistant `tool_calls` message together with all of
its `tool` results), so it never produces an orphaned call or result that a
provider would reject.

```php
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Context\ToolLoopOverflowBehavior;

$client->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 150000,
    reserveForCompletion: 8192,
));

$response = $client->chat($messages, [
    ChatOption::TOOLS => $tools,
    ChatOption::AUTO_EXECUTE_TOOLS => true,

    // Opt-in: re-truncate between tool iterations (default: false)
    ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,

    // How to handle overflow (default: DROP_OLDEST)
    ChatOption::TOOL_LOOP_OVERFLOW_BEHAVIOR => ToolLoopOverflowBehavior::DROP_OLDEST,
]);
```

## Overflow Behaviors

| Behavior | What happens to old tool-call groups | Needs a summarizer |
|----------|--------------------------------------|--------------------|
| `DROP_OLDEST` (default) | Oldest groups are dropped wholesale | No |
| `SUMMARIZE` | Oldest groups are condensed into a short summary message | Yes |

The most recent tool-call group (the one driving the current iteration) and the
system message are always preserved.

### Using `SUMMARIZE`

`SUMMARIZE` keeps the gist of dropped tool activity instead of discarding it. It
needs a summarizer provider, resolved in this order:

1. `ChatOption::TOOL_LOOP_SUMMARIZER` if provided (prefer a cheap/fast model);
2. otherwise the summarizer of a configured `SummarizingWindowStrategy`;
3. otherwise `chat()` fails fast with an `InvalidConfigException`.

```php
$response = $client->chat($messages, [
    ChatOption::TOOLS => $tools,
    ChatOption::AUTO_EXECUTE_TOOLS => true,
    ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,
    ChatOption::TOOL_LOOP_OVERFLOW_BEHAVIOR => ToolLoopOverflowBehavior::SUMMARIZE,
    ChatOption::TOOL_LOOP_SUMMARIZER => $cheapClient,
]);
```

## Files

| File | Description |
|------|-------------|
| `verify.php` | Verification script using `FakeHttpClient` (no API key needed) |

## Running

```bash
php examples/31-mid-loop-truncation/verify.php
```

Expected output shows the default run carrying the full accumulated history,
versus the opt-in run keeping the final request bounded — with tool-call /
tool-result pairing intact across every request.

## Notes

- **Opt-in and backward-compatible.** When the flag is unset, behavior is
  unchanged: truncation still happens once before the loop only.
- **No-op without a strategy.** If no context window strategy is configured, the
  option does nothing.
- **Observability.** A `Status::TRUNCATING_CONTEXT` event is emitted each time
  mid-loop truncation removes messages, with the current `iteration`.
