<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Redaction\RedactionConfig;
use WebFiori\Ai\Redaction\RedactionRule;
use WebFiori\Ai\Redaction\RedactionService;

/**
 * Tests for PII redaction functionality.
 */
class RedactionTest extends TestCase {
    // =========================================================================
    // RedactionRule tests
    // =========================================================================

    /**
     * @test
     */
    public function testRedactionRuleConstruction() {
        $rule = new RedactionRule('email', '/\b\w+@\w+\.\w+\b/i', '[EMAIL]');

        $this->assertSame('email', $rule->getName());
        $this->assertSame('/\b\w+@\w+\.\w+\b/i', $rule->getPattern());
        $this->assertSame('[EMAIL]', $rule->getReplacement());
    }

    // =========================================================================
    // RedactionConfig tests
    // =========================================================================

    /**
     * @test
     */
    public function testRedactionConfigDefaults() {
        $config = new RedactionConfig();

        $this->assertFalse($config->isRedactRequestBodies());
        $this->assertFalse($config->isRedactResponseBodies());
        $this->assertSame([], $config->getDisabledRules());
        $this->assertSame([], $config->getCustomRules());
    }

    /**
     * @test
     */
    public function testRedactionConfigCustomValues() {
        $rule = new RedactionRule('ssn', '/\d{3}-\d{2}-\d{4}/', '[SSN]');
        $config = new RedactionConfig(
            redactRequestBodies: true,
            redactResponseBodies: true,
            disabledRules: ['email', 'phone'],
            customRules: [$rule]
        );

        $this->assertTrue($config->isRedactRequestBodies());
        $this->assertTrue($config->isRedactResponseBodies());
        $this->assertSame(['email', 'phone'], $config->getDisabledRules());
        $this->assertCount(1, $config->getCustomRules());
    }

    /**
     * @test
     */
    public function testApiKeyRuleCannotBeDisabled() {
        $config = new RedactionConfig(disabledRules: ['api_key', 'bearer_token', 'email']);

        // api_key and bearer_token should be silently removed from disabled list
        $this->assertNotContains('api_key', $config->getDisabledRules());
        $this->assertNotContains('bearer_token', $config->getDisabledRules());
        $this->assertContains('email', $config->getDisabledRules());
    }

    /**
     * @test
     */
    public function testIsRuleEnabled() {
        $config = new RedactionConfig(disabledRules: ['email', 'phone']);

        $this->assertFalse($config->isRuleEnabled('email'));
        $this->assertFalse($config->isRuleEnabled('phone'));
        $this->assertTrue($config->isRuleEnabled('credit_card'));
        $this->assertTrue($config->isRuleEnabled('api_key'));
    }

    // =========================================================================
    // RedactionService — mandatory rules tests
    // =========================================================================

    /**
     * @test
     */
    public function testAlwaysRedactsOpenAiApiKey() {
        $service = new RedactionService(new RedactionConfig());

        $text = 'key=sk-abc123def456ghi789jkl012mno345pqr';
        $result = $service->redactString($text);

        $this->assertStringNotContainsString('sk-abc123def456ghi789jkl012mno345pqr', $result);
        $this->assertStringContainsString('[API_KEY]', $result);
    }

    /**
     * @test
     */
    public function testAlwaysRedactsBearerToken() {
        $service = new RedactionService(new RedactionConfig());

        $text = 'Authorization: Bearer ya29.a0AfH6SMBxxx_very_long_token_here';
        $result = $service->redactString($text);

        $this->assertStringNotContainsString('ya29.a0AfH6SMBxxx_very_long_token_here', $result);
        $this->assertStringContainsString('[TOKEN]', $result);
    }

