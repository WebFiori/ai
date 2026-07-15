<?php

/**
 * Example 03: Provider Comparison (Web)
 *
 * Sends the same prompt to OpenAI and Google side by side.
 * Start: php -S localhost:8080 -t examples/03-google
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

$openaiResponse = null;
$googleResponse = null;
$error = null;
$userMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $userMessage = trim($_POST['message']);

    $messages = [
        new Message('system', 'You are a helpful assistant. Keep responses concise.'),
        new Message('user', $userMessage),
    ];

    try {
        if (getenv('OPENAI_API_KEY')) {
            $openai = new OpenAIClient([
                'api_key' => getenv('OPENAI_API_KEY'),
                'model' => 'gpt-4o',
            ]);
            $openaiResponse = $openai->chat($messages);
        }
    } catch (Throwable $e) {
        $error = 'OpenAI: '.$e->getMessage();
    }

    try {
        $credentialsFile = __DIR__.'/../../vertex-ai-key.json';

        if (getenv('GCP_ACCESS_TOKEN') || getenv('GCP_CREDENTIALS') || file_exists($credentialsFile)) {
            $google = new GoogleClient([
                'api' => getenv('GCP_API') ?: 'gemini',
                'project_id' => getenv('GCP_PROJECT_ID') ?: null,
                'location' => getenv('GCP_LOCATION') ?: 'us-central1',
                'model' => getenv('GCP_MODEL') ?: 'gemini-2.5-flash',
                'credentials' => getenv('GCP_CREDENTIALS') ?: $credentialsFile,
                'access_token' => getenv('GCP_ACCESS_TOKEN') ?: null,
            ]);
            $googleResponse = $google->chat($messages);
        }
    } catch (Throwable $e) {
        $error = ($error ? $error.' | ' : '').'Google: '.$e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Comparison — WebFiori AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; color: #333; }
        h1 { margin-bottom: 8px; }
        p.subtitle { color: #666; margin-bottom: 24px; }
        form { display: flex; gap: 8px; margin-bottom: 24px; }
        input[type="text"] { flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; }
        button { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
        .card h3 { margin-bottom: 8px; color: #1e40af; }
        .card pre { white-space: pre-wrap; word-wrap: break-word; font-size: 14px; }
        .meta { font-size: 12px; color: #64748b; margin-top: 10px; }
        .error { background: #fef2f2; border-color: #fecaca; color: #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .na { color: #94a3b8; font-style: italic; }
    </style>
</head>
<body>
    <h1>Provider Comparison</h1>
    <p class="subtitle">Same prompt, different providers. Compare OpenAI GPT-4o vs Google Gemini 2.5 Flash.</p>

    <form method="POST">
        <input type="text" name="message" placeholder="Ask something..." value="<?= htmlspecialchars($userMessage) ?>" autofocus>
        <button type="submit">Compare</button>
    </form>

    <?php if ($error) { ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php } ?>

    <?php if ($openaiResponse || $googleResponse) { ?>
    <div class="grid">
        <div class="card">
            <h3>OpenAI (GPT-4o)</h3>
            <?php if ($openaiResponse) { ?>
                <pre><?= htmlspecialchars($openaiResponse->getMessage()->getContent()) ?></pre>
                <div class="meta">
                    Model: <?= htmlspecialchars($openaiResponse->getModel()) ?>
                    <?php if ($openaiResponse->getUsage()) { ?>
                        | Tokens: <?= $openaiResponse->getUsage()->getTotalTokens() ?>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p class="na">Set OPENAI_API_KEY to enable.</p>
            <?php } ?>
        </div>
        <div class="card">
            <h3>Google (Gemini 2.5 Flash)</h3>
            <?php if ($googleResponse) { ?>
                <pre><?= htmlspecialchars($googleResponse->getMessage()->getContent()) ?></pre>
                <div class="meta">
                    Model: <?= htmlspecialchars($googleResponse->getModel()) ?>
                    <?php if ($googleResponse->getUsage()) { ?>
                        | Tokens: <?= $googleResponse->getUsage()->getTotalTokens() ?>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p class="na">Set GCP_CREDENTIALS or place vertex-ai-key.json in project root.</p>
            <?php } ?>
        </div>
    </div>
    <?php } ?>
</body>
</html>
