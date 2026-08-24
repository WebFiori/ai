# 11 — Context Window Management

Token counting and automatic context window management to prevent overflow errors.

## What It Demonstrates

- Estimating token counts with `countTokens()`
- Checking remaining tokens with `getRemainingTokens()`
- Auto-truncation with `SlidingWindowStrategy`
- Summarizing old context with `SummarizingWindowStrategy`
- Failing fast with `NoTruncationStrategy`

## Files

| File | Description |
|------|-------------|
| `count.php` | CLI script — demonstrates token counting |
| `sliding.php` | CLI script — demonstrates sliding window truncation |
| `summarizing.php` | CLI script — demonstrates summarizing old messages instead of dropping them |
| `verify.php` | Verification script using FakeHttpClient |

## Running

```bash
# Token counting
php examples/11-context-window/count.php

# Sliding window (drops old messages)
php examples/11-context-window/sliding.php

# Summarizing window (summarizes old messages — requires API key)
source keys/env.sh && php examples/11-context-window/summarizing.php

# Verification (no API key needed)
php examples/11-context-window/verify.php
```

## Key Concepts

### Summarizing Window Strategy

Summarizes old messages into a system message instead of discarding them.
The model retains the gist of earlier conversation even after the context fills up.

```php
use WebFiori\Ai\Context\SummarizingWindowStrategy;
use WebFiori\Ai\Context\SummarizationPrompt;

$strategy = new SummarizingWindowStrategy(
    summarizer: $cheapClient,     // injected provider (use a cheap/fast model)
    contextWindow: 8192,          // your model's context window size
    threshold: 0.70,              // trigger at 70% full (default)
    keepRecentTurns: 3,           // keep last 3 user/assistant pairs verbatim
    reserveForCompletion: 1024,
);

$provider->setContextWindowStrategy($strategy);
// Old messages are automatically summarized when threshold is reached
```

Result when triggered:
```
[system: original system prompt]
[system: "Summary of earlier conversation: ..."]  ← injected
[user: recent message N-2]
[assistant: recent response N-2]
[user: recent message N-1]
[assistant: recent response N-1]
[user: current message]
```

Custom summarization prompt:
```php
$strategy = new SummarizingWindowStrategy(
    summarizer: $summarizer,
    prompt: new SummarizationPrompt(
        instruction: 'Summarize in bullet points, preserving key technical facts.',
        summaryPrefix: 'Prior context: '
    ),
);
```

### Sliding Window Strategy

Automatically removes oldest messages when context is exceeded:

```php
use WebFiori\Ai\Context\SlidingWindowStrategy;

$provider->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 128000,
    reserveForCompletion: 4096,
    preserveSystemMessage: true,
));
```

### No Truncation Strategy

Throws an exception instead of silently truncating:

```php
use WebFiori\Ai\Context\NoTruncationStrategy;
use WebFiori\Ai\Exception\ContextOverflowException;

$provider->setContextWindowStrategy(new NoTruncationStrategy(
    maxTokens: 128000,
    reserveForCompletion: 4096,
));

try {
    $response = $provider->chat($messages);
} catch (ContextOverflowException $e) {
    echo "Need {$e->getRequiredTokens()} tokens, ";
    echo "but only {$e->getAvailableTokens()} available\n";
}
```

## Strategy Comparison

| Strategy | What happens when full | Information loss | Extra API call |
|----------|----------------------|-----------------|----------------|
| `SlidingWindowStrategy` | Drops oldest messages | Yes — old messages lost | No |
| `SummarizingWindowStrategy` | Summarizes old messages | Minimal — summary preserves key facts | Yes (once, then cached) |
| `NoTruncationStrategy` | Throws exception | N/A | No |

## Model Context Limits

| Model | Context Window |
|-------|----------------|
| GPT-4o | 128,000 |
| Claude Sonnet | 200,000 |
| Gemini 2.5 Flash | 1,000,000 |

## What It Demonstrates

- Estimating token counts with `countTokens()`
- Checking remaining tokens with `getRemainingTokens()`
- Auto-truncation with `SlidingWindowStrategy`
- Failing fast with `NoTruncationStrategy`
- Custom strategy implementation

## Files

| File | Description |
|------|-------------|
| `count.php` | CLI script — demonstrates token counting |
| `sliding.php` | CLI script — demonstrates sliding window truncation |
| `verify.php` | Verification script using FakeHttpClient |

## Running

```bash
# Token counting
php examples/11-context-window/count.php

# Sliding window demo
php examples/11-context-window/sliding.php

# Verification (no API key needed)
php examples/11-context-window/verify.php
```

## Key Concepts

### Token Counting

```php
// Count tokens in messages
$tokens = $provider->countTokens($messages);

// Count tokens including tool definitions
$tokens = $provider->countTokens($messages, $tools);

// Check remaining capacity
$remaining = $provider->getRemainingTokens($messages);
```

### Sliding Window Strategy

Automatically removes oldest messages when context is exceeded:

```php
use WebFiori\Ai\Context\SlidingWindowStrategy;

$provider->setContextWindowStrategy(new SlidingWindowStrategy(
    maxTokens: 128000,           // Model's context limit
    reserveForCompletion: 4096,  // Reserve for response
    preserveSystemMessage: true, // Never truncate system message
));

// Messages are auto-truncated if needed
$response = $provider->chat($longConversation);
```

### No Truncation Strategy

Throws an exception instead of silently truncating:

```php
use WebFiori\Ai\Context\NoTruncationStrategy;
use WebFiori\Ai\Exception\ContextOverflowException;

$provider->setContextWindowStrategy(new NoTruncationStrategy(
    maxTokens: 128000,
    reserveForCompletion: 4096,
));

try {
    $response = $provider->chat($messages);
} catch (ContextOverflowException $e) {
    echo "Need {$e->getRequiredTokens()} tokens, ";
    echo "but only {$e->getAvailableTokens()} available\n";
}
```

### Custom Strategy

Implement `ContextWindowStrategyInterface` for custom logic:

```php
use WebFiori\Ai\Context\ContextWindowStrategyInterface;

class SummarizeOldMessagesStrategy implements ContextWindowStrategyInterface {
    public function truncate(array $messages, int $maxTokens, array $tools = []): array {
        // Summarize old messages instead of dropping them
        // ...
    }
    
    public function getReservedTokens(): int {
        return 4096;
    }
}
```

## Model Context Limits

| Model | Context Window |
|-------|----------------|
| GPT-4o | 128,000 |
| GPT-4o-mini | 128,000 |
| Claude 3.5 Sonnet | 200,000 |
| Claude 3 Opus | 200,000 |
| Gemini 1.5 Pro | 1,000,000 |
| Gemini 1.5 Flash | 1,000,000 |

Configure `maxTokens` based on your model.
