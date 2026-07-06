<?php

/**
 * Example 03: Vertex AI Chat (CLI)
 *
 * Run: php examples/03-vertex-ai/chat.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;

$provider = new GoogleClient([
    'api' => getenv('GCP_API') ?: 'gemini',
    'project_id' => getenv('GCP_PROJECT_ID') ?: null,
    'location' => getenv('GCP_LOCATION') ?: 'us-central1',
    'model' => getenv('GCP_MODEL') ?: 'gemini-2.5-flash',
    'credentials' => getenv('GCP_CREDENTIALS') ?: __DIR__.'/../../vertex-ai-key.json',
    'access_token' => getenv('GCP_ACCESS_TOKEN') ?: null,
]);

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
