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
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;

/**
 * RAG provider backed by Vertex AI RAG API (managed RAG corpora).
 *
 * Uses the Vertex AI RAG API (aiplatform.googleapis.com) to retrieve contexts
 * from managed RAG corpora. Authentication is handled via {@see GoogleAuth}
 * supporting service accounts and ADC.
 *
 * Example:
 * ```php
 * $config = new GoogleRagConfig(
 *     projectId: 'my-project',
 *     location: 'us-central1',
 *     corpusId: '1234567890',
 *     credentials: '/path/to/service-account.json',
 * );
 *
 * $provider = new GoogleRagProvider($config);
 *
 * // Retrieve contexts
 * $results = $provider->retrieve('What is PHP?', topK: 5);
 *
 * // Delete a RAG file
 * $provider->delete('rag-file-id');
 * ```
 *
 * @author Ibrahim
 */
class GoogleRagProvider implements RagProviderInterface {
    /**
     * Google authentication handler.
     *
     * @var GoogleAuth
     */
    private GoogleAuth $auth;

    /**
     * Provider configuration.
     *
     * @var GoogleRagConfig
     */
    private GoogleRagConfig $config;

    /**
     * HTTP client for making API requests.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $httpClient;

    /**
     * Creates a new GoogleRagProvider instance.
     *
     * @param GoogleRagConfig $config Configuration for the RAG corpus.
     * @param HttpClientInterface|null $httpClient HTTP client instance. Defaults to CurlHttpClient.
     */
    public function __construct(GoogleRagConfig $config, ?HttpClientInterface $httpClient = null) {
        $this->config = $config;
        $this->auth = new GoogleAuth($config->credentials);
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * Deletes a RAG file from the Vertex AI RAG corpus.
     *
     * @param string $id The RAG file ID to delete.
     *
     * @throws ProviderException If the deletion request fails.
     */
    public function delete(string $id): void {
        $url = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/ragCorpora/%s/ragFiles/%s',
            $this->config->location,
            $this->config->projectId,
            $this->config->location,
            $this->config->corpusId,
            $id,
        );

        $request = new HttpRequest(
            'DELETE',
            $url,
            $this->auth->getAuthHeaders(),
        );

        $response = $this->httpClient->send($request);

        if (!$response->isSuccess()) {
            throw new ProviderException(
                sprintf('Failed to delete RAG file "%s": %s', $id, $response->getBody()),
                $response->getStatusCode(),
            );
        }
    }

    /**
     * Returns the provider configuration.
     *
     * @return GoogleRagConfig The configuration instance.
     */
    public function getConfig(): GoogleRagConfig {
        return $this->config;
    }

    /**
     * Inline text ingestion is not supported by GoogleRagProvider.
     *
     * The Vertex AI RAG API requires files to be uploaded from GCS or via
     * multipart upload. Use the Vertex AI console, the GCS upload path,
     * or the importRagFiles API directly.
     *
     * @param string $content The text content (unused).
     * @param array<string, mixed> $metadata Optional metadata (unused).
     *
     * @return string Never returns — always throws.
     *
     * @throws UnsupportedFeatureException Always thrown.
     */
    public function ingest(string $content, array $metadata = []): string {
        throw new UnsupportedFeatureException(
            'inline_text_ingestion',
            'GoogleRagProvider',
        );
    }

    /**
     * Retrieves relevant contexts from the Vertex AI RAG corpus.
     *
     * Sends a retrieveContexts request to the Vertex AI RAG API and converts
     * the response into RetrievalResult objects.
     *
     * @param string $query The search query.
     * @param int $topK Maximum number of results to return.
     * @param array<string, mixed> $options Additional provider-specific options.
     *
     * @return RetrievalResult[] Results sorted by score descending.
     *
     * @throws ProviderException If the API request fails.
     */
    public function retrieve(string $query, int $topK = 5, array $options = []): array {
        $url = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/ragCorpora/%s:retrieveContexts',
            $this->config->location,
            $this->config->projectId,
            $this->config->location,
            $this->config->corpusId,
        );

        $body = [
            'query' => [
                'text' => $query,
                'ragRetrievalConfig' => [
                    'topK' => $topK,
                ],
            ],
        ];

        $request = new HttpRequest(
            'POST',
            $url,
            array_merge($this->auth->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            json_encode($body),
        );

        $response = $this->httpClient->send($request);

        if (!$response->isSuccess()) {
            throw new ProviderException(
                sprintf('Vertex AI RAG retrieveContexts failed: %s', $response->getBody()),
                $response->getStatusCode(),
            );
        }

        $data = $response->getJson();

        $results = [];
        $contexts = $data['contexts']['contexts'] ?? [];

        foreach ($contexts as $context) {
            $text = $context['text'] ?? '';
            $score = isset($context['score']) ? (float) $context['score'] : 0.0;
            $sourceUri = $context['sourceUri'] ?? '';

            $id = md5($text);

            $metadata = [];

            if ($sourceUri !== '') {
                $metadata['source'] = $sourceUri;
            }

            $results[] = new RetrievalResult(
                id: $id,
                text: $text,
                score: $score,
                metadata: $metadata,
            );
        }

        // Sort by score descending
        usort($results, function (RetrievalResult $a, RetrievalResult $b): int
        {
            return $b->getScore() <=> $a->getScore();
        });

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
}
