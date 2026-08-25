<?php

/**
 * Example 03: Google Gemini Chat (CLI)
 *
 * Run: php examples/03-google/chat.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;


$provider = new GoogleClient(new GoogleClientConfig(
    model: getenv('GCP_MODEL') ?: 'gemini-2.5-flash',
    apiKey: getenv('GCP_API_KEY') ?: null,
    projectId: getenv('GCP_PROJECT_ID') ?: null,
    location: getenv('GCP_LOCATION') ?: 'global',
    credentials: getenv('GCP_CREDENTIALS') ?: '/path/to/service-account.json',
    accessToken: getenv('GCP_ACCESS_TOKEN') ?: null,
    api: getenv('GCP_API') === 'vertex_ai' ? GoogleApi::VERTEX_AI : GoogleApi::GEMINI,
));

$response = $provider->chat([
    new Message('system', 'You are a helpful assistant. Keep responses concise.'),
    new Message('user', 'What is PHP in one sentence?'),
]);

echo 'Response: '.$response->getMessage()->getContent().PHP_EOL;
echo 'Model: '.$response->getModel().PHP_EOL;
echo 'Finish reason: '.$response->getFinishReason().PHP_EOL;

if ($response->getUsage() !== null) {
    echo 'Tokens — Prompt: '.$response->getUsage()->getPromptTokens();
    echo ', Completion: '.$response->getUsage()->getCompletionTokens();
    echo ', Total: '.$response->getUsage()->getTotalTokens().PHP_EOL;
}
