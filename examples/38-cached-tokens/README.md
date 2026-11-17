# Example 38: Cached Input Tokens

Providers can serve part of a prompt from a cache and bill those tokens at a lower rate. The library reports how many input tokens were cached and prices them separately. See ADR-0062.

## Run

```bash
php examples/38-cached-tokens/verify.php
```

It uses `FakeHttpClient`, so it needs no API key and no network.

## Reading cached tokens

```php
$usage = $client->chat($messages)->getUsage();

$usage->getPromptTokens();      // total input, including cached and cache-write tokens
$usage->getCachedTokens();      // input tokens read from cache (subset of prompt)
$usage->getCacheWriteTokens();  // input tokens written to cache (subset of prompt)
```

`getPromptTokens()` is the full input for every provider. Anthropic and Bedrock report only the uncached part in their own `input_tokens` / `inputTokens` field, so the library adds their cache read and write counters back. Uncached input is `prompt - cached - cacheWrite`.

If a provider reports more cached and written tokens than the prompt total, `Usage` reduces them (cached first, then writes) so they never exceed it.

## Pricing cached tokens

Add optional `cached_input` and `cache_write` prices (USD per million tokens):

```php
$pricing = new PricingConfig([
    'claude-sonnet-4' => [
        'input' => 3.00, 'output' => 15.00,
        'cached_input' => 0.30, 'cache_write' => 3.75,
    ],
]);

// or
$pricing->setModel('gpt-4o', 2.50, 10.00, cachedInputPrice: 1.25);

$client->setPricing($pricing);
$cost = $client->chat($messages)->getCost();

$cost->getInputCost();        // total input cost, cached parts included
$cost->getCachedInputCost();  // the part for cached tokens
$cost->getCacheWriteCost();   // the part for cache-write tokens
```

When a cached price is not set, those tokens are charged at the normal input price, so existing pricing tables give the same cost as before.

## Provider notes

| Provider | Where the count comes from | Cache writes |
|---|---|---|
| OpenAI | `usage.prompt_tokens_details.cached_tokens` | Only if the API reports `cache_write_tokens` |
| Google `generateContent` | `usageMetadata.cachedContentTokenCount` | Not reported |
| Google Interactions | `usage.total_cached_tokens` | Not reported |
| Anthropic | `cache_read_input_tokens` | `cache_creation_input_tokens` |
| Bedrock Converse | `cacheReadInputTokens` | `cacheWriteInputTokens` |
| Bedrock InvokeModel (Anthropic models) | Same as Anthropic | Same as Anthropic |

- OpenAI and Gemini cache automatically. Anthropic and Bedrock cache only when the request carries cache markers (`cache_control`, `cachePoint`), which this library does not send yet, so they normally report 0.
- OpenAI streaming requests now send `stream_options.include_usage`, so streamed responses carry usage.
- Anthropic bills cache writes differently for 5-minute and 1-hour caches. One `cache_write` price cannot express both.

## Audit and metrics

The success audit entry of `chat()` has `tokens.cached` and `tokens.cache_write`, and the `request.completed` metric has `cached_tokens` and `cache_write_tokens`. `prompt` still means total input.

## Live check

```bash
source keys/env.sh && php live/18-cached-tokens.php
```

It sends the same long prompt twice to each configured provider and prints what was cached.