    /**
     * @test
     */
    public function testAlwaysRedactsAwsAccessKey() {
        $service = new RedactionService(new RedactionConfig());

        $text = 'key=AKIAIOSFODNN7EXAMPLE';
        $result = $service->redactString($text);

        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $result);
        $this->assertStringContainsString('[API_KEY]', $result);
    }

    /**
     * @test
     */
    public function testBearerRedactionCannotBeDisabled() {
        // Even with all rules "disabled", bearer tokens still get redacted
        $service = new RedactionService(new RedactionConfig(
            disabledRules: ['api_key', 'bearer_token'] // silently ignored
        ));

        $text = 'Bearer supersecrettoken123456789';
        $result = $service->redactString($text);

        $this->assertStringNotContainsString('supersecrettoken123456789', $result);
    }

    // =========================================================================
    // RedactionService — optional rules tests
    // =========================================================================

    /**
     * @test
     */
    public function testRedactsEmailByDefault() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('Contact user@example.com for info');

        $this->assertStringNotContainsString('user@example.com', $result);
        $this->assertStringContainsString('[EMAIL]', $result);
    }

    /**
     * @test
     */
    public function testRedactsPhoneByDefault() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('Call me at 555-123-4567 please');

        $this->assertStringNotContainsString('555-123-4567', $result);
        $this->assertStringContainsString('[PHONE]', $result);
    }

    /**
     * @test
     */
    public function testRedactsCreditCardByDefault() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('Card: 4111111111111111');

        $this->assertStringNotContainsString('4111111111111111', $result);
        $this->assertStringContainsString('[CC]', $result);
    }

    /**
     * @test
     */
    public function testCanDisableEmailRule() {
        $service = new RedactionService(new RedactionConfig(
            disabledRules: ['email']
        ));

        $result = $service->redactString('Email: test@example.com');

        $this->assertStringContainsString('test@example.com', $result);
    }

    /**
     * @test
     */
    public function testCanDisablePhoneRule() {
        $service = new RedactionService(new RedactionConfig(
            disabledRules: ['phone']
        ));

        $result = $service->redactString('Phone: 555-123-4567');

        $this->assertStringContainsString('555-123-4567', $result);
    }

    // =========================================================================
    // New built-in rules: SSN, IP, Saudi ID, IBAN
    // =========================================================================

    /**
     * @test
     */
    public function testRedactsSsn() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('SSN: 123-45-6789');

        $this->assertStringNotContainsString('123-45-6789', $result);
        $this->assertStringContainsString('[SSN]', $result);
    }

    /**
     * @test
     */
    public function testRedactsIpv4() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('User IP: 192.168.1.100');

        $this->assertStringNotContainsString('192.168.1.100', $result);
        $this->assertStringContainsString('[IP]', $result);
    }

    /**
     * @test
     */
    public function testRedactsPublicIpv4() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('Connected from 203.0.113.42');

        $this->assertStringNotContainsString('203.0.113.42', $result);
        $this->assertStringContainsString('[IP]', $result);
    }

    /**
     * @test
     */
    public function testRedactsIpv6() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('IPv6: 2001:0db8:85a3:0000:0000:8a2e:0370:7334');

        $this->assertStringNotContainsString('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $result);
        $this->assertStringContainsString('[IP]', $result);
    }

    /**
     * @test
     */
    public function testRedactsSaudiCitizenId() {
        $service = new RedactionService(new RedactionConfig());

        // Saudi citizen ID starts with 1
        $result = $service->redactString('National ID: 1234567890');

        $this->assertStringNotContainsString('1234567890', $result);
        $this->assertStringContainsString('[NATIONAL_ID]', $result);
    }

    /**
     * @test
     */
    public function testRedactsSaudiResidentId() {
        $service = new RedactionService(new RedactionConfig());

        // Saudi resident ID starts with 2
        $result = $service->redactString('Iqama: 2987654321');

        $this->assertStringNotContainsString('2987654321', $result);
        $this->assertStringContainsString('[NATIONAL_ID]', $result);
    }

    /**
     * @test
     */
    public function testRedactsSaudiIban() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('IBAN: SA0380000000608010167519');

        $this->assertStringNotContainsString('SA0380000000608010167519', $result);
        $this->assertStringContainsString('[IBAN]', $result);
    }

    /**
     * @test
     */
    public function testRedactsGenericIban() {
        $service = new RedactionService(new RedactionConfig());

        $result = $service->redactString('IBAN: GB29NWBK60161331926819');

        $this->assertStringNotContainsString('GB29NWBK60161331926819', $result);
        $this->assertStringContainsString('[IBAN]', $result);
    }

    /**
     * @test
     */
    public function testCanDisableSsnRule() {
        $service = new RedactionService(new RedactionConfig(disabledRules: ['ssn']));

        $result = $service->redactString('SSN: 123-45-6789');

        $this->assertStringContainsString('123-45-6789', $result);
    }

    /**
     * @test
     */
    public function testCanDisableIpRule() {
        $service = new RedactionService(new RedactionConfig(disabledRules: ['ipv4', 'ipv6']));

        $result = $service->redactString('IP: 192.168.1.100');

        $this->assertStringContainsString('192.168.1.100', $result);
    }

    /**
     * @test
     */
    public function testCanDisableSaudiIdRule() {
        $service = new RedactionService(new RedactionConfig(disabledRules: ['saudi_id', 'phone']));

        $result = $service->redactString('ID: 1234567890');

        $this->assertStringContainsString('1234567890', $result);
    }

    /**
     * @test
     */
    public function testCanDisableIbanRule() {
        $service = new RedactionService(new RedactionConfig(disabledRules: ['iban']));

        $result = $service->redactString('IBAN: SA0380000000608010167519');

        $this->assertStringContainsString('SA0380000000608010167519', $result);
    }

    /**
     * @test
     */
    public function testMultiplePiiInOneString() {
        $service = new RedactionService(new RedactionConfig());

        $text = 'User john@example.com called 555-123-4567 from 192.168.0.1';
        $result = $service->redactString($text);

        $this->assertStringNotContainsString('john@example.com', $result);
        $this->assertStringNotContainsString('555-123-4567', $result);
        $this->assertStringNotContainsString('192.168.0.1', $result);
        $this->assertStringContainsString('[EMAIL]', $result);
        $this->assertStringContainsString('[PHONE]', $result);
        $this->assertStringContainsString('[IP]', $result);
    }

    /**
     * @test
     */
    public function testCustomRule() {
        $service = new RedactionService(new RedactionConfig(
            customRules: [
                new RedactionRule('passport', '/\b[A-Z]{1,2}\d{6,9}\b/', '[PASSPORT]'),
            ]
        ));

        $result = $service->redactString('Passport: AB1234567');

        $this->assertStringNotContainsString('AB1234567', $result);
        $this->assertStringContainsString('[PASSPORT]', $result);
    }

    /**
     * @test
     */
    public function testMultipleCustomRules() {
        $service = new RedactionService(new RedactionConfig(
            customRules: [
                new RedactionRule('passport', '/\b[A-Z]{1,2}\d{6,9}\b/', '[PASSPORT]'),
                new RedactionRule('employee_id', '/\bEMP-\d{5}\b/', '[EMP_ID]'),
            ]
        ));

        $result = $service->redactString('Passport AB1234567, employee EMP-00123');

        $this->assertStringNotContainsString('AB1234567', $result);
        $this->assertStringNotContainsString('EMP-00123', $result);
        $this->assertStringContainsString('[PASSPORT]', $result);
        $this->assertStringContainsString('[EMP_ID]', $result);
    }

    /**
     * @test
     */
    public function testCustomRuleCombinedWithDisabledBuiltin() {
        // Disable built-in phone, replace with custom Saudi phone format
        $service = new RedactionService(new RedactionConfig(
            disabledRules: ['phone'],
            customRules: [
                new RedactionRule('sa_phone', '/\b05\d{8}\b/', '[PHONE]'),
            ]
        ));

        $text = 'Call 555-123-4567 or 0512345678';
        $result = $service->redactString($text);

        // US phone preserved (built-in disabled)
        $this->assertStringContainsString('555-123-4567', $result);

        // Saudi phone redacted (custom rule)
        $this->assertStringNotContainsString('0512345678', $result);
        $this->assertStringContainsString('[PHONE]', $result);
    }

    /**
     * @test
     */
    public function testCustomRuleWithProviderIntegration() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $client->setRedactionConfig(new RedactionConfig(
            customRules: [
                new RedactionRule('employee_id', '/\bEMP-\d{5}\b/', '[EMP_ID]'),
            ]
        ));

        $logContext = [];
        $client->setLogCallback(function ($level, $message, $context) use (&$logContext) {
            $logContext[] = json_encode($context);
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(400, [], json_encode([
            'error' => ['message' => 'Error for EMP-00999 at user@secret.com'],
        ])));
        $client->setHttpClient($fakeHttp);

        try {
            $client->chat([new Message('user', 'Hello')]);
        } catch (\Throwable $e) {}

        $allLogs = implode(' ', $logContext);

        $this->assertStringNotContainsString('EMP-00999', $allLogs);
        $this->assertStringNotContainsString('user@secret.com', $allLogs);
    }

    /**
     * @test
     */
    public function testCustomRuleDoesNotAffectBuiltins() {
        // Custom rules are additive — built-ins still apply
        $service = new RedactionService(new RedactionConfig(
            customRules: [
                new RedactionRule('employee_id', '/\bEMP-\d{5}\b/', '[EMP_ID]'),
            ]
        ));

        // Built-in email rule should still work
        $result = $service->redactString('Email admin@example.com, EMP-00001');

        $this->assertStringNotContainsString('admin@example.com', $result);
        $this->assertStringNotContainsString('EMP-00001', $result);
        $this->assertStringContainsString('[EMAIL]', $result);
        $this->assertStringContainsString('[EMP_ID]', $result);
    }

    /**
     * @test
     */
    public function testEmptyStringReturnsEmpty() {
        $service = new RedactionService(new RedactionConfig());

        $this->assertSame('', $service->redactString(''));
    }

    /**
     * @test
     */
    public function testNoSensitiveDataUnchanged() {
        $service = new RedactionService(new RedactionConfig());

        $text = 'The quick brown fox jumps over the lazy dog';
        $result = $service->redactString($text);

        $this->assertSame($text, $result);
    }

    // =========================================================================
    // RedactionService — redactContext tests
    // =========================================================================

    /**
     * @test
     */
    public function testRedactContextStringsApplyPatterns() {
        $service = new RedactionService(new RedactionConfig());

        $context = [
            'provider' => 'openai',
            'error' => 'User john@example.com not found',
        ];

        $result = $service->redactContext($context);

        $this->assertSame('openai', $result['provider']);
        $this->assertStringNotContainsString('john@example.com', $result['error']);
        $this->assertStringContainsString('[EMAIL]', $result['error']);
    }

    /**
     * @test
     */
    public function testRedactContextBodyRedactionOptIn() {
        $service = new RedactionService(new RedactionConfig(
            redactRequestBodies: true
        ));

        $context = [
            'model' => 'gpt-4o',
            'body' => 'User says: my email is secret@example.com',
        ];

        $result = $service->redactContext($context);

        $this->assertSame('gpt-4o', $result['model']);
        $this->assertSame('[REDACTED]', $result['body']);
    }

    /**
     * @test
     */
    public function testRedactContextBodyNotRedactedByDefault() {
        $service = new RedactionService(new RedactionConfig());

        $context = [
            'body' => 'User says: hello world',
        ];

        $result = $service->redactContext($context);

        $this->assertSame('User says: hello world', $result['body']);
    }

    /**
     * @test
     */
    public function testRedactContextNestedArray() {
        $service = new RedactionService(new RedactionConfig());

        $context = [
            'nested' => [
                'error' => 'Contact us at help@example.com',
            ],
        ];

        $result = $service->redactContext($context);

        $this->assertStringNotContainsString('help@example.com', $result['nested']['error']);
    }

    /**
     * @test
     */
    public function testRedactContextPreservesNonStringValues() {
        $service = new RedactionService(new RedactionConfig());

        $context = [
            'latency_ms' => 150,
            'tokens' => 42,
            'available' => true,
            'metadata' => null,
        ];

        $result = $service->redactContext($context);

        $this->assertSame(150, $result['latency_ms']);
        $this->assertSame(42, $result['tokens']);
        $this->assertTrue($result['available']);
        $this->assertNull($result['metadata']);
    }

    // =========================================================================
    // Provider integration tests
    // =========================================================================

    /**
     * @test
     */
    public function testSetRedactionConfigAppliesToLogs() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $client->setRedactionConfig(new RedactionConfig());

        $loggedMessages = [];
        $client->setLogCallback(function ($level, $message, $context) use (&$loggedMessages) {
            $loggedMessages[] = compact('level', 'message', 'context');
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ])));
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi there')]);

        // No sensitive data should appear in logs
        foreach ($loggedMessages as $log) {
            $this->assertStringNotContainsString('test-key', json_encode($log));
        }
    }

    /**
     * @test
     */
    public function testSetRedactionConfigAppliesToMetrics() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $client->setRedactionConfig(new RedactionConfig());

        $metricData = [];
        $client->setMetricsCallback(function ($event, $data) use (&$metricData) {
            $metricData[] = $data;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi with email user@test.com')]);

        // No email should appear in metric data
        foreach ($metricData as $data) {
            $this->assertStringNotContainsString('user@test.com', json_encode($data));
        }
    }

    /**
     * @test
     */
    public function testSetRedactionConfigNull() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $client->setRedactionConfig(new RedactionConfig());
        // Should not throw
        $client->setRedactionConfig(null);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
        ])));
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hello')]);
        $this->assertSame('Hi', $response->getMessage()->getContent());
    }

    /**
     * @test
     */
    public function testErrorMessagesAreRedacted() {
        $client = new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
        $client->setRedactionConfig(new RedactionConfig());

        $loggedErrors = [];
        $client->setLogCallback(function ($level, $message, $context) use (&$loggedErrors) {
            $loggedErrors[] = json_encode($context);
        });

        $fakeHttp = new FakeHttpClient();
        // Error response that echoes back an email
        $fakeHttp->addResponse(new HttpResponse(400, [], json_encode([
            'error' => ['message' => 'Invalid request for user@secret.com'],
        ])));
        $client->setHttpClient($fakeHttp);

        try {
            $client->chat([new Message('user', 'Hi')]);
        } catch (\Throwable $e) {
            // Exception message itself is checked separately
        }

        // Any logged context should not contain the email
        foreach ($loggedErrors as $error) {
            $this->assertStringNotContainsString('user@secret.com', $error);
        }
    }
}
