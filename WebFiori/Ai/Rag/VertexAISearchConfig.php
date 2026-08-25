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
 * Configuration for Vertex AI Search (Discovery Engine) RAG provider.
 *
 * Holds all connection parameters needed to interact with a Vertex AI Search
 * data store, including project, location, data store ID, and authentication
 * credentials.
 *
 * Example:
 * ```php
 * $config = new VertexAISearchConfig(
 *     projectId: 'my-gcp-project',
 *     location: 'global',
 *     dataStoreId: 'my-datastore-id',
 *     credentials: '/path/to/service-account.json',
 * );
 * ```
 *
 * @author Ibrahim
 */
class VertexAISearchConfig {
    /**
     * The collection ID within the data store.
     *
     * Defaults to 'default_collection' which is the standard collection
     * created by Vertex AI Search.
     *
     * @var string
     */
    public readonly string $collectionId;

    /**
     * Service account credentials for authentication.
     *
     * Can be one of:
     * - A string path to a service account JSON key file.
     * - An associative array containing the decoded service account credentials.
     * - null to use Application Default Credentials (ADC).
     *
     * @var string|array<string, mixed>|null
     */
    public readonly string|array|null $credentials;

    /**
     * The Vertex AI Search data store ID.
     *
     * This is the unique identifier of the data store within the project
     * and location.
     *
     * @var string
     */
    public readonly string $dataStoreId;

    /**
     * The GCP region/location for the data store.
     *
     * Common values: 'global', 'us', 'eu', 'us-central1', etc.
     *
     * @var string
     */
    public readonly string $location;

    /**
     * The Google Cloud project ID.
     *
     * @var string
     */
    public readonly string $projectId;

    /**
     * Creates a new VertexAISearchConfig instance.
     *
     * @param string $projectId The Google Cloud project ID.
     * @param string $location The GCP region/location (e.g., 'global', 'us').
     * @param string $dataStoreId The Vertex AI Search data store ID.
     * @param string|array<string, mixed>|null $credentials Path to service account JSON,
     *        credentials array, or null for Application Default Credentials (ADC).
     * @param string $collectionId The collection ID within the data store.
     */
    public function __construct(
        string $projectId,
        string $location,
        string $dataStoreId,
        string|array|null $credentials = null,
        string $collectionId = 'default_collection',
    ) {
        $this->projectId = $projectId;
        $this->location = $location;
        $this->dataStoreId = $dataStoreId;
        $this->credentials = $credentials;
        $this->collectionId = $collectionId;
    }
}
