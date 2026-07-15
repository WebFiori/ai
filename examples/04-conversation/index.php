<?php

/**
 * Example 04: Multi-turn Conversation (Web)
 *
 * Start: php -S localhost:8080 -t examples/04-conversation
 * Uses PHP sessions to persist conversation history across requests.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Conversation\Conversation;
use WebFiori\Ai\Conversation\InMemoryStorage;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;

session_start();

// Initialize storage from session
$storage = new InMemoryStorage();

if (isset($_SESSION['history'])) {
    $messages = [];

    foreach ($_SESSION['history'] as $msg) {
        $messages[] = new Message($msg['role'], $msg['content']);
    }
    $storage->save('web-session', $messages);
}

// Handle reset
if (isset($_POST['reset'])) {
    $_SESSION['history'] = [];
    header('Location: index.php');
    exit;
}

$error = null;

// Handle new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    try {
        $provider = new GoogleClient([
            'api' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'credentials' => __DIR__.'/../../vertex-ai-key.json',
        ]);

        $conversation = new Conversation($provider, $storage, 'web-session');
        $conversation->setSystemMessage('You are a helpful assistant.');
        $conversation->setMaxHistory(20);

        $response = $conversation->send(trim($_POST['message']));

        // Save history to session
        $_SESSION['history'] = [];

        foreach ($conversation->getHistory() as $msg) {
            $_SESSION['history'][] = ['role' => $msg->getRole(), 'content' => $msg->getContent()];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$history = $_SESSION['history'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversation — WebFiori AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; color: #333; }
        h1 { margin-bottom: 8px; }
        p.subtitle { color: #666; margin-bottom: 24px; }
        .messages { max-height: 500px; overflow-y: auto; margin-bottom: 16px; }
        .message { padding: 10px 14px; margin-bottom: 8px; border-radius: 8px; }
        .message.user { background: #dbeafe; margin-left: 40px; }
        .message.assistant { background: #f1f5f9; margin-right: 40px; }
        .message .role { font-size: 12px; font-weight: bold; color: #64748b; margin-bottom: 4px; text-transform: uppercase; }
        .message .content { white-space: pre-wrap; word-wrap: break-word; }
        .input-row { display: flex; gap: 8px; }
        input[type="text"] { flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; }
        button { padding: 10px 16px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .reset-btn { background: #ef4444; margin-left: 8px; }
        .reset-btn:hover { background: #dc2626; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .empty { color: #94a3b8; text-align: center; padding: 40px; }
    </style>
</head>
<body>
    <h1>Conversation</h1>
    <p class="subtitle">Multi-turn chat with conversation history. The AI remembers what you said.</p>

    <?php if ($error) { ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php } ?>

    <div class="messages">
        <?php if (empty($history)) { ?>
            <div class="empty">Start a conversation by typing a message below.</div>
        <?php } else { ?>
            <?php foreach ($history as $msg) { ?>
                <div class="message <?= htmlspecialchars($msg['role']) ?>">
                    <div class="role"><?= htmlspecialchars($msg['role']) ?></div>
                    <div class="content"><?= htmlspecialchars($msg['content']) ?></div>
                </div>
            <?php } ?>
        <?php } ?>
    </div>

    <form method="POST" class="input-row">
        <input type="text" name="message" placeholder="Type your message..." autofocus>
        <button type="submit">Send</button>
        <button type="submit" name="reset" value="1" class="reset-btn">Reset</button>
    </form>
</body>
</html>
