# 12 — Health Checks

Verify that AI providers are available before sending requests.

## What It Demonstrates

- Checking provider availability with `healthCheck()`
- Handling unavailable providers gracefully
- Custom health check timeouts
- Using `HealthCheckResult` for monitoring

## Files

| File | Description |
|------|-------------|
| `health.php` | CLI script — checks Google Gemini availability |
| `verify.php` | Verification script using assertions (no API key needed) |

## Running

```bash
# Real health check (requires API credentials)
php examples/12-health-checks/health.php

# Verification (no API key needed)
php examples/12-health-checks/verify.php
```

## Usage

```php
$result = $provider->healthCheck();         // 5s default timeout
$result = $provider->healthCheck(timeout: 10); // custom timeout

if ($result->isAvailable()) {
    $response = $provider->chat($messages);
} else {
    echo "Provider unavailable: " . $result->getError();
}

// Full result details
$result->isAvailable();    // bool
$result->getLatencyMs();   // int — response time in ms
$result->getError();       // ?string — error if unavailable
$result->getCheckedAt();   // DateTimeInterface
$result->getCheckMethod(); // string — e.g. 'models_list', 'minimal_completion'
```

## Check Methods by Provider

| Provider | Method | Cost |
|----------|--------|------|
| OpenAI | `models_list` — `GET /v1/models` | Free |
| Google | `models_list` — model info endpoint | Free |
| Anthropic | `minimal_completion` — max_tokens: 1 | ~$0.00001 |
| Bedrock | `models_list` — `ListFoundationModels` | Free |

## Notes

- `healthCheck()` **never throws** — errors are captured in the result
- Uses a **separate short timeout** (default 5s) from the provider's normal timeout
- **Bypasses caching and retry logic** to give real-time status
