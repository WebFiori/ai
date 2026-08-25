<?php

/**
 * Example: Basic chat with AWS Bedrock (Claude)
 *
 * Supports two authentication modes:
 *
 * API key mode:
 *   BEDROCK_API_KEY=your-key php examples/05-bedrock/chat.php
 *
 * SigV4 mode:
 *   AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... AWS_REGION=us-east-1 php examples/05-bedrock/chat.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;


$apiKey = getenv('BEDROCK_API_KEY');
$region = getenv('AWS_REGION') ?: 'us-east-1';

if ($apiKey) {
    // API key authentication (simpler)
    $config = new BedrockClientConfig(
        region: $region,
        model: getenv('BEDROCK_MODEL') ?: 'us.amazon.nova-lite-v1:0',
        apiKey: $apiKey,
    );
} else {
    // SigV4 authentication
    $config = new BedrockClientConfig(
        region: $region,
        model: getenv('BEDROCK_MODEL') ?: 'us.amazon.nova-lite-v1:0',
        accessKey: getenv('AWS_ACCESS_KEY_ID') ?: null,
        secretKey: getenv('AWS_SECRET_ACCESS_KEY') ?: null,
    );
}

$client = new BedrockClient($config);

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
