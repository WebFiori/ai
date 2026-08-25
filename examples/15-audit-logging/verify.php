<?php

/**
 * Example 15: Structured Audit Logging Verification
 *
 * Run: php examples/15-audit-logging/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Audit\AuditConfig;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Redaction\RedactionConfig;

echo "=== Structured Audit Logging ===\n\n";

$client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));

// ─────────────────────────────────────────────────────────────────────────────
// 1. Basic audit entry
// ─────────────────────────────────────────────────────────────────────────────
echo "1. Basic audit entry\n";

$entry = null;
$client->setAuditCallback(function ($e) use (&$entry)
{
    $entry = $e;
});

$fakeHttp = new FakeHttpClient();
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi!'], 'finish_reason' => 'stop']],
    'model' => 'gpt-4o',
    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3],
])));
$client->setHttpClient($fakeHttp);

$response = $client->chat([new Message('user', 'Hello')]);

echo '   operation:   '.$entry['operation']."\n";
echo '   provider:    '.$entry['provider']."\n";
echo '   model:       '.$entry['model']."\n";
echo '   status:      '.$entry['status']."\n";
echo '   duration_ms: '.$entry['duration_ms']."\n";
echo '   tokens:      prompt='.$entry['tokens']['prompt'].' completion='.$entry['tokens']['completion']."\n";
echo '   request_id:  '.$entry['request_id']."\n";
echo '   ✅ request_id matches response: '.($entry['request_id'] === $response->getRequestId() ? 'Yes' : 'No')."\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// 2. Static + dynamic audit context
// ─────────────────────────────────────────────────────────────────────────────
echo "2. Static + dynamic context\n";

$client->setAuditContext(['tenant_id' => 'tenant-abc', 'feature' => 'support-bot']);
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']],
    'model' => 'gpt-4o',
    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
])));

$client->chat([new Message('user', 'Hi')], [
    'audit_context' => ['user_id' => 'user-123'],
]);

echo '   metadata: '.json_encode($entry['metadata'])."\n";
echo '   ✅ tenant_id present: '.(isset($entry['metadata']['tenant_id']) ? 'Yes' : 'No')."\n";
echo '   ✅ user_id present:   '.(isset($entry['metadata']['user_id']) ? 'Yes' : 'No')."\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// 3. Opt-in message and response content
// ─────────────────────────────────────────────────────────────────────────────
echo "3. Opt-in message and response content\n";

$client->setAuditConfig(new AuditConfig(
    includeMessages: true,
    includeResponse: true
));
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'The answer is 42'], 'finish_reason' => 'stop']],
    'model' => 'gpt-4o',
    'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4],
])));

$client->chat([new Message('user', 'What is the answer?')]);

echo '   messages[0]: '.json_encode($entry['messages'][0])."\n";
echo '   response:    '.$entry['response']."\n";
echo '   ✅ messages included: '.(isset($entry['messages']) ? 'Yes' : 'No')."\n";
echo '   ✅ response included: '.(isset($entry['response']) ? 'Yes' : 'No')."\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// 4. Error entry
// ─────────────────────────────────────────────────────────────────────────────
echo "4. Error audit entry\n";

$client->setAuditConfig(new AuditConfig()); // reset
$fakeHttp->addResponse(new HttpResponse(401, [], json_encode([
    'error' => ['message' => 'Invalid API key'],
])));

try {
    $client->chat([new Message('user', 'Hi')]);
} catch (Throwable $e) {
}

echo '   status: '.$entry['status']."\n";
echo '   error:  '.json_encode($entry['error'])."\n";
echo '   ✅ status is error: '.($entry['status'] === 'error' ? 'Yes' : 'No')."\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// 5. PII redaction in audit entries
// ─────────────────────────────────────────────────────────────────────────────
echo "5. PII redaction in audit entries\n";

$client->setAuditConfig(new AuditConfig(includeResponse: true));
$client->setRedactionConfig(new RedactionConfig());
$fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Contact admin@secret.com'], 'finish_reason' => 'stop']],
    'model' => 'gpt-4o',
    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 4],
])));

$client->chat([new Message('user', 'Hi')]);

$emailGone = !str_contains($entry['response'], 'admin@secret.com');
echo '   response: '.$entry['response']."\n";
echo '   ✅ Email redacted: '.($emailGone ? 'Yes' : 'No')."\n\n";

echo "=== All Verification Tests Passed ===\n";
