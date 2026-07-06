<?php

/**
 * Example 01: Basic Chat Completion (CLI)
 *
 * Run: php examples/01-basic-chat/chat.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;

$provider = new OpenAIProvider([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
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
