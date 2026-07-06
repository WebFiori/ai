<?php

/**
 * Example 01: Basic Chat Completion (Web)
 *
 * Start: php -S localhost:8080 -t examples/01-basic-chat
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

$response = null;
$error = null;
$userMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $userMessage = trim($_POST['message']);

    try {
        $provider = new OpenAIClient([
            'api_key' => getenv('OPENAI_API_KEY'),
            'model' => 'gpt-4o',
        ]);

        $response = $provider->chat([
            new Message('system', 'You are a helpful assistant. Keep responses concise.'),
            new Message('user', $userMessage),
        ]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basic Chat — WebFiori AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; color: #333; }
        h1 { margin-bottom: 8px; }
        p.subtitle { color: #666; margin-bottom: 24px; }
        form { display: flex; gap: 8px; margin-bottom: 24px; }
        input[type="text"] { flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; }
        button { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .result { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .result h3 { margin-bottom: 8px; color: #1e40af; }
        .result pre { white-space: pre-wrap; word-wrap: break-word; }
        .meta { font-size: 14px; color: #64748b; margin-top: 12px; }
        .error { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    </style>
</head>
<body>
    <h1>Basic Chat</h1>
    <p class="subtitle">Send a message to GPT-4o and see the response.</p>

    <form method="POST">
        <input type="text" name="message" placeholder="Ask something..." value="<?= htmlspecialchars($userMessage) ?>" autofocus>
        <button type="submit">Send</button>
    </form>

    <?php if ($error) { ?>
        <div class="result error">
            <h3>Error</h3>
            <pre><?= htmlspecialchars($error) ?></pre>
        </div>
    <?php } elseif ($response) { ?>
        <div class="result">
            <h3>Response</h3>
            <pre><?= htmlspecialchars($response->getMessage()->getContent()) ?></pre>
            <div class="meta">
                Model: <?= htmlspecialchars($response->getModel()) ?> |
                Finish: <?= htmlspecialchars($response->getFinishReason() ?? 'n/a') ?>
                <?php if ($response->getUsage()) { ?>
                    | Tokens: <?= $response->getUsage()->getTotalTokens() ?>
                    (prompt: <?= $response->getUsage()->getPromptTokens() ?>,
                    completion: <?= $response->getUsage()->getCompletionTokens() ?>)
                <?php } ?>
            </div>
        </div>
    <?php } ?>
</body>
</html>
