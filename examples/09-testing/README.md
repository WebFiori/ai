# 09 — Testing

Demonstrates how to test code that uses the AI library without hitting real APIs.

## What It Demonstrates

- Using `FakeHttpClient` to mock provider responses
- Pre-queuing responses for predictable tests
- Asserting on request format (headers, body, URL)
- Testing streaming with fake chunks
- Testing error scenarios

## Files

| File | Description |
|------|-------------|
| `ExampleTest.php` | PHPUnit test class demonstrating testing patterns |

## Running

```bash
cd /path/to/project
vendor/bin/phpunit examples/09-testing/ExampleTest.php
```

## Key Concepts

The `FakeHttpClient` class is the testing backbone:

```php
$client = new FakeHttpClient();

// Queue responses (returned in order)
$client->addResponse(new HttpResponse(200, [], '{"choices":[...]}'));

// Inject into provider
$provider = new OpenAIProvider(['api_key' => 'test']);
$provider->setHttpClient($client);

// Make calls — no network traffic
$response = $provider->chat([...]);

// Assert on what was sent
$request = $client->getLastRequest();
$this->assertEquals('POST', $request->getMethod());
```
