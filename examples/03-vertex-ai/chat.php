<?php

/**
 * Example 03: Vertex AI Chat (CLI)
 *
 * Run: php examples/03-vertex-ai/chat.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\VertexAI\VertexAIClient;

$provider = new VertexAIClient([
    'project_id' => getenv('GCP_PROJECT_ID'),
    'location' => getenv('GCP_LOCATION') ?: 'us-central1',
    'model' => 'gemini-1.5-pro',
    'access_token' => getenv('GCP_ACCESS_TOKEN'),
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
