<?php

/**
 * Example: Basic chat with Anthropic Claude
 *
 * Run: php examples/04-anthropic/chat.php
 *
 * Requires: ANTHROPIC_API_KEY environment variable
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;


$client = new AnthropicClient([
    'api_key' => getenv('ANTHROPIC_API_KEY'),
    'model' => 'claude-sonnet-4-20250514',
]);

$response = $client->chat([
    new Message('system', 'You are a helpful assistant. Be concise.'),
    new Message('user', 'What is the capital of France?'),
]);

echo 'Response: '.$response->getMessage()->getContent().PHP_EOL;
echo 'Model: '.$response->getModel().PHP_EOL;
echo 'Finish reason: '.$response->getFinishReason().PHP_EOL;

if ($response->getUsage() !== null) {
    echo 'Input tokens: '.$response->getUsage()->getPromptTokens().PHP_EOL;
    echo 'Output tokens: '.$response->getUsage()->getCompletionTokens().PHP_EOL;
}
