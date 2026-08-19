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
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;

/**
 * Tests for #99: Auto-detect API version from model name.
 */
class GoogleApiVersionTest extends TestCase {
    // =========================================================================
    // Auto-detection from model name
    // =========================================================================

    public function testGemini2xUsesGenerateContent(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-key',
        ));

        $fakeHttp = $this->fakeGeminiResponse();
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertStringContainsString('generateContent', $fakeHttp->getLastRequest()->getUrl());
    }

    public function testGemini25ProUsesGenerateContent(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-pro',
            apiKey: 'test-key',
        ));

        $fakeHttp = $this->fakeGeminiResponse();
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertStringContainsString('generateContent', $fakeHttp->getLastRequest()->getUrl());
    }

    public function testGemini1xUsesGenerateContent(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-1.5-pro',
            apiKey: 'test-key',
        ));

        $fakeHttp = $this->fakeGeminiResponse();
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertStringContainsString('generateContent', $fakeHttp->getLastRequest()->getUrl());
    }

    public function testGemini3xThrowsUnsupportedFeature(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
        ));
        $client->setHttpClient(new FakeHttpClient());

        $this->expectException(UnsupportedFeatureException::class);
        $client->chat([new Message('user', 'Hi')]);
    }

    public function testGemini3xStreamingThrowsUnsupportedFeature(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.0-pro',
            apiKey: 'test-key',
        ));
        $client->setHttpClient(new FakeHttpClient());

        $this->expectException(UnsupportedFeatureException::class);
        $client->streamChat(
            [new Message('user', 'Hi')],
            function (string $token) {}
        );
    }

    public function testGemini4xAlsoUsesInteractions(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-4.0-ultra',
            apiKey: 'test-key',
        ));
        $client->setHttpClient(new FakeHttpClient());

        $this->expectException(UnsupportedFeatureException::class);
        $client->chat([new Message('user', 'Hi')]);
    }

    // =========================================================================
    // Manual override via GoogleApiVersion config
    // =========================================================================

    public function testManualOverrideForceGenerateContent(): void {
        // Force generate_content even for gemini-3.x
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-3.5-flash',
            apiKey: 'test-key',
            apiVersion: GoogleApiVersion::GENERATE_CONTENT,
        ));

        $fakeHttp = $this->fakeGeminiResponse();
        $client->setHttpClient($fakeHttp);

        // Should NOT throw — override forces legacy API
        $client->chat([new Message('user', 'Hi')]);

        $this->assertStringContainsString('generateContent', $fakeHttp->getLastRequest()->getUrl());
    }

    public function testManualOverrideForceInteractions(): void {
        // Force interactions even for gemini-2.x
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-key',
            apiVersion: GoogleApiVersion::INTERACTIONS,
        ));
        $client->setHttpClient(new FakeHttpClient());

        $this->expectException(UnsupportedFeatureException::class);
        $client->chat([new Message('user', 'Hi')]);
    }

    public function testAutoVersionIsDefault(): void {
        $config = new GoogleClientConfig(model: 'gemini-2.5-flash', apiKey: 'key');
        $this->assertEquals(GoogleApiVersion::AUTO, $config->apiVersion);
    }

    // =========================================================================
    // Edge cases in model name detection
    // =========================================================================

    public function testNonGeminiModelUsesGenerateContent(): void {
        // A custom/unknown model name should default to generate_content
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'palm-2',
            apiKey: 'test-key',
        ));

        $fakeHttp = $this->fakeGeminiResponse();
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertStringContainsString('generateContent', $fakeHttp->getLastRequest()->getUrl());
    }

    public function testGeminiWithoutVersionUsesGenerateContent(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-pro',
            apiKey: 'test-key',
        ));

        $fakeHttp = $this->fakeGeminiResponse();
        $client->setHttpClient($fakeHttp);

        $client->chat([new Message('user', 'Hi')]);

        $this->assertStringContainsString('generateContent', $fakeHttp->getLastRequest()->getUrl());
    }

    // =========================================================================
    // GoogleApiVersion enum
    // =========================================================================

    public function testGoogleApiVersionEnumValues(): void {
        $this->assertEquals('auto', GoogleApiVersion::AUTO->value);
        $this->assertEquals('generate_content', GoogleApiVersion::GENERATE_CONTENT->value);
        $this->assertEquals('interactions', GoogleApiVersion::INTERACTIONS->value);
    }

    public function testGoogleApiVersionFromString(): void {
        $this->assertEquals(GoogleApiVersion::AUTO, GoogleApiVersion::from('auto'));
        $this->assertEquals(GoogleApiVersion::GENERATE_CONTENT, GoogleApiVersion::from('generate_content'));
        $this->assertEquals(GoogleApiVersion::INTERACTIONS, GoogleApiVersion::from('interactions'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakeGeminiResponse(): FakeHttpClient {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hello']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3],
        ])));

        return $fakeHttp;
    }
}
