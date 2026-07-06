<?php

/**
 * Example 08: Error Handling Patterns
 *
 * Run: php examples/08-error-handling/errors.php
 *
 * Demonstrates catching and handling different exception types.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Exception\AiException;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;

// --- Example 1: Invalid configuration ---
echo "=== Example 1: Invalid Configuration ===\n";

try {
    $provider = new OpenAIProvider([]); // Missing api_key
} catch (InvalidConfigException $e) {
    echo "Config error: ".$e->getMessage()."\n";
    echo "Option: ".$e->getOptionName()."\n";
}

echo "\n";

// --- Example 2: Catching specific error types ---
echo "=== Example 2: Specific Error Handling ===\n";

$provider = new OpenAIProvider([
    'api_key' => 'sk-invalid-key-for-demo',
]);

try {
    $provider->chat([new Message('user', 'Hello')]);
} catch (RateLimitException $e) {
    // Handle rate limiting — wait and retry
    echo "Rate limited! Wait ".$e->getRetryAfterSeconds()." seconds.\n";
    // sleep($e->getRetryAfterSeconds());
    // Retry the request...
} catch (AuthenticationException $e) {
    // Handle auth errors — check API key
    echo "Auth error (HTTP ".$e->getStatusCode()."): ".$e->getMessage()."\n";
    echo "Action: Check your API key configuration.\n";
} catch (ProviderException $e) {
    // Handle other provider errors
    echo "Provider error (HTTP ".$e->getStatusCode()."): ".$e->getMessage()."\n";

    if ($e->getProviderErrorCode()) {
        echo "Error code: ".$e->getProviderErrorCode()."\n";
    }
} catch (AiException $e) {
    // Catch-all for any library exception
    echo "AI library error: ".$e->getMessage()."\n";
}

echo "\n";

// --- Example 3: Retry pattern ---
echo "=== Example 3: Simple Retry Pattern ===\n";

function chatWithRetry(OpenAIProvider $provider, array $messages, int $maxRetries = 3): ?string {
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $response = $provider->chat($messages);

            return $response->getMessage()->getContent();
        } catch (RateLimitException $e) {
            $wait = $e->getRetryAfterSeconds() ?? ($attempt * 2);
            echo "  Attempt $attempt: Rate limited, waiting {$wait}s...\n";
            sleep($wait);
        } catch (ProviderException $e) {
            if ($e->getStatusCode() >= 500) {
                echo "  Attempt $attempt: Server error, retrying...\n";
                sleep($attempt);
            } else {
                throw $e; // Client errors are not retryable
            }
        }
    }

    echo "  All $maxRetries attempts failed.\n";

    return null;
}

echo "Retry pattern defined (would execute with a valid API key).\n";

echo "\n";

// --- Example 4: Logging callback for debugging ---
echo "=== Example 4: Debug Logging ===\n";

$provider = new OpenAIProvider([
    'api_key' => getenv('OPENAI_API_KEY') ?: 'sk-demo',
]);

$provider->setLogCallback(function (string $level, string $message, array $context)
{
    $contextStr = !empty($context) ? ' '.json_encode($context) : '';
    echo "  [$level] $message$contextStr\n";
});

echo "Log callback configured. All requests will be logged.\n";
echo "Example log output on a request:\n";
echo "  [info] Chat request started {\"provider\":\"openai\",\"model\":\"gpt-4o\",\"message_count\":1}\n";
echo "  [debug] HTTP request {\"method\":\"POST\",\"url\":\"https://api.openai.com/v1/chat/completions\"}\n";
echo "  [debug] HTTP response {\"status_code\":200}\n";
echo "  [info] Chat request completed {\"duration_ms\":1234,\"total_tokens\":39}\n";
