# Examples

Usage examples for the WebFiori AI library. Each example is self-contained in its own folder with a README explaining what it demonstrates and how to run it.

## Prerequisites

Install dependencies from the project root:

```bash
composer install
```

For Google/Gemini examples, see the [Google Cloud Setup Guide](GOOGLE_SETUP.md).

Set your API keys as environment variables:

```bash
export OPENAI_API_KEY="sk-..."
export GCP_PROJECT_ID="my-project"
export GCP_LOCATION="us-central1"
export GCP_ACCESS_TOKEN="ya29...."
```

## Examples

| # | Example | Description |
|---|---------|-------------|
| 01 | [Basic Chat](01-basic-chat/) | Simple chat completion with OpenAI |
| 02 | [Streaming](02-streaming/) | Real-time token-by-token streaming with a web UI |
| 03 | [Google (Gemini)](03-google/) | Chat with Google Gemini models |
| 04 | [Conversation](04-conversation/) | Multi-turn conversation with persistent history |
| 05 | [Embeddings](05-embeddings/) | Generate and compare text embeddings |
| 06 | [Image Generation](06-image-generation/) | Generate images from text prompts with a web UI |
| 07 | [Tool Calling](07-tool-calling/) | AI-invoked functions with a live demo |
| 08 | [Error Handling](08-error-handling/) | Error handling patterns and retry strategies |
| 09 | [Testing](09-testing/) | Using FakeHttpClient for unit testing |
