<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Provider\Bedrock;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Provider\Bedrock\ApiMethod;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;

/**
 * Verifies ApiMethod is a string-backed enum (consistent with the other
 * closed-choice enums in the library) and that BedrockClientConfig accepts
 * both an ApiMethod case and its string value.
 */
class ApiMethodTest extends TestCase {
    public function testIsBackedEnumWithExpectedValues(): void {
        $this->assertSame('converse', ApiMethod::CONVERSE->value);
        $this->assertSame('invoke', ApiMethod::INVOKE->value);
        $this->assertSame('responses', ApiMethod::RESPONSES->value);
    }

    public function testTryFromResolvesStrings(): void {
        $this->assertSame(ApiMethod::CONVERSE, ApiMethod::tryFrom('converse'));
        $this->assertSame(ApiMethod::INVOKE, ApiMethod::tryFrom('invoke'));
        $this->assertNull(ApiMethod::tryFrom('nope'));
    }

    public function testConfigAcceptsEnumCase(): void {
        $config = new BedrockClientConfig(
            region: 'us-east-1',
            apiKey: 'k',
            apiMethod: ApiMethod::INVOKE,
        );

        // Stored (and serialized) as the backing string for BC.
        $this->assertSame('invoke', $config->apiMethod);
        $this->assertSame('invoke', $config->toArray()['api_method']);
    }

    public function testConfigAcceptsStringValue(): void {
        $config = new BedrockClientConfig(
            region: 'us-east-1',
            apiKey: 'k',
            apiMethod: 'converse',
        );

        $this->assertSame('converse', $config->apiMethod);
    }

    public function testConfigDefaultsToConverse(): void {
        $config = new BedrockClientConfig(region: 'us-east-1', apiKey: 'k');
        $this->assertSame('converse', $config->apiMethod);
    }
}
