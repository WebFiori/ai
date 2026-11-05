# 34 — Streaming Tool Loop

Stream a response while the model calls tools. `streamChat()` runs an
auto-execute tool loop: it streams a turn, and if the turn ends in tool calls it
executes them, appends the results, and streams the next turn — until the model
produces a final text answer. Only the **final answer's** tokens reach your
`onToken`; intermediate tool-call turns are consumed internally (tool use stays
observable through the `TOOL_*` status events).

> **Since 1.7.0**

See [ADR-0060](https://github.com/WebFiori/docs/blob/main/adr/0060-ai-streaming-tool-loop.md)
and [ADR-0059](https://github.com/WebFiori/docs/blob/main/adr/0059-ai-final-synthesis-on-max-iterations.md).

## What It Demonstrates

- Streaming + tool calling end to end via `ChatOption::AUTO_EXECUTE_TOOLS`
- The tool executes mid-stream; only the final answer streams to `onToken`
- `ChatOption::FINAL_SYNTHESIS_ON_MAX_ITERATIONS` — when the loop hits
  `MAX_TOOL_ITERATIONS` with tool calls still pending, one tools-off streaming
  turn is issued so the model still answers in text

The verification script uses `FakeHttpClient`, so it needs **no API key and no
network**.

## Running

```bash
php examples/34-streaming-tool-loop/verify.php
```

Expected: each scenario prints a `✅` line and the script ends with
"All streaming tool loop outcomes verified."

## Provider support

Streamed tool calls are supported by OpenAI, Anthropic, Google (Gemini via both
the generateContent and Interactions APIs), and AWS Bedrock (Converse). A
provider path that cannot surface tool calls from a stream (e.g. Bedrock's
Invoke API) throws `UnsupportedFeatureException` when auto-executed tools are
requested on a streaming call, rather than silently returning no answer.

## Files

| File | Description |
|------|-------------|
| `verify.php` | Offline, self-checking demo of the streaming tool loop and streaming final synthesis |

## Real usage

```php
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;

$client->streamChat(
    [new Message('user', 'What is the weather in Paris?')],
    function (string $token): void {
        echo $token;   // only the final answer's tokens arrive here
        flush();
    },
    onComplete: function ($response): void {
        // $response is the final text answer (no pending tool calls)
    },
    onError: null,
    options: [
        ChatOption::TOOLS => [$weatherTool],
        ChatOption::AUTO_EXECUTE_TOOLS => true,
        // Optional: still answer in text if the tool budget is exhausted.
        ChatOption::FINAL_SYNTHESIS_ON_MAX_ITERATIONS => true,
    ],
);
```
