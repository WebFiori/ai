<?php

/**
 * Example 02: Streaming Chat (CLI)
 *
 * Run: php examples/02-streaming/stream.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;

$provider = new OpenAIProvider([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

echo 'Streaming: ';

$provider->streamChat(
    messages: [
        new Message('user', 'Write a short poem about PHP programming.'),
    ],
    onToken: function (string $token) {
        echo $token;
    },
    onComplete: function ($response) {
        echo PHP_EOL.PHP_EOL.'--- Stream complete ---'.PHP_EOL;
        echo 'Model: '.$response->getModel().PHP_EOL;
        echo 'Finish reason: '.$response->getFinishReason().PHP_EOL;
    },
    onError: function ($e) {
        echo PHP_EOL.'Error: '.$e->getMessage().PHP_EOL;
    }
);
