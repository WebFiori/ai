<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Anthropic;

use WebFiori\Ai\Provider\ClientConfig;

/**
 * Configuration for the Anthropic (Claude) provider client.
 *
 * @author Ibrahim
 */
class AnthropicClientConfig extends ClientConfig {
    /**
     * Anthropic API version header.
     *
     * @var string
     */
    public readonly string $anthropicVersion;

    /**
     * Anthropic API key.
     *
     * @var string
     */
    public readonly string $apiKey;

    /**
     * Base URL for the API.
     *
     * @var string
     */
    public readonly string $baseUrl;

    /**
     * Default max tokens for responses.
     *
     * @var int
     */
    public readonly int $maxTokens;

    /**
     * Creates a new AnthropicClientConfig instance.
     *
     * @param string $apiKey Anthropic API key.
     * @param string $model The default model for chat completions.
     * @param int $maxTokens Default max tokens for responses.
     * @param string $baseUrl Base URL for the API.
     * @param string $anthropicVersion API version header value.
     * @param int $timeout Request timeout in seconds.
     * @param int $connectTimeout Connection timeout in seconds.
     */
    public function __construct(
        string $apiKey,
        string $model = 'claude-sonnet-4-20250514',
        int $maxTokens = 4096,
        string $baseUrl = 'https://api.anthropic.com',
        string $anthropicVersion = '2023-06-01',
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        parent::__construct($model, $timeout, $connectTimeout);

        $this->apiKey = $apiKey;
        $this->maxTokens = $maxTokens;
        $this->baseUrl = $baseUrl;
        $this->anthropicVersion = $anthropicVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array {
        return [
            'api_key' => $this->apiKey,
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'base_url' => $this->baseUrl,
            'anthropic_version' => $this->anthropicVersion,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];
    }
}
