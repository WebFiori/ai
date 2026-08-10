# 13 — Metrics Collection

Emit structured metrics events from all AI provider operations for routing to monitoring systems.

## What It Demonstrates

- Setting up a metrics callback with `setMetricsCallback()`
- Events emitted by `chat()`, `embed()`, `streamChat()`, `generateImage()`
- Cache hit/miss events
- Request ID for end-to-end tracing
- Bridging to DataDog, Prometheus, CloudWatch, etc.

## Files

| File | Description |
|------|-------------|
| `verify.php` | Verification script — no API key needed |

## Running

```bash
php examples/13-metrics/verify.php
```

## Usage

```php
$provider->setMetricsCallback(function (string $event, array $data) {
    // Route to your monitoring system
    MyMonitoring::record($event, $data);
});
```

## All Events

Every event includes `timestamp` (ms), `request_id`, `provider`, and `model`.

| Event | Additional Data | Emitted By |
|-------|----------------|------------|
| `request.sent` | endpoint, method | All operations |
| `request.completed` | status_code, latency_ms, prompt_tokens, completion_tokens, total_tokens | chat, embed, image |
| `request.failed` | error_type, error_message, latency_ms | All operations |
| `cache.hit` | key | chat, embed |
| `cache.miss` | key | chat, embed |
| `stream.started` | — | streamChat |
| `stream.completed` | duration_ms, tokens | streamChat |
| `stream.error` | error | streamChat |
| `health_check.completed` | latency_ms, check_method | healthCheck |
| `health_check.failed` | error, latency_ms, check_method | healthCheck |

## Request ID

Auto-generated per request. Override via options:

```php
$response = $provider->chat($messages, ['request_id' => $myTraceId]);
$response->getRequestId(); // correlate with metrics
```

## DataDog Example

```php
$provider->setMetricsCallback(function (string $event, array $data) use ($statsd) {
    if ($event === 'request.completed') {
        $statsd->timing('ai.latency', $data['latency_ms'], [
            'provider' => $data['provider'],
            'model' => $data['model'],
        ]);
        $statsd->increment('ai.tokens', $data['total_tokens'] ?? 0, [
            'provider' => $data['provider'],
        ]);
    }

    if ($event === 'request.failed') {
        $statsd->increment('ai.errors', 1, [
            'provider' => $data['provider'],
            'error_type' => $data['error_type'],
        ]);
    }
});
```
