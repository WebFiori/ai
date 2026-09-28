<?php

/**
 * Example: Reuse RedactionService rules for offset-based detection (#165)
 *
 * Run: php examples/14-pii-redaction/detect-live.php
 *
 * Demonstrates the "detect, don't just redact" use case against a LIVE API:
 *   1. Send a real chat() request whose prompt contains PII.
 *   2. Use RedactionService::detect() to find WHERE each PII item is and WHICH
 *      rule matched — on BOTH the user prompt and the model's response — so a
 *      downstream trust layer can build findings {rule, start, end, value}
 *      while reusing the library's built-in rules as the single source of truth.
 *
 * Keys are read from the repo `keys/` folder or the environment — never
 * hard-coded.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Redaction\RedactionConfig;
use WebFiori\Ai\Redaction\RedactionMatch;
use WebFiori\Ai\Redaction\RedactionService;

// ─── Resolve API key without embedding it in source ──────────────────────────
$keysDir = __DIR__.'/../../keys';
$apiKey = getenv('OPENAI_API_KEY') ?: null;

if ($apiKey === null && is_readable($keysDir.'/OpenAIKey.txt')) {
    $apiKey = trim((string) file_get_contents($keysDir.'/OpenAIKey.txt'));
}

if (!$apiKey) {
    fwrite(STDERR, "No OpenAI API key found. Set OPENAI_API_KEY or add keys/OpenAIKey.txt.\n");
    exit(1);
}

$model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';

$client = new OpenAIClient(new OpenAIClientConfig(apiKey: $apiKey, model: $model));

// Detector reuses the library's built-in rules — no pattern duplication.
$detector = new RedactionService(new RedactionConfig());

/**
 * Prints findings for a piece of text using detect().
 */
$report = function (string $label, string $text) use ($detector): void
{
    echo PHP_EOL.$label.PHP_EOL;
    echo '  text: '.$text.PHP_EOL;

    $matches = $detector->detect($text);

    if ($matches === []) {
        echo '  (no PII detected)'.PHP_EOL;

        return;
    }

    foreach ($matches as $m) {
        /** @var RedactionMatch $m */
        printf(
            "  • %-12s [%d..%d] \"%s\" → %s\n",
            $m->getRule(),
            $m->getStart(),
            $m->getEnd(),
            $m->getValue(),
            $m->getReplacement()
        );
    }
};

// ─── Live request whose prompt contains PII ──────────────────────────────────
$prompt = 'My email is jane.doe@example.com and my card is 4111111111111111. '
        .'Reply with a short confirmation and repeat my email back to me.';

echo '=== Detect PII on live chat I/O (#165) ==='.PHP_EOL;

$response = $client->chat([
    new Message('system', 'You are a concise assistant.'),
    new Message('user', $prompt),
]);

$reply = $response->getMessage()->getContent();

// 1) Detect on the user prompt (input-trust layer).
$report('User prompt findings:', $prompt);

// 2) Detect on the model response (output scanning).
$report('Model response findings:', $reply);

echo PHP_EOL.'Done.'.PHP_EOL;
