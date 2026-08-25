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

use WebFiori\Ai\Auth\GoogleAuth;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;

/**
 * RAG provider backed by Google Search (Discovery Engine).
 *
 * Uses the Google Discovery Engine API to search, ingest, and delete
 * documents in a Google Search data store. Authentication is handled
 * via {@see GoogleAuth} supporting service accounts and ADC.
 *
 * Example:
 * ```php
 * $config = new GoogleSearchConfig(
 *     projectId: 'my-project',
 *     location: 'global',
 *     dataStoreId: 'my-datastore',
 *     credentials: '/path/to/service-account.json',
 * );
 *
 * $provider = new GoogleSearchProvider($config);
 *
 * // Search
 * $results = $provider->retrieve('What is PHP?', topK: 5);
 *
 * // Ingest
 * $id = $provider->ingest('PHP is a server-side language.', ['source' => 'docs']);
 *
 * // Delete
 * $provider->delete($id);
 * ```
 *
 * @author Ibrahim
 */
class GoogleSearchProvider implements RagProviderInterface {
    /**
     * Google authentication handler.
     *
     * @var GoogleAuth
     */
    private GoogleAuth $auth;

    /**
     * Provider configuration.
     *
     * @var GoogleSearchConfig
     */
    private GoogleSearchConfig $config;

