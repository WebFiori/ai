<?php

/**
 * Example 14: PII Redaction
 *
 * Demonstrates all redaction features: built-in rules, disabling rules,
 * custom rules, and provider integration.
 *
 * Run: php examples/14-pii-redaction/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Redaction\RedactionConfig;
use WebFiori\Ai\Redaction\RedactionRule;
use WebFiori\Ai\Redaction\RedactionService;

echo "=== PII Redaction Examples ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Built-in rules (all active by default)
// ─────────────────────────────────────────────────────────────────────────────
echo "1. Built-in rules\n";
$service = new RedactionService(new RedactionConfig());

$tests = [
    ['Bearer ya29.secrettoken123',          '[TOKEN]',      'Bearer token'],
    ['key=AKIAIOSFODNN7EXAMPLE',            '[API_KEY]',    'AWS access key'],
    ['user@example.com',                    '[EMAIL]',      'Email (GDPR, PDPL)'],
    ['Call 555-867-5309',                   '[PHONE]',      'Phone (GDPR, PDPL)'],
    ['Card 4111111111111111',               '[CC]',         'Credit card (PCI-DSS)'],
    ['SSN: 123-45-6789',                    '[SSN]',        'SSN (HIPAA)'],
    ['IP: 192.168.1.100',                   '[IP]',         'IPv4 (GDPR, PDPL)'],
    ['IP: 2001:0db8:85a3:0000:0000:8a2e:0370:7334', '[IP]', 'IPv6 (GDPR, PDPL)'],
    ['National ID: 1234567890',             '[NATIONAL_ID]','Saudi ID (PDPL)'],
    ['IBAN: SA0380000000608010167519',       '[IBAN]',       'IBAN (GDPR, PDPL)'],
];

foreach ($tests as [$input, $expectedToken, $label]) {
    $result = $service->redactString($input);
    $ok = str_contains($result, $expectedToken) && !str_contains($result, $input);
    echo '   '.($ok ? '✅' : '❌')." {$label}\n";
    echo "      Before: \"{$input}\"\n";
    echo "      After:  \"{$result}\"\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Disabling a built-in rule
// ─────────────────────────────────────────────────────────────────────────────
echo "\n2. Disabling built-in rules\n";

$service = new RedactionService(new RedactionConfig(
    disabledRules: ['email', 'phone'] // keep email and phone in logs for debugging
));

$result = $service->redactString('Contact john@example.com or 555-111-2222');
echo '   '.(str_contains($result, 'john@example.com') ? '✅' : '❌')." Email preserved\n";
echo '   '.(str_contains($result, '555-111-2222') ? '✅' : '❌')." Phone preserved\n";
echo "   Result: \"{$result}\"\n";

// ─────────────────────────────────────────────────────────────────────────────
// 3. Mandatory rules cannot be disabled
// ─────────────────────────────────────────────────────────────────────────────
echo "\n3. Mandatory rules (api_key, bearer_token) cannot be disabled\n";

$service = new RedactionService(new RedactionConfig(
    disabledRules: ['api_key', 'bearer_token'] // silently ignored
));

$result = $service->redactString('Authorization: Bearer mysupersecrettoken1234');
echo '   '.(!str_contains($result, 'mysupersecrettoken1234') ? '✅' : '❌')." Bearer token still redacted\n";
echo "   Result: \"{$result}\"\n";

// ─────────────────────────────────────────────────────────────────────────────
// 4. Adding custom rules
// ─────────────────────────────────────────────────────────────────────────────
echo "\n4. Custom rules\n";

$service = new RedactionService(new RedactionConfig(
    customRules: [
        new RedactionRule(
            name: 'passport',
            pattern: '/\b[A-Z]{1,2}\d{6,9}\b/',
            replacement: '[PASSPORT]'
        ),
        new RedactionRule(
            name: 'employee_id',
            pattern: '/\bEMP-\d{5}\b/',
            replacement: '[EMP_ID]'
        ),
    ]
));

$tests = [
    ['Passport: AB1234567', '[PASSPORT]', 'Passport number'],
    ['Employee: EMP-00123', '[EMP_ID]', 'Employee ID'],
];

foreach ($tests as [$input, $expectedToken, $label]) {
    $result = $service->redactString($input);
    $ok = str_contains($result, $expectedToken);
    echo '   '.($ok ? '✅' : '❌')." {$label}: \"{$input}\" → \"{$result}\"\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. Combining built-ins with custom rules
// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. Combining built-in + custom rules\n";

$service = new RedactionService(new RedactionConfig(
    disabledRules: ['phone'],                    // disable built-in phone
    customRules: [
        new RedactionRule(                        // add custom phone format
            name: 'sa_phone',
            pattern: '/\b05\d{8}\b/',            // Saudi mobile: 05xxxxxxxx
            replacement: '[PHONE]'
        ),
    ]
));

$text = 'Call +1 555-123-4567 or 0512345678';
$result = $service->redactString($text);
echo "   Input:  \"{$text}\"\n";
echo "   Output: \"{$result}\"\n";
echo '   '.(str_contains($result, '555-123-4567') ? '✅' : '❌')." US phone preserved (built-in disabled)\n";
echo '   '.(!str_contains($result, '0512345678') ? '✅' : '❌')." Saudi phone redacted (custom rule)\n";

// ─────────────────────────────────────────────────────────────────────────────
// 6. Request/response body redaction (opt-in)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n6. Body redaction (opt-in)\n";

$service = new RedactionService(new RedactionConfig(
    redactRequestBodies: true,
    redactResponseBodies: true
));

$context = [
    'provider' => 'openai',
    'model' => 'gpt-4o',
    'latency_ms' => 250,
    'body' => 'User message with sensitive data: john@example.com',
    'response' => 'AI reply mentioning sensitive data',
];

$result = $service->redactContext($context);
echo '   '.($result['body'] === '[REDACTED]' ? '✅' : '❌')." Request body fully redacted\n";
echo '   '.($result['response'] === '[REDACTED]' ? '✅' : '❌')." Response body fully redacted\n";
echo '   '.($result['provider'] === 'openai' ? '✅' : '❌')." Metadata (provider) preserved\n";
echo '   '.($result['model'] === 'gpt-4o' ? '✅' : '❌')." Metadata (model) preserved\n";
echo '   '.($result['latency_ms'] === 250 ? '✅' : '❌')." Numeric values preserved\n";

// ─────────────────────────────────────────────────────────────────────────────
// 7. Provider integration
// ─────────────────────────────────────────────────────────────────────────────
echo "\n7. Provider integration (logs + metrics)\n";

$client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
$client->setRedactionConfig(new RedactionConfig(
    customRules: [
        new RedactionRule('employee_id', '/\bEMP-\d{5}\b/', '[EMP_ID]'),
    ]
));

$logContext = [];
$client->setLogCallback(function ($level, $message, $context) use (&$logContext)
{
    $logContext[] = json_encode($context);
});

$fakeHttp = new FakeHttpClient();
$fakeHttp->addResponse(new HttpResponse(400, [], json_encode([
    'error' => ['message' => 'Error processing request for user@secret.com (EMP-00999)'],
])));
$client->setHttpClient($fakeHttp);

try {
    $client->chat([new Message('user', 'Hello')]);
} catch (Throwable $e) {
}

$allLogs = implode(' ', $logContext);
echo '   '.(!str_contains($allLogs, 'user@secret.com') ? '✅' : '❌')." Email not in logs\n";
echo '   '.(!str_contains($allLogs, 'EMP-00999') ? '✅' : '❌')." Custom rule applied to error context\n";
echo '   '.(str_contains($allLogs, '[EMAIL]') || !str_contains($allLogs, '@') ? '✅' : '❌')." Email replaced with [EMAIL]\n";

echo "\n=== All Examples Complete ===\n";
