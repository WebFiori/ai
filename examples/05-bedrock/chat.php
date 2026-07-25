<?php

/**
 * Example: Basic chat with AWS Bedrock (Claude)
 *
 * Run: php examples/05-bedrock/chat.php
 *
 * Requires: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION environment variables
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;

$client = new BedrockClient([
    'access_key' => getenv('AWS_ACCESS_KEY_ID'),
    'secret_key' => getenv('AWS_SECRET_ACCESS_KEY'),
    'region' => getenv('AWS_REGION') ?: 'us-east-1',
    'model' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
]);

$response = $client->chat([
    new Message('system', 'You are a helpful assistant. Be concise.'),
    new Message('user', 'What is AWS Bedrock?'),
]);

echo 'Response: '.$response->getMessage()->getContent().PHP_EOL;
echo 'Model: '.$response->getModel().PHP_EOL;
echo 'Finish reason: '.$response->getFinishReason().PHP_EOL;

if ($response->getUsage() !== null) {
    echo 'Input tokens: '.$response->getUsage()->getPromptTokens().PHP_EOL;
    echo 'Output tokens: '.$response->getUsage()->getCompletionTokens().PHP_EOL;
}
