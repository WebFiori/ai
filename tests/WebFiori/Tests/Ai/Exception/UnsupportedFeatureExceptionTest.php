<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Exception;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Exception\AiException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;
use WebFiori\Ai\ImageRequest;

/**
 * Tests for UnsupportedFeatureException argument handling and the provider
 * paths that throw it. Regression coverage for the pre-1.0 bug where callers
 * passed (message, feature, provider) into a (feature, provider) constructor,
 * producing garbled messages and wrong accessor values.
 */
class UnsupportedFeatureExceptionTest extends TestCase {
    public function testDefaultMessageGenerated(): void {
        $e = new UnsupportedFeatureException('embeddings', 'anthropic');

        $this->assertSame('embeddings', $e->getFeature());
        $this->assertSame('anthropic', $e->getProviderName());
        $this->assertStringContainsString('embeddings', $e->getMessage());
        $this->assertStringContainsString('anthropic', $e->getMessage());
        $this->assertInstanceOf(AiException::class, $e);
    }

    public function testCustomMessageOverridesDefault(): void {
        $e = new UnsupportedFeatureException(
            'embeddings',
            'anthropic',
            'Anthropic does not support embeddings.'
        );

        // Custom message is used verbatim...
        $this->assertSame('Anthropic does not support embeddings.', $e->getMessage());
        // ...but the structured accessors still hold the real values.
        $this->assertSame('embeddings', $e->getFeature());
        $this->assertSame('anthropic', $e->getProviderName());
    }

    public function testAnthropicEmbedThrowsWithCorrectMetadata(): void {
        $client = new AnthropicClient(new AnthropicClientConfig(apiKey: 'test'));

        try {
            $client->embed('hello');
            $this->fail('Expected UnsupportedFeatureException');
        } catch (UnsupportedFeatureException $e) {
            $this->assertSame('embeddings', $e->getFeature());
            $this->assertSame('anthropic', $e->getProviderName());
            $this->assertStringContainsString('Anthropic does not support embeddings', $e->getMessage());
        }
    }

    public function testAnthropicImageThrowsWithCorrectMetadata(): void {
        $client = new AnthropicClient(new AnthropicClientConfig(apiKey: 'test'));

        try {
            $client->generateImage(new ImageRequest('a cat'));
            $this->fail('Expected UnsupportedFeatureException');
        } catch (UnsupportedFeatureException $e) {
            $this->assertSame('image_generation', $e->getFeature());
            $this->assertSame('anthropic', $e->getProviderName());
        }
    }

    public function testBedrockEmbedThrowsWithCorrectMetadata(): void {
        $client = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            accessKey: 'AKIA_TEST',
            secretKey: 'SECRET_TEST',
        ));

        try {
            $client->embed('hello');
            $this->fail('Expected UnsupportedFeatureException');
        } catch (UnsupportedFeatureException $e) {
            $this->assertSame('embeddings', $e->getFeature());
            $this->assertSame('bedrock', $e->getProviderName());
        }
    }

    public function testBedrockImageThrowsWithCorrectMetadata(): void {
        $client = new BedrockClient(new BedrockClientConfig(
            region: 'us-east-1',
            accessKey: 'AKIA_TEST',
            secretKey: 'SECRET_TEST',
        ));

        try {
            $client->generateImage(new ImageRequest('a cat'));
            $this->fail('Expected UnsupportedFeatureException');
        } catch (UnsupportedFeatureException $e) {
            $this->assertSame('image_generation', $e->getFeature());
            $this->assertSame('bedrock', $e->getProviderName());
        }
    }
}