    /**
     * HTTP client for making API requests.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $httpClient;

    /**
     * Creates a new GoogleSearchProvider instance.
     *
     * @param GoogleSearchConfig $config Configuration for the data store.
     * @param HttpClientInterface|null $httpClient HTTP client instance. Defaults to CurlHttpClient.
     */
    public function __construct(GoogleSearchConfig $config, ?HttpClientInterface $httpClient = null) {
        $this->config = $config;
        $this->auth = new GoogleAuth($config->credentials);
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * Deletes a document from the Google Search data store.
     *
     * @param string $id The document ID to delete.
     */
    public function delete(string $id): void {
        $url = sprintf(
            'https://discoveryengine.googleapis.com/v1/projects/%s/locations/%s/collections/%s/dataStores/%s/branches/default_branch/documents/%s',
            $this->config->projectId,
            $this->config->location,
            $this->config->collectionId,
            $this->config->dataStoreId,
            $id,
        );

        $request = new HttpRequest(
            'DELETE',
            $url,
            $this->auth->getAuthHeaders(),
        );

        $this->httpClient->send($request);
    }

    /**
     * Returns the provider configuration.
     *
     * @return GoogleSearchConfig The configuration instance.
     */
    public function getConfig(): GoogleSearchConfig {
        return $this->config;
    }

    /**
     * Ingests content into the Google Search data store.
     *
     * Creates a new document in the default branch of the data store
     * with the provided content encoded as base64 raw bytes.
     *
     * @param string $content The text content to ingest.
     * @param array<string, mixed> $metadata Optional metadata stored as structData.
     *
     * @return string The generated document ID.
     */
    public function ingest(string $content, array $metadata = []): string {
        $documentId = 'doc_'.substr(md5($content.microtime(true)), 0, 12);

        $url = sprintf(
            'https://discoveryengine.googleapis.com/v1/projects/%s/locations/%s/collections/%s/dataStores/%s/branches/default_branch/documents',
            $this->config->projectId,
            $this->config->location,
            $this->config->collectionId,
            $this->config->dataStoreId,
        );

        $body = [
            'id' => $documentId,
            'content' => [
                'rawBytes' => base64_encode($content),
                'mimeType' => 'text/plain',
            ],
        ];

        if (!empty($metadata)) {
            $body['structData'] = $metadata;
        }

        $request = new HttpRequest(
            'POST',
            $url,
            array_merge($this->auth->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            json_encode($body),
        );

        $response = $this->httpClient->send($request);
        $data = $response->getJson();

        // Return the document ID from response if available, otherwise the generated one
        return $data['id'] ?? $documentId;
    }

    /**
     * Retrieves relevant documents from the Google Search data store.
     *
     * Sends a search request to the Discovery Engine API and converts
     * the response into RetrievalResult objects.
     *
     * @param string $query The search query.
     * @param int $topK Maximum number of results to return.
     * @param array<string, mixed> $options Additional provider-specific options. Supports:
     *        - 'filter': string filter expression for the search.
     *        - 'boost_spec': array boost specification for ranking.
     *
     * @return RetrievalResult[] Results sorted by relevance descending.
     */
    public function retrieve(string $query, int $topK = 5, array $options = []): array {
        $url = sprintf(
            'https://discoveryengine.googleapis.com/v1/projects/%s/locations/%s/collections/%s/dataStores/%s/servingConfigs/default_search:search',
            $this->config->projectId,
            $this->config->location,
            $this->config->collectionId,
            $this->config->dataStoreId,
        );

        $body = [
            'query' => $query,
            'pageSize' => $topK,
        ];

        if (isset($options['filter'])) {
            $body['filter'] = $options['filter'];
        }

        if (isset($options['boost_spec'])) {
            $body['boostSpec'] = $options['boost_spec'];
        }

        $request = new HttpRequest(
            'POST',
            $url,
            array_merge($this->auth->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            json_encode($body),
        );

        $response = $this->httpClient->send($request);
        $data = $response->getJson();

        $results = [];

        foreach ($data['results'] ?? [] as $index => $item) {
            $document = $item['document'] ?? [];
            $documentId = $document['id'] ?? ('result_'.$index);

            // Extract text from derivedStructData or structData
            $text = $this->extractText($document);

            // Score may come from the response or be inferred from position
            $score = 1.0 - ($index * 0.01);

            if (isset($item['relevanceScore'])) {
                $score = (float) $item['relevanceScore'];
            }

            $metadata = $document['structData'] ?? [];

            if (isset($document['derivedStructData']['link'])) {
                $metadata['link'] = $document['derivedStructData']['link'];
            }

            $results[] = new RetrievalResult(
                id: $documentId,
                text: $text,
                score: $score,
                metadata: $metadata,
            );
        }

        return $results;
    }

    /**
     * Sets the HTTP client used for API requests.
     *
     * @param HttpClientInterface $httpClient The HTTP client instance.
     *
     * @return self For method chaining.
     */
    public function setHttpClient(HttpClientInterface $httpClient): self {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * Extracts text content from a Discovery Engine document response.
     *
     * Attempts to extract text from derivedStructData (extractive answers,
     * snippets) or falls back to structData fields.
     *
     * @param array<string, mixed> $document The document object from the API response.
     *
     * @return string The extracted text content.
     */
    private function extractText(array $document): string {
        $derived = $document['derivedStructData'] ?? [];

        // Try extractive answers first
        if (!empty($derived['extractive_answers'])) {
            $answers = $derived['extractive_answers'];

            if (isset($answers[0]['content'])) {
                return $answers[0]['content'];
            }
        }

        // Try extractive segments
        if (!empty($derived['extractive_segments'])) {
            $segments = $derived['extractive_segments'];

            if (isset($segments[0]['content'])) {
                return $segments[0]['content'];
            }
        }

        // Try snippets
        if (!empty($derived['snippets'])) {
            $snippets = $derived['snippets'];

            if (isset($snippets[0]['snippet'])) {
                return $snippets[0]['snippet'];
            }
        }

        // Try title from derivedStructData
        if (isset($derived['title'])) {
            return $derived['title'];
        }

        // Fallback to structData content
        $structData = $document['structData'] ?? [];

        if (isset($structData['content'])) {
            return $structData['content'];
        }

        if (isset($structData['text'])) {
            return $structData['text'];
        }

        if (isset($structData['title'])) {
            return $structData['title'];
        }

        return '';
    }
}
