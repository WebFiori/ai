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
use WebFiori\Ai\Audit\AuditConfig;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Redaction\RedactionConfig;

/**
 * Tests for structured audit logging.
 */
class AuditTest extends TestCase {
    private function makeClient(): OpenAIClient {
        return new OpenAIClient(['api_key' => 'test-key', 'model' => 'gpt-4o']);
    }

    private function chatResponse(string $content = 'Hello!'): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]));
    }

    // =========================================================================
    // AuditConfig Tests
    // =========================================================================

    /**
     * @test
     */
    public function testAuditConfigDefaults() {
        $config = new AuditConfig();

        $this->assertFalse($config->isIncludeMessages());
        $this->assertFalse($config->isIncludeResponse());
    }

    /**
     * @test
     */
    public function testAuditConfigCustomValues() {
        $config = new AuditConfig(includeMessages: true, includeResponse: true);

        $this->assertTrue($config->isIncludeMessages());
        $this->assertTrue($config->isIncludeResponse());
    }

    // =========================================================================
    // Audit callback and context tests
    // =========================================================================

    /**
     * @test
     */
    public function testSetAndGetAuditCallback() {
        $client = $this->makeClient();

        $this->assertNull($client->getAuditCallback());

        $cb = function () {};
        $client->setAuditCallback($cb);

        $this->assertSame($cb, $client->getAuditCallback());
    }

    /**
     * @test
     */
    public function testSetAuditCallbackNull() {
        $client = $this->makeClient();
        $client->setAuditCallback(function () {});
        $client->setAuditCallback(null);

        $this->assertNull($client->getAuditCallback());
    }

    /**
     * @test
     */
    public function testSetAndGetAuditContext() {
        $client = $this->makeClient();

        $this->assertSame([], $client->getAuditContext());

        $client->setAuditContext(['tenant_id' => 'tenant-123']);
        $this->assertSame(['tenant_id' => 'tenant-123'], $client->getAuditContext());
    }

    /**
     * @test
     */
    public function testSetAndGetAuditConfig() {
        $client = $this->makeClient();
        $config = new AuditConfig(includeMessages: true);
        $client->setAuditConfig($config);

        $this->assertSame($config, $client->getAuditConfig());
    }

    /**
     * @test
     */
    public function testNoCallbackNoOp() {
        $client = $this->makeClient();
        // No audit callback — should not throw

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);
        $this->assertSame('Hello!', $response->getMessage()->getContent());
    }

    // =========================================================================
    // chat() audit tests
    // =========================================================================

    /**
     * @test
     */
    public function testChatEmitsAuditEntry() {
        $client = $this->makeClient();
        $entries = [];
        $client->setAuditCallback(function ($entry) use (&$entries) {
            $entries[] = $entry;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertCount(1, $entries);
    }

    /**
     * @test
     */
    public function testChatAuditEntryStructure() {
        $client = $this->makeClient();
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) {
            $entry = $e;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertArrayHasKey('request_id', $entry);
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('operation', $entry);
        $this->assertArrayHasKey('provider', $entry);
        $this->assertArrayHasKey('model', $entry);
        $this->assertArrayHasKey('status', $entry);
        $this->assertArrayHasKey('duration_ms', $entry);
        $this->assertArrayHasKey('tokens', $entry);
        $this->assertArrayHasKey('error', $entry);
        $this->assertArrayHasKey('metadata', $entry);

        $this->assertSame('chat', $entry['operation']);
        $this->assertSame('openai', $entry['provider']);
        $this->assertSame('gpt-4o', $entry['model']);
        $this->assertSame('success', $entry['status']);
        $this->assertNull($entry['error']);
        $this->assertSame(10, $entry['tokens']['prompt']);
        $this->assertSame(5, $entry['tokens']['completion']);
        $this->assertGreaterThanOrEqual(0, $entry['duration_ms']);
        $this->assertGreaterThan(1_000_000_000_000, $entry['timestamp']);
    }

    /**
     * @test
     */
    public function testChatAuditEntryRequestIdMatchesResponse() {
        $client = $this->makeClient();
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) {
            $entry = $e;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new Message('user', 'Hi')]);

        $this->assertSame($response->getRequestId(), $entry['request_id']);
    }

    /**
     * @test
     */
    public function testChatAuditErrorEntry() {
        $client = $this->makeClient();
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) {
            $entry = $e;
        });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(401, [], json_encode([
            'error' => ['message' => 'Unauthorized'],
        ])));
        $client->setHttpClient($fakeHttp);

        try {
            $client->chat([new Message('user', 'Hi')]);
        } catch (\Throwable $e) {}

        $this->assertNotNull($entry);
        $this->assertSame('error', $entry['status']);
        $this->assertNotNull($entry['error']);
        $this->assertArrayHasKey('type', $entry['error']);
        $this->assertArrayHasKey('message', $entry['error']);
    }

    /**
     * @test
     */
    public function testChatAuditDoesNotIncludeMessagesByDefault() {
        $client = $this->makeClient();
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Secret message')]);

        $this->assertArrayNotHasKey('messages', $entry);
    }

    /**
     * @test
     */
    public function testChatAuditIncludesMessagesWhenConfigured() {
        $client = $this->makeClient();
        $client->setAuditConfig(new AuditConfig(includeMessages: true));
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hello there')]);

        $this->assertArrayHasKey('messages', $entry);
        $this->assertSame('user', $entry['messages'][0]['role']);
        $this->assertSame('Hello there', $entry['messages'][0]['content']);
    }

    /**
     * @test
     */
    public function testChatAuditIncludesResponseWhenConfigured() {
        $client = $this->makeClient();
        $client->setAuditConfig(new AuditConfig(includeResponse: true));
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse('The answer is 42'));
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'What is the answer?')]);

        $this->assertArrayHasKey('response', $entry);
        $this->assertSame('The answer is 42', $entry['response']);
    }

    // =========================================================================
    // Metadata context tests
    // =========================================================================

    /**
     * @test
     */
    public function testStaticAuditContextIncluded() {
        $client = $this->makeClient();
        $client->setAuditContext(['tenant_id' => 'tenant-abc', 'feature' => 'chatbot']);
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertSame('tenant-abc', $entry['metadata']['tenant_id']);
        $this->assertSame('chatbot', $entry['metadata']['feature']);
    }

    /**
     * @test
     */
    public function testDynamicAuditContextMergedWithStatic() {
        $client = $this->makeClient();
        $client->setAuditContext(['tenant_id' => 'tenant-abc']);
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')], [
            'audit_context' => ['user_id' => 'user-123'],
        ]);

        $this->assertSame('tenant-abc', $entry['metadata']['tenant_id']);
        $this->assertSame('user-123', $entry['metadata']['user_id']);
    }

    /**
     * @test
     */
    public function testDynamicContextOverridesStatic() {
        $client = $this->makeClient();
        $client->setAuditContext(['feature' => 'default']);
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')], [
            'audit_context' => ['feature' => 'override'],
        ]);

        $this->assertSame('override', $entry['metadata']['feature']);
    }

    // =========================================================================
    // embed() audit tests
    // =========================================================================

    /**
     * @test
     */
    public function testEmbedEmitsAuditEntry() {
        $client = $this->makeClient();
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'data' => [['embedding' => [0.1, 0.2]]],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 3],
        ])));
        $client->setHttpClient($fakeHttp);

        $client->embed('test');

        $this->assertSame('embed', $entry['operation']);
        $this->assertSame('success', $entry['status']);
        $this->assertSame(3, $entry['tokens']['prompt']);
    }

    // =========================================================================
    // streamChat() audit tests
    // =========================================================================

    /**
     * @test
     */
    public function testStreamChatEmitsSingleAuditEntryAfterCompletion() {
        $client = $this->makeClient();
        $entries = [];
        $client->setAuditCallback(function ($e) use (&$entries) { $entries[] = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addStreamingChunks([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":null}]}\n\n",
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"model\":\"gpt-4o\"}\n\n",
            "data: [DONE]\n\n",
        ]);
        $client->setHttpClient($fakeHttp);

        $client->streamChat(
            [new Message('user', 'Hey')],
            fn ($t) => null,
            fn ($r) => null
        );

        $this->assertCount(1, $entries);
        $this->assertSame('streamChat', $entries[0]['operation']);
        $this->assertSame('success', $entries[0]['status']);
    }

    // =========================================================================
    // PII redaction in audit tests
    // =========================================================================

    /**
     * @test
     */
    public function testAuditEntriesAreRedacted() {
        $client = $this->makeClient();
        $client->setRedactionConfig(new RedactionConfig());
        $client->setAuditConfig(new AuditConfig(includeResponse: true));
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse('Contact admin@secret.com for help'));
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertStringNotContainsString('admin@secret.com', $entry['response']);
        $this->assertStringContainsString('[EMAIL]', $entry['response']);
    }

    /**
     * @test
     */
    public function testAuditMetadataNotRedacted() {
        $client = $this->makeClient();
        $client->setRedactionConfig(new RedactionConfig());
        $client->setAuditContext(['tenant_id' => 'tenant-123', 'feature' => 'support']);
        $entry = null;
        $client->setAuditCallback(function ($e) use (&$entry) { $entry = $e; });

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse($this->chatResponse());
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        // Metadata values that look like IDs should not be redacted
        $this->assertSame('tenant-123', $entry['metadata']['tenant_id']);
    }
}
