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
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\InteractionsRequestBuilder;
use WebFiori\Ai\Provider\Google\SchemaCoercer;
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for #169: empty schema `properties` must serialize as {} not [] in
 * Gemini tool parameters and responseSchema.
 */
class GoogleEmptySchemaTest extends TestCase {
    // =========================================================================
    // SchemaCoercer
    // =========================================================================

    public function testCoerceEmptyPropertiesBecomesObject(): void {
        $out = SchemaCoercer::coerce(['type' => 'object', 'properties' => []]);

        $this->assertStringContainsString('"properties":{}', json_encode($out));
        $this->assertStringNotContainsString('"properties":[]', json_encode($out));
    }

    public function testCoerceNonEmptyPropertiesStaysObject(): void {
        $json = json_encode(SchemaCoercer::coerce([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]));

        $this->assertStringContainsString('"properties":{"name":{"type":"string"}}', $json);
    }

    public function testCoercePreservesListsSuchAsRequiredAndEnum(): void {
        $json = json_encode(SchemaCoercer::coerce([
            'type' => 'object',
            'properties' => ['status' => ['type' => 'string', 'enum' => ['a', 'b']]],
            'required' => ['status'],
        ]));

        $this->assertStringContainsString('"required":["status"]', $json);
        $this->assertStringContainsString('"enum":["a","b"]', $json);
    }

    public function testCoerceNestedEmptyPropertiesRecursively(): void {
        $json = json_encode(SchemaCoercer::coerce([
            'type' => 'object',
            'properties' => [
                'nested' => ['type' => 'object', 'properties' => []],
            ],
        ]));

        // Outer non-empty object and inner empty object both serialize as {}.
        $this->assertStringContainsString('"nested":{"type":"object","properties":{}}', $json);
        $this->assertStringNotContainsString('[]', $json);
    }

    public function testCoerceEmptyDefsAndDefinitionsBecomeObjects(): void {
        $json = json_encode(SchemaCoercer::coerce([
            'type' => 'object',
            'properties' => [],
            '$defs' => [],
            'definitions' => [],
        ]));

        $this->assertStringContainsString('"$defs":{}', $json);
        $this->assertStringContainsString('"definitions":{}', $json);
    }

    public function testCoerceNonArrayReturnedAsIs(): void {
        $this->assertSame('scalar', SchemaCoercer::coerce('scalar'));
        $this->assertSame(42, SchemaCoercer::coerce(42));
    }

    // =========================================================================
    // Classic generateContent — tool parameters
    // =========================================================================

    public function testToolWithEmptyPropertiesSerializesAsObject(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'OK']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-api-key',
        ));
        $provider->setHttpClient($http);

        $tool = new Tool('retrieve', 'Retrieve chunks', ['type' => 'object', 'properties' => []], fn (array $a): string => 'ok');

        $provider->chat([new Message('user', 'hi')], ['tools' => [$tool]]);

        // Assert on the RAW body: json_decode would hide [] vs {}.
        $raw = $http->getLastRequest()->getBody();

        $this->assertStringContainsString('"properties":{}', $raw);
        $this->assertStringNotContainsString('"properties":[]', $raw);
    }

    // =========================================================================
    // Classic generateContent — responseSchema
    // =========================================================================

    public function testResponseSchemaWithEmptyPropertiesSerializesAsObject(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => '{}']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ])));

        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test-api-key',
        ));
        $provider->setHttpClient($http);

        $provider->chat(
            [new Message('user', 'give json')],
            ['json_schema' => ['type' => 'object', 'properties' => []]]
        );

        $raw = $http->getLastRequest()->getBody();

        $this->assertStringContainsString('"properties":{}', $raw);
        $this->assertStringNotContainsString('"properties":[]', $raw);
    }

    // =========================================================================
    // Interactions API — tool parameters
    // =========================================================================

    public function testInteractionsFormatToolsEmptyPropertiesSerializesAsObject(): void {
        $builder = new InteractionsRequestBuilder();
        $tool = new Tool('retrieve', 'Retrieve chunks', ['type' => 'object', 'properties' => []], fn (array $a): string => 'ok');

        $formatted = $builder->formatTools([$tool]);
        $raw = json_encode($formatted);

        $this->assertStringContainsString('"properties":{}', $raw);
        $this->assertStringNotContainsString('"properties":[]', $raw);
    }
}
