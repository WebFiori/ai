<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Google;

use WebFiori\Ai\Provider\ClientConfig;

/**
 * Configuration for the Google (Gemini) provider client.
 *
 * Supports both the Gemini API (generativelanguage.googleapis.com) and
 * Vertex AI (aiplatform.googleapis.com).
 *
 * Authentication priority: apiKey > accessToken > credentials.
 *
 * @author Ibrahim
 */
class GoogleClientConfig extends ClientConfig {
    /**
     * Pre-fetched OAuth2 access token.
     *
     * If provided, credentials file is not used.
     *
     * @var string|null
     */
    public readonly ?string $accessToken;

    /**
     * Which API endpoint to use.
     *
     * @var GoogleApi
     */
    public readonly GoogleApi $api;

    /**
     * Gemini API key from Google AI Studio.
     *
     * Simplest auth method for the Gemini API.
     *
     * @var string|null
     */
    public readonly ?string $apiKey;

    /**
     * Which API version to use for chat completions.
     *
     * AUTO will detect based on model name (gemini-3.x+ uses Interactions).
     *
     * @var GoogleApiVersion
     */
    public readonly GoogleApiVersion $apiVersion;

    /**
     * Path to service account JSON file, or credentials as array.
     *
     * @var string|array<string, mixed>|null
     */
    public readonly string|array|null $credentials;

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
     * GCP region for Vertex AI.
     *
     * Use 'global' for automatic routing, or a specific region
     * (e.g., 'us-central1') for data residency requirements.
     *
     * @var string
     */
    public readonly string $location;

    /**
     * GCP project ID (required for Vertex AI).
     *
     * @var string|null
     */
    public readonly ?string $projectId;

    /**
     * Creates a new GoogleClientConfig instance.
     *
     * @param string $model The default model for chat completions.
     * @param string|null $apiKey Gemini API key from Google AI Studio.
     * @param string|null $projectId GCP project ID (required for Vertex AI).
     * @param string $location GCP region. Default is 'global'.
     * @param string|array<string, mixed>|null $credentials Service account JSON path or array.
     * @param string|null $accessToken Pre-fetched OAuth2 access token.
     * @param GoogleApi $api Which API to use. Default is GEMINI.
     * @param GoogleApiVersion $apiVersion API version for chat. Default is AUTO.
     * @param string $embeddingModel Model for embeddings.
     * @param string $imageModel Model for image generation.
     * @param int $timeout Request timeout in seconds.
     * @param int $connectTimeout Connection timeout in seconds.
     */
    public function __construct(
        string $model = 'gemini-2.5-flash',
        ?string $apiKey = null,
        ?string $projectId = null,
        string $location = 'global',
        string|array|null $credentials = null,
        ?string $accessToken = null,
        GoogleApi $api = GoogleApi::GEMINI,
        GoogleApiVersion $apiVersion = GoogleApiVersion::AUTO,
        string $embeddingModel = 'text-embedding-004',
        string $imageModel = 'gemini-2.5-flash-preview-image-generation',
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        parent::__construct($model, $timeout, $connectTimeout);

        $this->apiKey = $apiKey;
        $this->projectId = $projectId;
        $this->location = $location;
        $this->credentials = $credentials;
        $this->accessToken = $accessToken;
        $this->api = $api;
        $this->apiVersion = $apiVersion;
        $this->embeddingModel = $embeddingModel;
        $this->imageModel = $imageModel;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array {
        return [
            'model' => $this->model,
            'api_key' => $this->apiKey,
            'project_id' => $this->projectId,
            'location' => $this->location,
            'credentials' => $this->credentials,
            'access_token' => $this->accessToken,
            'api' => $this->api->value,
            'api_version' => $this->apiVersion->value,
            'embedding_model' => $this->embeddingModel,
            'image_model' => $this->imageModel,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];
    }
}
