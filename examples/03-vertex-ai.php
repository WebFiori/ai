<?php

/**
 * Example: Chat Completion with GCP Vertex AI (Gemini)
 *
 * Demonstrates how to use the Vertex AI provider with Gemini models.
 * Shows the same interface working with a different provider.
 */
require_once __DIR__.'/../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\VertexAI\VertexAIProvider;

$provider = new VertexAIProvider([
    'project_id' => getenv('GCP_PROJECT_ID'),
    'location' => getenv('GCP_LOCATION') ?: 'us-central1',
    'model' => 'gemini-1.5-pro',
    'access_token' => getenv('GCP_ACCESS_TOKEN'),
    // Or use service account credentials:
    // 'credentials' => '/path/to/service-account.json',
]);

$response = $provider->chat([
    new Message('system', 'You are a helpful assistant. Keep responses concise.'),
    new Message('user', 'What is PHP in one sentence?'),
]);

echo 'Response: '.$response->getMessage()->getContent().PHP_EOL;
echo 'Model: '.$response->getModel().PHP_EOL;

if ($response->getUsage() !== null) {
    echo 'Tokens used: '.$response->getUsage()->getTotalTokens().PHP_EOL;
}
