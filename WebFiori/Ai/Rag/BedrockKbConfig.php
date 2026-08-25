<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Rag;

/**
 * Configuration for the Bedrock Knowledge Base RAG provider.
 *
 * Holds the AWS region, knowledge base ID, and optional credentials.
 * If accessKey/secretKey are null, {@see \WebFiori\Ai\Auth\AwsCredentialChain}
 * resolves credentials automatically (environment variables, credentials file,
 * or instance metadata).
 *
 * Example:
 * ```php
 * // Explicit credentials
 * $config = new BedrockKbConfig(
 *     region: 'us-east-1',
 *     knowledgeBaseId: 'KB12345',
 *     accessKey: 'AKIA...',
 *     secretKey: 'wJal...',
 * );
 *
 * // Auto-resolved credentials (IAM role, env vars, ~/.aws/credentials)
 * $config = new BedrockKbConfig(
 *     region: 'us-east-1',
 *     knowledgeBaseId: 'KB12345',
 * );
 * ```
 *
 * @author Ibrahim
 */
class BedrockKbConfig {
    /**
     * AWS access key ID for SigV4 authentication.
     *
     * If null, credentials are resolved automatically via
     * {@see \WebFiori\Ai\Auth\AwsCredentialChain}.
     *
     * @var string|null
     */
    public readonly ?string $accessKey;

    /**
     * The Bedrock Knowledge Base ID.
     *
     * @var string
     */
    public readonly string $knowledgeBaseId;

    /**
     * Optional model ARN for retrieval augmentation with a foundation model.
     *
     * When set, the knowledge base can use this model for query reformulation
     * or answer generation during retrieval.
     *
     * @var string|null
     */
    public readonly ?string $modelArn;
    /**
     * AWS region where the knowledge base is deployed.
     *
     * @var string
     */
    public readonly string $region;

    /**
     * AWS secret access key for SigV4 authentication.
     *
     * If null, credentials are resolved automatically via
     * {@see \WebFiori\Ai\Auth\AwsCredentialChain}.
     *
     * @var string|null
     */
    public readonly ?string $secretKey;

    /**
     * AWS session token for temporary credentials (e.g., from STS AssumeRole).
     *
     * @var string|null
     */
    public readonly ?string $sessionToken;

    /**
     * Creates a new BedrockKbConfig instance.
     *
     * @param string $region AWS region (e.g., 'us-east-1').
     * @param string $knowledgeBaseId The Bedrock Knowledge Base ID.
     * @param string|null $accessKey AWS access key ID. If null, credentials
     *        are resolved via the standard AWS credential chain.
     * @param string|null $secretKey AWS secret access key. Required if
     *        accessKey is provided.
     * @param string|null $sessionToken AWS session token for temporary
     *        credentials.
     * @param string|null $modelArn Optional foundation model ARN for
     *        retrieval augmentation.
     */
    public function __construct(
        string $region,
        string $knowledgeBaseId,
        ?string $accessKey = null,
        ?string $secretKey = null,
        ?string $sessionToken = null,
        ?string $modelArn = null,
    ) {
        $this->region = $region;
        $this->knowledgeBaseId = $knowledgeBaseId;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->sessionToken = $sessionToken;
        $this->modelArn = $modelArn;
    }
}
