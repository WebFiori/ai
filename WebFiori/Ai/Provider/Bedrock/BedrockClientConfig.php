<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Bedrock;

use WebFiori\Ai\Provider\ClientConfig;

/**
 * Configuration for the AWS Bedrock provider client.
 *
 * Supports two authentication modes:
 * - API Key: Simple key-based authentication
 * - AWS Credentials: SigV4 signing with access/secret keys or credential chain
 *
 * @author Ibrahim
 */
class BedrockClientConfig extends ClientConfig {
    /**
     * AWS access key ID (for SigV4 auth).
     *
     * @var string|null
     */
    public readonly ?string $accessKey;

    /**
     * Bedrock API key (simple auth mode).
     *
     * @var string|null
     */
    public readonly ?string $apiKey;

    /**
     * Which invocation API to use.
     *
     * @var ApiMethod
     */
    public readonly string $apiMethod;

    /**
     * Default max tokens for responses.
     *
     * @var int
     */
    public readonly int $maxTokens;

    /**
     * AWS profile name for credential chain.
     *
     * @var string|null
     */
    public readonly ?string $profile;

    /**
     * AWS region (required).
     *
     * @var string
     */
    public readonly string $region;

    /**
     * AWS secret access key (for SigV4 auth).
     *
     * @var string|null
     */
    public readonly ?string $secretKey;

    /**
     * AWS session token (for temporary credentials).
     *
     * @var string|null
     */
    public readonly ?string $sessionToken;

    /**
     * Creates a new BedrockClientConfig instance.
     *
     * @param string $region AWS region (e.g., 'us-east-1').
     * @param string $model The default model for chat completions.
     * @param string|null $apiKey Bedrock API key (simple auth).
     * @param string|null $accessKey AWS access key ID (SigV4 auth).
     * @param string|null $secretKey AWS secret access key (SigV4 auth).
     * @param string|null $sessionToken AWS session token (temporary credentials).
     * @param string|null $profile AWS profile name for credential chain.
     * @param string $apiMethod Invocation API to use. Use ApiMethod constants.
     * @param int $maxTokens Default max tokens for responses.
     * @param int $timeout Request timeout in seconds.
     * @param int $connectTimeout Connection timeout in seconds.
     */
    public function __construct(
        string $region,
        string $model = 'anthropic.claude-3-5-sonnet-20241022-v2:0',
        ?string $apiKey = null,
        ?string $accessKey = null,
        ?string $secretKey = null,
        ?string $sessionToken = null,
        ?string $profile = null,
        string $apiMethod = ApiMethod::CONVERSE,
        int $maxTokens = 4096,
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        parent::__construct($model, $timeout, $connectTimeout);

        $this->region = $region;
        $this->apiKey = $apiKey;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->sessionToken = $sessionToken;
        $this->profile = $profile;
        $this->apiMethod = $apiMethod;
        $this->maxTokens = $maxTokens;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array {
        return [
            'region' => $this->region,
            'model' => $this->model,
            'api_key' => $this->apiKey,
            'access_key' => $this->accessKey,
            'secret_key' => $this->secretKey,
            'session_token' => $this->sessionToken,
            'profile' => $this->profile,
            'api_method' => $this->apiMethod,
            'max_tokens' => $this->maxTokens,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];
    }
}
