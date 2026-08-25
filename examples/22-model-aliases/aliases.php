<?php

/**
 * Example 22: Model Aliases
 *
 * Run: php examples/22-model-aliases/aliases.php
 *
 * Model aliases let you use logical names like 'fast' or 'smart' instead of
 * verbose, version-specific model identifiers. Each alias maps to a different
 * model per provider, so the same alias resolves correctly regardless of which
 * provider handles the request.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\ModelAliases;
use WebFiori\Ai\Provider\Fallback\FallbackProvider;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

// ─── Define aliases ───────────────────────────────────────────────────────────
// Map logical names to provider-specific model IDs.
// Each provider resolves 'fast'/'smart' to its own best match.

$aliases = new ModelAliases([
    'fast' => [
        'openai' => 'gpt-4o-mini',
        'google' => 'gemini-2.5-flash',
    ],
    'smart' => [
        'openai' => 'gpt-4o',
        'google' => 'gemini-2.5-pro',
    ],
    // String value = same model ID for all providers
    'embedding' => 'text-embedding-3-small',
]);

// ─── Use with a single provider ───────────────────────────────────────────────
$client = new GoogleClient(new GoogleClientConfig(
    credentials: getenv('GCP_CREDENTIALS') ?: '/path/to/service-account.json',
    projectId: 'webfiori',
    location: 'us-central1',
    api: GoogleApi::VERTEX_AI,
));
$client->setModelAliases($aliases);

// Pass alias as the 'model' option — resolves to 'gemini-2.5-flash' for Google
$response = $client->chat(
    [new Message('user', 'What is PHP in one sentence?')],
    ['model' => 'fast']
);

echo 'Provider: google'.PHP_EOL;
echo 'Alias "fast" resolved to: '.$response->getModel().PHP_EOL;
echo 'Response: '.$response->getMessage()->getContent().PHP_EOL.PHP_EOL;

// ─── Use with FallbackProvider ────────────────────────────────────────────────
// Each provider in the fallback chain resolves the alias independently.
// 'smart' → 'gpt-4o' for OpenAI, 'gemini-2.5-pro' for Google.

$openai = new OpenAIClient(new OpenAIClientConfig(
    apiKey: getenv('OPENAI_API_KEY') ?: 'sk-...',
));
$openai->setModelAliases($aliases);

$google = new GoogleClient(new GoogleClientConfig(
    credentials: getenv('GCP_CREDENTIALS') ?: '/path/to/service-account.json',
    projectId: 'webfiori',
    location: 'us-central1',
    api: GoogleApi::VERTEX_AI,
));
$google->setModelAliases($aliases);

$fallback = new FallbackProvider([$openai, $google]);

$response = $fallback->chat(
    [new Message('user', 'What is PHP in one sentence?')],
    ['model' => 'smart']
);

echo 'FallbackProvider: used '.$fallback->getLastUsedProvider().PHP_EOL;
echo 'Alias "smart" resolved to: '.$response->getModel().PHP_EOL;
echo 'Response: '.$response->getMessage()->getContent().PHP_EOL.PHP_EOL;

// ─── Dynamic alias management ─────────────────────────────────────────────────
// Aliases are mutable — update model versions in one place.

$aliases->add('latest', [
    'openai' => 'gpt-4o-2025-01-01',
    'google' => 'gemini-3.5-flash',
]);

echo 'Added "latest" alias.'.PHP_EOL;
echo '"latest" for openai: '.$aliases->resolve('latest', 'openai').PHP_EOL;
echo '"latest" for google: '.$aliases->resolve('latest', 'google').PHP_EOL.PHP_EOL;

// ─── Fallback to literal model name ──────────────────────────────────────────
// If the alias is not found, the name is used as-is (no error thrown).

echo 'Unregistered alias "gpt-4o" resolves to: '.$aliases->resolve('gpt-4o', 'openai').PHP_EOL;
echo 'Unknown provider for "fast" resolves to: '.$aliases->resolve('fast', 'bedrock').PHP_EOL;
