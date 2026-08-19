# Google Interactions API

Chat with gemini-3.5-flash and newer models using the Interactions API — Google's next-generation API with structured step-based responses, built-in thinking, and stateless multi-turn support.

## Key Differences from generateContent

| | generateContent | Interactions API |
|---|---|---|
| Models | gemini-1.x, gemini-2.x | gemini-3.x+ |
| Response format | `candidates[].content.parts[]` | `steps[]` (text, thought, model_output) |
| Streaming | `/streamGenerateContent?alt=sse` | Same endpoint + `stream: true` in body |
| Thinking | Optional via config | Always included (as thought steps) |
| Conversation history | Full `contents[]` array | Full `input[]` array |
| Response ID | Not available | `interaction_id` for stateful mode |

## Auto-Detection

The library automatically selects the right API based on model name:

```php
// generateContent (auto-detected)
$client = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    credentials: '/path/to/key.json',
));

// Interactions API (auto-detected from gemini-3.x)
$client = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-3.5-flash',
    credentials: '/path/to/key.json',
));

// Manual override
$client = new GoogleClient(new GoogleClientConfig(
    model: 'gemini-2.5-flash',
    credentials: '/path/to/key.json',
    apiVersion: GoogleApiVersion::INTERACTIONS, // Force
));
```

## Stateless Multi-Turn

The library manages conversation history in stateless mode. Assistant messages carry `rawSteps` (all steps including thoughts) which are replayed in subsequent turns:

```php
$messages = [new Message('user', 'My name is Ibrahim.')];

$r1 = $client->chat($messages);

// Append with rawSteps intact for the next turn
$messages[] = $r1->getMessage();
$messages[] = new Message('user', 'What is my name?');

$r2 = $client->chat($messages);
// → "Your name is Ibrahim."
```

## Run

```bash
php examples/21-interactions-api/chat.php
```
