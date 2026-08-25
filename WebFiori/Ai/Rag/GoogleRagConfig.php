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
 * Configuration for Google Vertex AI RAG (managed RAG corpora) provider.
 *
 * Holds connection parameters needed to interact with the Vertex AI RAG API
 * (aiplatform.googleapis.com) for managed RAG corpora, including project,
 * location, corpus ID, and authentication credentials.
 *
 * Example:
 * ```php
 * $config = new GoogleRagConfig(
 *     projectId: 'my-gcp-project',
 *     location: 'us-central1',
 *     corpusId: '1234567890',
 *     credentials: '/path/to/service-account.json',
 * );
 * ```
 *
 * @author Ibrahim
 */
class GoogleRagConfig {
    /**
     * The Vertex AI RAG corpus ID.
     *
     * This is the unique identifier of the RAG corpus within the project
     * and location.
     *
     * @var string
     */
    public readonly string $corpusId;

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
     * The GCP region/location for the RAG corpus.
     *
     * Common values: 'us-central1', 'europe-west4', etc.
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
     * Creates a new GoogleRagConfig instance.
     *
     * @param string $projectId The Google Cloud project ID.
     * @param string $location The GCP region/location (e.g., 'us-central1').
     * @param string $corpusId The Vertex AI RAG corpus ID.
     * @param string|array<string, mixed>|null $credentials Path to service account JSON,
     *        credentials array, or null for Application Default Credentials (ADC).
     */
    public function __construct(
        string $projectId,
        string $location,
        string $corpusId,
        string|array|null $credentials = null,
    ) {
        $this->projectId = $projectId;
        $this->location = $location;
        $this->corpusId = $corpusId;
        $this->credentials = $credentials;
    }
}
