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

use WebFiori\Ai\Auth\AwsCredentialChain;
use WebFiori\Ai\Auth\AwsSigner;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;

/**
 * RAG provider backed by AWS Bedrock Knowledge Bases.
 *
 * Performs semantic retrieval against an existing Bedrock Knowledge Base
 * using the `bedrock-agent-runtime` Retrieve API. Documents must be ingested
 * via S3 data sources and the AWS console/SDK — direct ingestion and deletion
 * are not supported through this provider.
 *
 * Example:
 * ```php
 * $config = new BedrockKbConfig(
 *     region: 'us-east-1',
 *     knowledgeBaseId: 'KBXXXXXXXX',
 *     accessKey: 'AKIA...',
 *     secretKey: 'wJal...',
 * );
 *
 * $provider = new BedrockKnowledgeBaseProvider($config);
 * $results = $provider->retrieve('What is PHP?', topK: 5);
 *
 * foreach ($results as $result) {
 *     echo $result->getText() . "\n";
 *     echo "Score: " . $result->getScore() . "\n";
 *     echo "Source: " . $result->getSource() . "\n";
 * }
 * ```
 *
 * @author Ibrahim
 */
class BedrockKnowledgeBaseProvider implements RagProviderInterface {
    /**
     * Configuration for the Bedrock Knowledge Base.
     *
     * @var BedrockKbConfig
     */
    private BedrockKbConfig $config;

    /**
     * HTTP client for making API requests.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $httpClient;

    /**
     * AWS SigV4 signer for authenticating requests.
     *
     * @var AwsSigner
     */
    private AwsSigner $signer;

    /**
     * Creates a new BedrockKnowledgeBaseProvider instance.
     *
     * If credentials are not provided in the config, they are resolved
     * automatically via {@see AwsCredentialChain}.
     *
     * @param BedrockKbConfig $config Knowledge base configuration.
     * @param HttpClientInterface|null $httpClient Optional HTTP client (defaults to CurlHttpClient).
     *
     * @throws ProviderException If credentials cannot be resolved.
     */
    public function __construct(BedrockKbConfig $config, ?HttpClientInterface $httpClient = null) {
        $this->config = $config;
        $this->httpClient = $httpClient ?? new CurlHttpClient();

        if ($config->accessKey !== null && $config->secretKey !== null) {
            $this->signer = new AwsSigner(
                $config->accessKey,
                $config->secretKey,
                $config->region,
                'bedrock-agent-runtime',
                $config->sessionToken,
            );
        } else {
            $chain = new AwsCredentialChain();
            $creds = $chain->resolve();

            if ($creds === null) {
                throw new ProviderException(
                    'Unable to resolve AWS credentials. Provide accessKey/secretKey in config or configure environment credentials.',
                    0
                );
            }

            $this->signer = new AwsSigner(
                $creds['access_key'],
                $creds['secret_key'],
                $config->region,
                'bedrock-agent-runtime',
                $creds['session_token'] ?? null,
            );
        }
    }

    /**
     * Deletion is not supported — Bedrock Knowledge Bases manage documents through S3.
     *
     * Delete the source file from S3 and re-sync the data source to remove
     * documents from the knowledge base.
     *
     * @param string $id Not used.
     *
     * @throws UnsupportedFeatureException Always thrown.
     */
    public function delete(string $id): void {
        throw new UnsupportedFeatureException('deletion', 'bedrock_knowledge_base');
    }

    /**
     * Returns the configuration object.
     *
     * @return BedrockKbConfig The knowledge base configuration.
     */
    public function getConfig(): BedrockKbConfig {
        return $this->config;
    }

    /**
     * Ingestion is not supported — Bedrock Knowledge Bases use S3 data sources.
     *
     * Upload content to your configured S3 bucket and start an ingestion job
     * via the AWS console or bedrock-agent API.
     *
     * @param string $content Not used.
     * @param array<string, mixed> $metadata Not used.
     *
     * @return string Never returns.
     *
     * @throws UnsupportedFeatureException Always thrown.
     */
    public function ingest(string $content, array $metadata = []): string {
        throw new UnsupportedFeatureException('ingestion', 'bedrock_knowledge_base');
    }

    /**
     * Retrieves relevant documents from the Bedrock Knowledge Base.
     *
     * Calls the Bedrock Agent Runtime Retrieve API with the given query
     * and returns matching results as RetrievalResult objects.
     *
     * @param string $query The search query.
     * @param int $topK Maximum number of results to return.
     * @param array<string, mixed> $options Additional options. Supported keys:
     *        - 'filter': Metadata filter for retrieval.
     *        - 'search_type': Override search type ('SEMANTIC' or 'HYBRID').
     *
     * @return RetrievalResult[] Results sorted by relevance descending.
     *
     * @throws ProviderException If the API request fails.
     */
    public function retrieve(string $query, int $topK = 5, array $options = []): array {
        $url = sprintf(
            'https://bedrock-agent-runtime.%s.amazonaws.com/knowledgebases/%s/retrieve',
            $this->config->region,
            $this->config->knowledgeBaseId
        );

        $body = [
            'retrievalQuery' => [
                'text' => $query,
            ],
            'retrievalConfiguration' => [
                'vectorSearchConfiguration' => [
                    'numberOfResults' => $topK,
                ],
            ],
        ];

        if (isset($options['filter'])) {
            $body['retrievalConfiguration']['vectorSearchConfiguration']['filter'] = $options['filter'];
        }

        if (isset($options['search_type'])) {
            $body['retrievalConfiguration']['vectorSearchConfiguration']['overrideSearchType'] = $options['search_type'];
        }

        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $signedHeaders = $this->signer->sign('POST', $url, $headers, $jsonBody);

        $request = new HttpRequest('POST', $url, $signedHeaders, $jsonBody);
        $response = $this->httpClient->send($request);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $responseData = $response->getJson();
            $message = $responseData['message'] ?? $response->getBody();

            throw new ProviderException(
                "Bedrock Knowledge Base retrieve failed: $message",
                $statusCode
            );
        }

        $responseData = $response->getJson();

        return $this->parseRetrievalResults($responseData);
    }

    /**
     * Sets the HTTP client used for API requests.
     *
     * Useful for testing with a mock HTTP client.
     *
     * @param HttpClientInterface $httpClient The HTTP client to use.
     */
    public function setHttpClient(HttpClientInterface $httpClient): void {
        $this->httpClient = $httpClient;
    }

    /**
     * Parses the Bedrock Knowledge Base Retrieve API response into RetrievalResult objects.
     *
     * @param array<string, mixed> $responseData The decoded JSON response.
     *
     * @return RetrievalResult[] Parsed results.
     */
    private function parseRetrievalResults(array $responseData): array {
        $results = [];
        $retrievalResults = $responseData['retrievalResults'] ?? [];

        foreach ($retrievalResults as $item) {
            $text = $item['content']['text'] ?? '';
            $score = (float) ($item['score'] ?? 0.0);

            // Build metadata from the response
            $metadata = [];

            if (isset($item['metadata'])) {
                $metadata = $item['metadata'];
            }

            // Extract S3 source URI if available
            $source = null;

            if (isset($item['location']['s3Location']['uri'])) {
                $source = $item['location']['s3Location']['uri'];
                $metadata['source'] = $source;
            } elseif (isset($item['location']['type'])) {
                $metadata['location_type'] = $item['location']['type'];
            }

            // Generate an ID from content hash if not provided
            $id = $item['id'] ?? hash('sha256', $text);

            $results[] = new RetrievalResult($id, $text, $score, $metadata);
        }

        return $results;
    }
}
