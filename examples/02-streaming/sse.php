<?php

/**
 * Example 02: SSE Endpoint
 *
 * This file is called via JavaScript EventSource from index.php.
 * It streams tokens as Server-Sent Events to the browser.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$message = $_GET['message'] ?? '';

if (empty($message)) {
    echo "data: {\"error\":\"No message provided\"}\n\n";
    exit;
}

$provider = new OpenAIClient([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

$provider->streamChat(
    messages: [
        new Message('system', 'You are a helpful assistant.'),
        new Message('user', $message),
    ],
    onToken: function (string $token)
    {
        echo 'data: '.json_encode(['token' => $token])."\n\n";
        ob_flush();
        flush();
    },
    onComplete: function ($response)
    {
        $data = [
            'done' => true,
            'model' => $response->getModel(),
            'finish_reason' => $response->getFinishReason(),
        ];
        echo 'data: '.json_encode($data)."\n\n";
        ob_flush();
        flush();
    },
    onError: function ($e)
    {
        echo 'data: '.json_encode(['error' => $e->getMessage()])."\n\n";
        ob_flush();
        flush();
    }
);
