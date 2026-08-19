<?php

/**
 * Example: Streaming chat with Anthropic Claude
 *
 * Run: php examples/04-anthropic/stream.php
 *
 * Requires: ANTHROPIC_API_KEY environment variable
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;


$client = new AnthropicClient([
    'api_key' => getenv('ANTHROPIC_API_KEY'),
    'model' => 'claude-sonnet-4-20250514',
]);

echo 'Streaming response: ';

$client->streamChat(
    messages: [
        new Message('system', 'You are a helpful assistant.'),
        new Message('user', 'Write a haiku about programming.'),
    ],
    onToken: function (string $token)
    {
        echo $token;
        flush();
    },
    onComplete: function ($response)
    {
        echo PHP_EOL.PHP_EOL;
        echo 'Model: '.$response->getModel().PHP_EOL;
        echo 'Finish reason: '.$response->getFinishReason().PHP_EOL;

        if ($response->getUsage() !== null) {
            echo 'Input tokens: '.$response->getUsage()->getPromptTokens().PHP_EOL;
            echo 'Output tokens: '.$response->getUsage()->getCompletionTokens().PHP_EOL;
        }
    }
);
