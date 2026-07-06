<?php

/**
 * Example 04: Multi-turn Conversation (CLI)
 *
 * Run: php examples/04-conversation/chat.php
 * Type messages and the AI remembers context. Type "quit" to exit.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Conversation\Conversation;
use WebFiori\Ai\Conversation\InMemoryStorage;
use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;

$provider = new OpenAIProvider([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

$storage = new InMemoryStorage();
$conversation = new Conversation($provider, $storage, 'cli-session');
$conversation->setSystemMessage('You are a helpful assistant. Keep responses concise.');
$conversation->setMaxHistory(20);

echo 'Chat started. Type "quit" to exit.'.PHP_EOL;
echo '---'.PHP_EOL;

while (true) {
    echo PHP_EOL.'You: ';
    $input = trim(fgets(STDIN));

    if ($input === 'quit' || $input === 'exit') {
        echo 'Goodbye!'.PHP_EOL;
        break;
    }

    if ($input === '') {
        continue;
    }

    $response = $conversation->send($input);
    echo 'AI: '.$response->getMessage()->getContent().PHP_EOL;
}
