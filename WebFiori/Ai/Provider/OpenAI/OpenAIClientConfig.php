<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\OpenAI;

use WebFiori\Ai\Provider\ClientConfig;

/**
 * Configuration for the OpenAI provider client.
 *
 * @author Ibrahim
 */
class OpenAIClientConfig extends ClientConfig {
    /**
     * OpenAI API key.
     *
     * @var string
     */
    public readonly string $apiKey;

    /**
     * Base URL for the API.
     *
     * Override for Azure OpenAI or compatible APIs.
     *
     * @var string
     */
    public readonly string $baseUrl;

    /**
     * The model to use for embeddings.
     *
     * @var string
     */
    public readonly string $embeddingModel;

    /**
     * The model to use for image generation.
     *
     * @var string
     */
    public readonly string $imageModel;

    /**
     * OpenAI organization ID (optional).
     *
     * @var string|null
     */
    public readonly ?string $organization;

    /**
     * Creates a new OpenAIClientConfig instance.
     *
     * @param string $apiKey OpenAI API key.
     * @param string $model The default model for chat completions.
     * @param string|null $organization OpenAI organization ID.
     * @param string $baseUrl Base URL for the API.
     * @param string $embeddingModel Model for embeddings.
     * @param string $imageModel Model for image generation.
     * @param int $timeout Request timeout in seconds.
     * @param int $connectTimeout Connection timeout in seconds.
     */
    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        ?string $organization = null,
        string $baseUrl = 'https://api.openai.com/v1',
        string $embeddingModel = 'text-embedding-3-small',
        string $imageModel = 'dall-e-3',
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        parent::__construct($model, $timeout, $connectTimeout);

        $this->apiKey = $apiKey;
        $this->organization = $organization;
        $this->baseUrl = $baseUrl;
        $this->embeddingModel = $embeddingModel;
        $this->imageModel = $imageModel;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array {
        return [
            'api_key' => $this->apiKey,
            'model' => $this->model,
            'organization' => $this->organization,
            'base_url' => $this->baseUrl,
            'embedding_model' => $this->embeddingModel,
            'image_model' => $this->imageModel,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];
    }
}
