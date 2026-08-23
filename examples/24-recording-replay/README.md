# Response Recording & Replay

Record real AI API responses once, replay them in tests without API keys.

## Quick Start

**Step 1: Record** (run once with a real API key)
```bash
OPENAI_API_KEY=sk-... php examples/24-recording-replay/record.php
```

**Step 2: Commit** the `fixtures/` directory alongside your code.

**Step 3: Replay** in tests — no API key needed:
```bash
php examples/24-recording-replay/replay.php
```

## How It Works

```php
// Recording mode — wraps real HTTP client
$provider->setHttpClient(new RecordingHttpClient(
    inner: new CurlHttpClient(),
    path: __DIR__ . '/fixtures',
));
$provider->chat($messages); // Real API call, saved to fixtures/

// Replay mode — no real API calls
$provider->setHttpClient(new ReplayHttpClient(__DIR__ . '/fixtures'));
$response = $provider->chat($messages); // From fixture
```

## Fixture Files

Fixtures are JSON files saved in the configured directory:

```json
{
  "name": "openai_completions_a1b2c3d4",
  "fingerprint": "sha256:abc...",
  "recorded_at": "2026-08-23T14:00:00Z",
  "streaming": false,
  "response": {
    "status": 200,
    "headers": {},
    "body": { "id": "chatcmpl-...", "choices": [...] }
  }
}
```

You can rename fixture files freely — matching uses the `fingerprint` inside, not the filename.

## Streaming Fixtures

Streaming responses store raw SSE chunks, exercising the real SSE parsing path:

```json
{
  "streaming": true,
  "chunks": [
    "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n",
    "data: [DONE]\n\n"
  ]
}
```

## Fingerprint Strategies

**Default** (`MessagesFingerprintStrategy`) — URL + messages array hash.
Generation params (`temperature`, `max_tokens`, etc.) are ignored.

```php
$replayer = new ReplayHttpClient($path); // default
```

**Strict** (`FullBodyFingerprintStrategy`) — full normalized body hash.
Every field affects matching.

```php
$replayer = new ReplayHttpClient($path, new FullBodyFingerprintStrategy());
```

## On Miss: Always Throws

```
FixtureNotFoundException: No fixture matched:
  Method: POST
  URL: https://api.openai.com/v1/chat/completions
  Fingerprint: sha256:abc123...
  Searched: /path/to/fixtures (3 fixtures)
  Hint: Use RecordingHttpClient to record this response, then commit the fixture file.
```

No silent fallback — your CI will never accidentally call a live API.

## Use In PHPUnit Tests

```php
class MyAITest extends TestCase {
    private OpenAIClient $client;

    protected function setUp(): void {
        $this->client = new OpenAIClient(new OpenAIClientConfig(
            apiKey: 'FAKE',
            model: 'gpt-4o-mini',
        ));
        $this->client->setHttpClient(
            new ReplayHttpClient(__DIR__ . '/fixtures')
        );
    }

    public function testChatResponse(): void {
        $response = $this->client->chat([
            new Message('user', 'What is PHP?'),
        ]);

        $this->assertStringContainsString('PHP', $response->getMessage()->getContent());
    }
}
```
