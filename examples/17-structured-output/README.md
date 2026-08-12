# 17 — Structured Output / JSON Mode

Force the AI to respond with valid JSON, optionally validated against a schema.

## What It Demonstrates

- `json_mode` — unstructured JSON output
- `json_schema` — structured JSON with schema validation
- Extracting structured data from free-form text

## Provider Support

| Provider | json_mode | json_schema |
|----------|-----------|-------------|
| Google (Gemini) | ✅ Native | ✅ Native (responseSchema) |
| OpenAI (GPT-4o) | ✅ Native | ✅ Native (json_schema) |
| Anthropic (Claude) | ✅ Prompt-based | ✅ Prompt-based |

## Usage

### Simple JSON Mode

```php
$response = $client->chat($messages, ['json_mode' => true]);
$data = json_decode($response->getMessage()->getContent(), true);
```

### JSON with Schema

```php
$response = $client->chat($messages, [
    'json_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age'  => ['type' => 'integer'],
        ],
        'required' => ['name', 'age'],
    ],
]);

$data = json_decode($response->getMessage()->getContent(), true);
echo $data['name']; // Always a string
echo $data['age'];  // Always an integer
```

## Running

```bash
php examples/17-structured-output/json_mode.php
```
