<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Rag;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Rag\BedrockKbConfig;
use WebFiori\Ai\Rag\GoogleRagConfig;

/**
 * Tests for the RAG provider configuration DTOs.
 */
class RagConfigTest extends TestCase {
    // =========================================================================
    // GoogleRagConfig
    // =========================================================================

    public function testGoogleRagConfig_StoresAllValues(): void {
        $config = new GoogleRagConfig(
            projectId: 'my-project',
            location: 'us-central1',
            corpusId: '1234567890',
            credentials: '/path/to/key.json',
        );

        $this->assertSame('my-project', $config->projectId);
        $this->assertSame('us-central1', $config->location);
        $this->assertSame('1234567890', $config->corpusId);
        $this->assertSame('/path/to/key.json', $config->credentials);
    }

    public function testGoogleRagConfig_CredentialsDefaultsToNull(): void {
        $config = new GoogleRagConfig(
            projectId: 'my-project',
            location: 'us-central1',
            corpusId: '1234567890',
        );

        $this->assertNull($config->credentials);
    }

    public function testGoogleRagConfig_AcceptsCredentialsArray(): void {
        $creds = ['type' => 'service_account', 'client_email' => 'a@b.iam'];
        $config = new GoogleRagConfig(
            projectId: 'p',
            location: 'l',
            corpusId: 'c',
            credentials: $creds,
        );

        $this->assertSame($creds, $config->credentials);
    }

    // =========================================================================
    // BedrockKbConfig
    // =========================================================================

    public function testBedrockKbConfig_StoresAllValues(): void {
        $config = new BedrockKbConfig(
            region: 'us-east-1',
            knowledgeBaseId: 'KB12345',
            accessKey: 'AKIA_TEST',
            secretKey: 'SECRET_TEST',
            sessionToken: 'SESSION_TEST',
            modelArn: 'arn:aws:bedrock:model/x',
        );

        $this->assertSame('us-east-1', $config->region);
        $this->assertSame('KB12345', $config->knowledgeBaseId);
        $this->assertSame('AKIA_TEST', $config->accessKey);
        $this->assertSame('SECRET_TEST', $config->secretKey);
        $this->assertSame('SESSION_TEST', $config->sessionToken);
        $this->assertSame('arn:aws:bedrock:model/x', $config->modelArn);
    }

    public function testBedrockKbConfig_OptionalsDefaultToNull(): void {
        $config = new BedrockKbConfig(
            region: 'us-east-1',
            knowledgeBaseId: 'KB12345',
        );

        $this->assertNull($config->accessKey);
        $this->assertNull($config->secretKey);
        $this->assertNull($config->sessionToken);
        $this->assertNull($config->modelArn);
    }
}
