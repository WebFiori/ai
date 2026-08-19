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

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\GeneratedImage;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\SseParser;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Tool\BuiltInToolInterface;
use WebFiori\Ai\Tool\GoogleBuiltInTool;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolInterface;
use WebFiori\Ai\Usage;

/**
 * Google Cloud Google (Gemini) provider implementation.
 *
 * Supports chat completions, streaming, embeddings, and image generation
 * via the Google API or the Gemini API using Gemini models.
 *
 * Configuration options:
 * - 'api' (optional): Which API endpoint to use. Either 'gemini' (default)
 *   or 'vertex_ai'. The Gemini API (generativelanguage.googleapis.com) is simpler
 *   and works with the free tier. Gemini Enterprise Agent Platform (previously
 *   Vertex AI) at aiplatform.googleapis.com is the enterprise endpoint requiring
 *   project_id.
 * - 'api_key' (optional): Gemini API key from Google AI Studio. Simplest auth
 *   method for the Gemini API. The key is passed as a query parameter.
 * - 'project_id' (required for vertex_ai API): GCP project ID.
 * - 'location' (optional for vertex_ai API): GCP region (e.g., 'us-central1').
 *   Defaults to 'global' which uses Google's global endpoint and automatic routing.
 *   Use a specific region if you have data residency requirements.
 * - 'model' (optional): Default model. Defaults to 'gemini-2.5-flash'.
 * - 'credentials' (optional): Path to service account JSON file, or an array
 *   with the credentials.
 * - 'access_token' (optional): Pre-fetched OAuth2 access token. If provided,
 *   credentials file is not used.
 *
 * Authentication priority: api_key > access_token > credentials.
 *
 * @author Ibrahim
 */
class GoogleClient extends AbstractClient {
    /**
     * Cached OAuth2 access token.
     *
     * @var string|null
     */
    private ?string $accessToken = null;

    /**
     * Token expiration timestamp.
     *
     * @var int
     */
    private int $tokenExpiresAt = 0;

    /**
     * Creates a new GoogleClient instance.
     *
     * @param GoogleClientConfig $config Provider configuration.
     */
    public function __construct(GoogleClientConfig $config) {
        parent::__construct($config);
    }

    /**
     * Returns the provider name.
     *
     * @return string The provider identifier.
     */
    public function getName(): string {
        return 'google';
    }

    /**
     * Performs a health check by listing models or making a minimal request.
     *
     * @param int $timeout Timeout in seconds for the health check.
     *
     * @return \WebFiori\Ai\HealthCheckResult The health check result.
     */
    public function healthCheck(int $timeout = 5): \WebFiori\Ai\HealthCheckResult {
        $startTime = microtime(true);
        $checkMethod = 'models_list';

        try {
            // Build models list URL
            if ($this->isGeminiApi()) {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models';
                $apiKey = $this->getConfig('api_key');

                if ($apiKey !== null) {
                    $url .= '?key='.$apiKey;
                }
            } else {
                $projectId = $this->getConfig('project_id');
                $location = $this->getConfig('location', 'global');

                if ($location === 'global') {
                    $url = sprintf(
                        'https://aiplatform.googleapis.com/v1/projects/%s/locations/global/publishers/google/models',
                        $projectId
                    );
                } else {
                    $url = sprintf(
                        'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models',
                        $location,
                        $projectId,
                        $location
                    );
                }
            }

            $request = new HttpRequest(
                'GET',
                $url,
                $this->getHeaders(),
                ''
            );

            // Use a fresh HTTP client with short timeout
            $httpClient = new \WebFiori\Ai\Http\CurlHttpClient($timeout, $timeout);
            $response = $httpClient->send($request);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return \WebFiori\Ai\HealthCheckResult::success($latencyMs, $checkMethod);
            }

            $body = $response->getJson();
            $error = $body['error']['message'] ?? 'HTTP '.$response->getStatusCode();

            return \WebFiori\Ai\HealthCheckResult::failure($error, $latencyMs, $checkMethod);
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return \WebFiori\Ai\HealthCheckResult::failure($e->getMessage(), $latencyMs, $checkMethod);
        }
    }

    /**
     * Builds the generation config from options.
     *
     * @param array<string, mixed> $options The request options.
     *
     * @return array<string, mixed> The generationConfig object.
     */
    private function buildGenerationConfig(array $options): array {
        $config = [];

        if (isset($options['temperature'])) {
            $config['temperature'] = $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $config['maxOutputTokens'] = $options['max_tokens'];
        }

        if (isset($options['top_p'])) {
            $config['topP'] = $options['top_p'];
        }

        if (isset($options['stop'])) {
            $config['stopSequences'] = is_array($options['stop']) ? $options['stop'] : [$options['stop']];
        }

        // Structured output / JSON mode
        // Options:
        //   'json_mode' => true                        → plain JSON output
        //   'json_schema' => ['type' => 'object', ...] → JSON with schema validation
        if (isset($options['json_schema'])) {
            $config['responseMimeType'] = 'application/json';
            $config['responseSchema'] = $options['json_schema'];
        } elseif (!empty($options['json_mode'])) {
            $config['responseMimeType'] = 'application/json';
        }

        return $config;
    }

    /**
     * Extracts the system instruction from messages.
     *
     * Google handles system messages as a separate top-level field.
     *
     * @param Message[] $messages The conversation messages.
     *
     * @return array<string, mixed>|null The system instruction, or null.
     */
    private function extractSystemInstruction(array $messages): ?array {
        foreach ($messages as $message) {
            if ($message->getRole() === 'system') {
                return [
                    'parts' => [['text' => $message->getContent()]],
                ];
            }
        }

        return null;
    }

    /**
     * Fetches a file from a URL and returns base64-encoded data.
     *
     * @param string $url The file URL.
     *
     * @return array{mime_type: string, data: string}|null The file data or null on failure.
     */
    private function fetchFileFromUrl(string $url): ?array {
        $results = $this->fetchFilesFromUrls([$url]);

        return $results[0] ?? null;
    }

    /**
     * Fetches multiple files from URLs concurrently using curl_multi.
     *
     * For a single URL, this is equivalent to a sequential fetch.
     * For multiple URLs, requests are executed in parallel, reducing
     * total fetch time from O(n) to O(1) (time of slowest request).
     *
     * @param string[] $urls The URLs to fetch.
     *
     * @return array<int, array{mime_type: string, data: string}|null> Results indexed by input order.
     */
    private function fetchFilesFromUrls(array $urls): array {
        if (empty($urls)) {
            return [];
        }

        $multiHandle = curl_multi_init();
        $handles = [];

        // Initialize all cURL handles
        foreach ($urls as $index => $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'WebFiori-AI/1.0');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$index] = $ch;
        }

        // Execute all requests concurrently
        do {
            $status = curl_multi_exec($multiHandle, $active);

            if ($active) {
                curl_multi_select($multiHandle);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results
        $results = [];

        foreach ($handles as $index => $ch) {
            $content = curl_multi_getcontent($ch);

            if ($content !== false && $content !== '') {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $results[$index] = [
                    'mime_type' => $finfo->buffer($content),
                    'data' => base64_encode($content),
                ];
            } else {
                $this->logWarning('Failed to fetch file from URL', ['url' => $urls[$index]]);
                $results[$index] = null;
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Fetches an access token from the GCE metadata server.
     *
     * @return array{access_token: string, expires_in: int}|null
     */
    private function fetchFromMetadataServer(): ?array {
        $url = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Metadata-Flavor: Google\r\n",
                'timeout' => 2,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return $data;
    }

    /**
     * Formats ContentPart objects into Google parts format.
     *
     * @param \WebFiori\Ai\ContentPart[] $contentParts The content parts.
     *
     * @return array<int, array<string, mixed>> The formatted parts array.
     */
    private function formatContentParts(array $contentParts): array {
        $parts = [];

        // Collect all image URLs first for concurrent fetching
        $urlIndexes = [];

        foreach ($contentParts as $index => $part) {
            if ($part->getType() === \WebFiori\Ai\ContentPart::TYPE_IMAGE_URL) {
                $urlIndexes[$index] = $part->getData()['url'];
            }
        }

        // Fetch all URLs concurrently
        $fetchedFiles = !empty($urlIndexes)
            ? $this->fetchFilesFromUrls(array_values($urlIndexes))
            : [];

        // Map fetched results back to original indexes
        $urlKeys = array_keys($urlIndexes);
        $fetchedByIndex = [];

        foreach ($fetchedFiles as $i => $fileData) {
            $fetchedByIndex[$urlKeys[$i]] = $fileData;
        }

        // Build parts array
        foreach ($contentParts as $index => $part) {
            switch ($part->getType()) {
                case \WebFiori\Ai\ContentPart::TYPE_TEXT:
                    $parts[] = ['text' => $part->getData()['text']];

                    break;

                case \WebFiori\Ai\ContentPart::TYPE_IMAGE_URL:
                    $fileData = $fetchedByIndex[$index] ?? null;

                    if ($fileData !== null) {
                        $parts[] = [
                            'inlineData' => [
                                'mimeType' => $fileData['mime_type'],
                                'data' => $fileData['data'],
                            ],
                        ];
                    }

                    break;

                case \WebFiori\Ai\ContentPart::TYPE_IMAGE_BASE64:
                case \WebFiori\Ai\ContentPart::TYPE_DOCUMENT:
                    $data = $part->getData();
                    $parts[] = [
                        'inlineData' => [
                            'mimeType' => $data['mime_type'],
                            'data' => $data['data'],
                        ],
                    ];

                    break;

                case \WebFiori\Ai\ContentPart::TYPE_FILE_GCS:
                    $data = $part->getData();
                    $parts[] = [
                        'fileData' => [
                            'mimeType' => $data['mime_type'],
                            'fileUri' => $data['uri'],
                        ],
                    ];

                    break;
            }
        }

        return $parts;
    }

    /**
     * Formats Message objects into Google contents format.
     *
     * Filters out system messages (handled separately) and maps roles.
     *
     * @param Message[] $messages The messages to format.
     *
     * @return array<int, array<string, mixed>> The formatted contents array.
     */
    private function formatContents(array $messages): array {
        $contents = [];

        foreach ($messages as $message) {
            $role = $message->getRole();

            // System messages are handled via systemInstruction
            if ($role === 'system') {
                continue;
            }

            // Map roles: 'assistant' → 'model', 'tool' → 'function'
            $vertexRole = match ($role) {
                'assistant' => 'model',
                'tool' => 'function',
                default => $role,
            };

            $parts = [];

            // Handle multi-modal content
            if ($message->isMultiModal()) {
                $parts = array_merge($parts, $this->formatContentParts($message->getContentParts()));
            } elseif ($message->getContent() !== '') {
                $parts[] = ['text' => $message->getContent()];
            }

            if ($message->hasToolCalls()) {
                foreach ($message->getToolCalls() as $toolCall) {
                    // Use raw part if available to preserve provider-specific fields
                    // (e.g. thought_signature required by Gemini 2.5+)
                    if ($toolCall->getRawPart() !== null) {
                        $parts[] = $toolCall->getRawPart();
                        continue;
                    }

                    $args = $toolCall->getArguments();
                    $callData = [
                        'functionCall' => [
                            'name' => $toolCall->getName(),
                        ],
                    ];

                    if (!empty($args)) {
                        $callData['functionCall']['args'] = (object) $args;
                    }

                    $parts[] = $callData;
                }
            }

            if ($message->getToolResult() !== null) {
                $result = $message->getToolResult();
                $decoded = json_decode($result->getContent(), true);

                if (!is_array($decoded) || array_is_list($decoded)) {
                    $decoded = ['result' => $result->getContent()];
                }

                $part = [
                    'functionResponse' => [
                        'name' => $result->getToolCallId(),
                        'response' => (object) $decoded,
                    ],
                ];

                // Gemini requires all functionResponse parts for a single model turn
                // to be in one 'function' role content entry. Merge consecutive tool
                // messages into the last content entry instead of creating a new one.
                $last = end($contents);

                if ($last !== false && $last['role'] === 'function') {
                    $contents[count($contents) - 1]['parts'][] = $part;
                    continue;
                }

                $parts[] = $part;
            }

            if (!empty($parts)) {
                $contents[] = [
                    'role' => $vertexRole,
                    'parts' => $parts,
                ];
            }
        }

        return $contents;
    }

    /**
     * Formats ToolInterface instances into the Google tools format.
     *
     * Google uses 'functionDeclarations' inside a tools array.
     * Built-in tools (googleSearch, codeExecution, urlContext) are
     * added as separate objects alongside functionDeclarations.
     *
     * Vertex AI does not support mixing googleSearch with functionDeclarations.
     * An UnsupportedFeatureException is thrown in that case.
     *
     * @param ToolInterface[] $tools Custom function calling tools.
     * @param BuiltInToolInterface[] $builtInTools Provider-native built-in tools.
     *
     * @return array<int, array<string, mixed>> The formatted tools array.
     *
     * @throws UnsupportedFeatureException If a built-in tool is not supported,
     *         or if GOOGLE_SEARCH is combined with custom tools on Vertex AI.
     */
    private function formatTools(array $tools, array $builtInTools = []): array {
        $result = [];

        // Map built-in tool values to Google API keys
        $builtInMap = [
            'google_search' => 'googleSearch',
            'code_execution' => 'codeExecution',
            'url_context' => 'urlContext',
        ];

        $hasGoogleSearch = false;

        foreach ($builtInTools as $builtIn) {
            if (!($builtIn instanceof GoogleBuiltInTool)) {
                throw new UnsupportedFeatureException(
                    'built_in_tools:'.get_class($builtIn),
                    'GoogleClient'
                );
            }

            $apiKey = $builtInMap[$builtIn->getValue()] ?? null;

            if ($apiKey === null) {
                throw new UnsupportedFeatureException(
                    'built_in_tools:'.$builtIn->getValue(),
                    'GoogleClient'
                );
            }

            if ($builtIn === GoogleBuiltInTool::GOOGLE_SEARCH) {
                $hasGoogleSearch = true;
            }

            $result[] = [$apiKey => (object) []];
        }

        // Vertex AI does not support mixing googleSearch with functionDeclarations
        if ($hasGoogleSearch && count($tools) > 0 && !$this->isGeminiApi()) {
            throw new UnsupportedFeatureException(
                'GOOGLE_SEARCH + functionDeclarations combined',
                'Vertex AI'
            );
        }

        // Add function declarations if any custom tools provided
        if (count($tools) > 0) {
            $declarations = [];

            foreach ($tools as $tool) {
                $declarations[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ];
            }

            $result[] = ['functionDeclarations' => $declarations];
        }

        return $result;
    }

    /**
     * Generates an OAuth2 access token from service account credentials.
     *
     * Creates a self-signed JWT and exchanges it for an access token
     * via Google's token endpoint.
     *
     * @param array<string, string> $credentials The service account credentials.
     *
     * @return string The access token.
     *
     * @throws AuthenticationException If token generation fails.
     */
    private function generateAccessToken(array $credentials): string {
        $now = time();
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);

        $scope = 'https://www.googleapis.com/auth/cloud-platform';

        if ($this->isGeminiApi()) {
            $scope .= ' https://www.googleapis.com/auth/generative-language';
        }

        $claim = json_encode([
            'iss' => $credentials['client_email'] ?? '',
            'scope' => $scope,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $base64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $base64Claim = rtrim(strtr(base64_encode($claim), '+/', '-_'), '=');
        $signInput = $base64Header.'.'.$base64Claim;

        $privateKey = $credentials['private_key'] ?? '';
        $signature = '';
        $success = openssl_sign($signInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$success) {
            throw new AuthenticationException(
                'Failed to sign JWT for Google authentication.',
                401
            );
        }

        $base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $jwt = $signInput.'.'.$base64Signature;

        // Exchange JWT for access token
        $tokenRequest = new HttpRequest(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])
        );

        $tokenResponse = $this->getHttpClient()->send($tokenRequest);

        if (!$tokenResponse->isSuccess()) {
            throw new AuthenticationException(
                'Failed to obtain access token from Google: '.$tokenResponse->getBody(),
                $tokenResponse->getStatusCode()
            );
        }

        $tokenData = $tokenResponse->getJson();

        return $tokenData['access_token'] ?? '';
    }

    /**
     * Returns the access token for API requests.
     *
     * If an access_token is configured directly, uses that. Otherwise
     * generates one from the service account credentials.
     *
     * @return string The OAuth2 access token.
     *
     * @throws AuthenticationException If token generation fails.
     */
    private function getAccessToken(): string {
        // Use pre-configured access token
        $token = $this->getConfig('access_token');

        if ($token !== null) {
            return $token;
        }

        // Check cached token
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        // Generate from service account credentials (explicit config)
        $credentials = $this->getConfig('credentials');

        if (is_string($credentials) && is_file($credentials)) {
            $credentials = json_decode(file_get_contents($credentials), true);
        }

        if (is_array($credentials)) {
            $this->accessToken = $this->generateAccessToken($credentials);
            $this->tokenExpiresAt = time() + 3500;

            return $this->accessToken;
        }

        // ADC: GOOGLE_APPLICATION_CREDENTIALS environment variable
        $envPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');

        if (!empty($envPath) && is_file($envPath)) {
            $credentials = json_decode(file_get_contents($envPath), true);

            if (is_array($credentials)) {
                if (($credentials['type'] ?? '') === 'authorized_user') {
                    $token = $this->refreshGcloudToken($credentials);

                    if ($token !== null) {
                        $this->accessToken = $token;
                        $this->tokenExpiresAt = time() + 3500;

                        return $this->accessToken;
                    }
                } else {
                    $this->accessToken = $this->generateAccessToken($credentials);
                    $this->tokenExpiresAt = time() + 3500;

                    return $this->accessToken;
                }
            }
        }

        // ADC: gcloud default credentials file
        // Note: authorized_user credentials work with Vertex AI (cloud-platform scope)
        // but NOT with the free Gemini API (generativelanguage.googleapis.com),
        // which requires an api_key or service_account credentials.
        $gcloudPath = $this->getGcloudDefaultCredentialsPath();

        if (is_file($gcloudPath)) {
            $gcloudCreds = json_decode(file_get_contents($gcloudPath), true);

            if (is_array($gcloudCreds)) {
                // gcloud default credentials use a different format (authorized_user)
                if (($gcloudCreds['type'] ?? '') === 'authorized_user') {
                    $token = $this->refreshGcloudToken($gcloudCreds);

                    if ($token !== null) {
                        $this->accessToken = $token;
                        $this->tokenExpiresAt = time() + 3500;

                        return $this->accessToken;
                    }
                } else {
                    // Service account in gcloud path
                    $this->accessToken = $this->generateAccessToken($gcloudCreds);
                    $this->tokenExpiresAt = time() + 3500;

                    return $this->accessToken;
                }
            }
        }

        // ADC: GCE/GKE/Cloud Run metadata server
        $metadataToken = $this->fetchFromMetadataServer();

        if ($metadataToken !== null) {
            $this->accessToken = $metadataToken['access_token'];
            $this->tokenExpiresAt = time() + (int) ($metadataToken['expires_in'] ?? 3500) - 60;

            return $this->accessToken;
        }

        throw new AuthenticationException(
            'No Google credentials found. Provide "api_key", "access_token", "credentials", '.
            'set GOOGLE_APPLICATION_CREDENTIALS, run "gcloud auth application-default login", '.
            'or run on GCE/GKE/Cloud Run.',
            401
        );
    }

    /**
     * Returns the full endpoint URL for a given model and action.
     *
     * @param string $model The model identifier.
     * @param string $action The API action (e.g., 'generateContent', 'predict').
     *
     * @return string The full URL.
     */
    private function getEndpoint(string $model, string $action): string {
        if ($this->isGeminiApi()) {
            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:%s',
                $model,
                $action
            );

            $apiKey = $this->getConfig('api_key');

            if ($apiKey !== null) {
                $url .= '?key='.$apiKey;
            }

            return $url;
        }

        $projectId = $this->getConfig('project_id');
        $location = $this->getConfig('location', 'global');

        // Global location uses a single endpoint without regional subdomain
        if ($location === 'global') {
            return sprintf(
                'https://aiplatform.googleapis.com/v1/projects/%s/locations/global/publishers/google/models/%s:%s',
                $projectId,
                $model,
                $action
            );
        }

        // Regional locations use region-prefixed subdomain
        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:%s',
            $location,
            $projectId,
            $location,
            $model,
            $action
        );
    }

    /**
     * Returns the path to the gcloud application default credentials file.
     *
     * @return string
     */
    private function getGcloudDefaultCredentialsPath(): string {
        if (PHP_OS_FAMILY === 'Windows') {
            $appData = getenv('APPDATA') ?: '';

            return $appData.DIRECTORY_SEPARATOR.'gcloud'.DIRECTORY_SEPARATOR.'application_default_credentials.json';
        }

        $home = getenv('HOME') ?: '';

        return $home.'/.config/gcloud/application_default_credentials.json';
    }

    /**
     * Returns the HTTP headers for Google API requests.
     *
     * @return array<string, string> The headers array.
     */
    private function getHeaders(): array {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($this->getConfig('api_key') === null) {
            $headers['Authorization'] = 'Bearer '.$this->getAccessToken();
        }

        return $headers;
    }

    /**
     * Returns whether the Gemini API endpoint should be used.
     *
     * When 'api' is set to 'gemini', uses generativelanguage.googleapis.com.
     * Otherwise uses the Google aiplatform.googleapis.com endpoint.
     *
     * @return bool True if using the Gemini API, false for Vertex AI.
     */
    private function isGeminiApi(): bool {
        $api = $this->getConfig('api', GoogleApi::GEMINI->value);

        if ($api instanceof GoogleApi) {
            return $api === GoogleApi::GEMINI;
        }

        return $api === 'gemini' || $api === GoogleApi::GEMINI->value;
    }

    /**
     * Resolves the API version to use for a given model.
     *
     * If the config specifies an explicit version (not AUTO), that version is
     * returned. Otherwise, the version is auto-detected from the model name:
     * - gemini-3.x and above → INTERACTIONS
     * - all other models → GENERATE_CONTENT
     *
     * @param string $model The model name (e.g., 'gemini-2.5-flash', 'gemini-3.5-flash').
     *
     * @return GoogleApiVersion The resolved API version.
     */
    private function resolveApiVersion(string $model): GoogleApiVersion {
        $configured = $this->getConfig('api_version', GoogleApiVersion::AUTO->value);

        // Normalize to enum
        if ($configured instanceof GoogleApiVersion) {
            $version = $configured;
        } else {
            $version = GoogleApiVersion::from((string) $configured);
        }

        if ($version !== GoogleApiVersion::AUTO) {
            return $version;
        }

        // Auto-detect: gemini-3.x and above use Interactions API
        if (preg_match('/^gemini-(\d+)/', $model, $m) && (int) $m[1] >= 3) {
            return GoogleApiVersion::INTERACTIONS;
        }

        return GoogleApiVersion::GENERATE_CONTENT;
    }

    /**
     * Maps Google finish reason to a normalized string.
     *
     * @param string $reason The Google finish reason.
     *
     * @return string|null The normalized finish reason.
     */
    private function mapFinishReason(string $reason): ?string {
        return match ($reason) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY' => 'content_filter',
            'RECITATION' => 'content_filter',
            default => $reason !== '' ? strtolower($reason) : null,
        };
    }

    /**
     * Refreshes an access token using gcloud authorized_user credentials.
     *
     * @param array<string, string> $creds The authorized_user credentials.
     *
     * @return string|null The refreshed access token, or null on failure.
     */
    private function refreshGcloudToken(array $creds): ?string {
        $tokenRequest = new HttpRequest(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $creds['client_id'] ?? '',
                'client_secret' => $creds['client_secret'] ?? '',
                'refresh_token' => $creds['refresh_token'] ?? '',
                'grant_type' => 'refresh_token',
            ])
        );

        $response = $this->getHttpClient()->send($tokenRequest);

        if (!$response->isSuccess()) {
            return null;
        }

        $data = $response->getJson();

        return $data['access_token'] ?? null;
    }

    /**
     * Maps a size string to an aspect ratio hint for the prompt.
     *
     * @param string $size e.g. '1024x1024', '1792x1024'
     *
     * @return string|null Hint to append to prompt, or null for square.
     */
    private function sizeToAspectRatioHint(string $size): ?string {
        return match ($size) {
            '1792x1024', '1920x1080' => 'Aspect ratio: 16:9 landscape orientation.',
            '1024x1792', '1080x1920' => 'Aspect ratio: 9:16 portrait orientation.',
            '1024x768',  '1280x960' => 'Aspect ratio: 4:3 landscape orientation.',
            default => null, // square — no hint needed
        };
    }

    /**
     * Builds the HTTP request for a chat completion call.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    protected function buildChatRequest(array $messages, array $options): HttpRequest {
        $model = $options['model'] ?? $this->getConfig('model', 'gemini-2.5-flash');

        if ($this->resolveApiVersion($model) === GoogleApiVersion::INTERACTIONS) {
            throw new \WebFiori\Ai\Exception\UnsupportedFeatureException(
                'Interactions API (gemini-3.x+) is not yet implemented. Coming in v0.5.2.',
                'google'
            );
        }

        $body = [
            'contents' => $this->formatContents($messages),
        ];

        $systemInstruction = $this->extractSystemInstruction($messages);

        if ($systemInstruction !== null) {
            $body['systemInstruction'] = $systemInstruction;
        }

        $generationConfig = $this->buildGenerationConfig($options);

        if (!empty($generationConfig)) {
            $body['generationConfig'] = $generationConfig;
        }

        $customTools = $options['tools'] ?? [];
        $builtInTools = $options['built_in_tools'] ?? [];

        if (count($customTools) > 0 || count($builtInTools) > 0) {
            $body['tools'] = $this->formatTools($customTools, $builtInTools);
        }

        return new HttpRequest(
            'POST',
            $this->getEndpoint($model, 'generateContent'),
            $this->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Builds the HTTP request for an embeddings call.
     *
     * @param string|string[] $input The text input(s) to embed.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    protected function buildEmbedRequest(string|array $input, array $options): HttpRequest {
        $model = $options['model'] ?? $this->getConfig('embedding_model', 'text-embedding-004');
        $texts = is_array($input) ? $input : [$input];

        if ($this->isGeminiApi()) {
            if (count($texts) === 1) {
                $body = [
                    'model' => "models/$model",
                    'content' => ['parts' => [['text' => $texts[0]]]],
                ];

                return new HttpRequest(
                    'POST',
                    $this->getEndpoint($model, 'embedContent'),
                    $this->getHeaders(),
                    json_encode($body)
                );
            }

            $requests = [];

            foreach ($texts as $text) {
                $requests[] = [
                    'model' => "models/$model",
                    'content' => ['parts' => [['text' => $text]]],
                ];
            }

            $body = ['requests' => $requests];

            return new HttpRequest(
                'POST',
                $this->getEndpoint($model, 'batchEmbedContents'),
                $this->getHeaders(),
                json_encode($body)
            );
        }

        $instances = [];

        foreach ($texts as $text) {
            $instances[] = ['content' => $text];
        }

        $body = ['instances' => $instances];

        return new HttpRequest(
            'POST',
            $this->getEndpoint($model, 'predict'),
            $this->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Builds the HTTP request for an image generation call.
     *
     * @param ImageRequest $request The image generation request.
     *
     * @return HttpRequest The HTTP request to send.
     */
    protected function buildImageRequest(ImageRequest $request): HttpRequest {
        // Use Gemini native image generation (generateContent with IMAGE modality).
        // This works on both Gemini API and Vertex AI with -image model variants.
        // Falls back gracefully — Gemini image models output both text and inline PNG data.
        $model = $this->getConfig('image_model', 'gemini-2.5-flash-image');

        $parts = [['text' => $request->getPrompt()]];

        // Add negative prompt as an instruction if provided
        if ($request->getNegativePrompt() !== null) {
            $parts[0]['text'] .= ' Do NOT include: '.$request->getNegativePrompt();
        }

        // Add style guidance if provided
        if ($request->getStyle() !== null) {
            $parts[0]['text'] .= ' Style: '.$request->getStyle();
        }

        // Add aspect ratio guidance from size
        $aspectHint = $this->sizeToAspectRatioHint($request->getSize());

        if ($aspectHint !== null) {
            $parts[0]['text'] .= ' '.$aspectHint;
        }

        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ];

        // Request multiple images by repeating the prompt in separate turns
        // Gemini generates one image per call; for count > 1 we note it for the response parser
        $body['generationConfig']['candidateCount'] = min($request->getCount(), 4);

        return new HttpRequest(
            'POST',
            $this->getEndpoint($model, 'generateContent'),
            $this->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Builds an incremental chat request by appending only new messages.
     *
     * Decodes the previous request body and appends only the newly added
     * messages, avoiding re-formatting the entire conversation history.
     *
     * @param HttpRequest $previousRequest The previous HTTP request.
     * @param Message[] $allMessages All conversation messages.
     * @param Message[] $newMessages Only the new messages since last request.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The updated HTTP request with new messages appended.
     */
    protected function buildIncrementalChatRequest(
        HttpRequest $previousRequest,
        array $allMessages,
        array $newMessages,
        array $options
    ): HttpRequest {
        $body = json_decode($previousRequest->getBody(), true) ?? [];
        $formattedNew = $this->formatMessagesForIncremental($newMessages);

        if (!empty($formattedNew)) {
            $body['contents'] = array_merge($body['contents'] ?? [], $formattedNew);
        }

        return new HttpRequest(
            $previousRequest->getMethod(),
            $previousRequest->getUrl(),
            $previousRequest->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Builds the HTTP request for a streaming chat completion call.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    protected function buildStreamChatRequest(array $messages, array $options): HttpRequest {
        $model = $options['model'] ?? $this->getConfig('model', 'gemini-2.5-flash');

        if ($this->resolveApiVersion($model) === GoogleApiVersion::INTERACTIONS) {
            throw new \WebFiori\Ai\Exception\UnsupportedFeatureException(
                'Interactions API streaming (gemini-3.x+) is not yet implemented. Coming in v0.5.2.',
                'google'
            );
        }

        $body = [
            'contents' => $this->formatContents($messages),
        ];

        $systemInstruction = $this->extractSystemInstruction($messages);

        if ($systemInstruction !== null) {
            $body['systemInstruction'] = $systemInstruction;
        }

        $generationConfig = $this->buildGenerationConfig($options);

        if (!empty($generationConfig)) {
            $body['generationConfig'] = $generationConfig;
        }

        $customTools = $options['tools'] ?? [];
        $builtInTools = $options['built_in_tools'] ?? [];

        if (count($customTools) > 0 || count($builtInTools) > 0) {
            $body['tools'] = $this->formatTools($customTools, $builtInTools);
        }

        return new HttpRequest(
            'POST',
            $this->getEndpoint($model, 'streamGenerateContent').'?alt=sse',
            $this->getHeaders(),
            json_encode($body)
        );
    }

    /**
     * Executes the streaming chat request using the SSE parser.
     *
     * @param HttpRequest $request The HTTP request to send.
     * @param callable $onToken Token callback.
     * @param callable|null $onComplete Completion callback.
     * @param callable|null $onError Error callback.
     */
    protected function doStreamChat(
        HttpRequest $request,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void {
        $accumulatedContent = '';
        $model = $this->getConfig('model', 'gemini-2.5-flash');
        $finishReason = null;
        $usage = null;

        $parser = new SseParser(
            function (string $data) use ($onToken, &$accumulatedContent, &$finishReason, &$usage)
            {
                $json = json_decode($data, true);

                if ($json === null) {
                    return;
                }

                $candidates = $json['candidates'] ?? [];

                if (empty($candidates)) {
                    return;
                }

                $candidate = $candidates[0];
                $parts = $candidate['content']['parts'] ?? [];

                foreach ($parts as $part) {
                    if (isset($part['text']) && $part['text'] !== '') {
                        $token = $part['text'];
                        $accumulatedContent .= $token;
                        $onToken($token);
                    }
                }

                if (isset($candidate['finishReason'])) {
                    $finishReason = $this->mapFinishReason($candidate['finishReason']);
                }

                if (isset($json['usageMetadata'])) {
                    $usage = new Usage(
                        $json['usageMetadata']['promptTokenCount'] ?? 0,
                        $json['usageMetadata']['candidatesTokenCount'] ?? 0
                    );
                }
            }
        );

        try {
            $this->getHttpClient()->sendStreaming($request, function (string $chunk) use ($parser)
            {
                $parser->feed($chunk);
            });

            if ($onComplete !== null) {
                $message = new Message('assistant', $accumulatedContent);
                $response = new ChatResponse($message, $model, $usage, $finishReason);
                $onComplete($response);
            }
        } catch (StreamingException $e) {
            if ($onError !== null) {
                $onError($e);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Formats new messages for incremental request building.
     *
     * Overrides the default to format only new messages in Google's contents format,
     * avoiding re-formatting the entire conversation on each tool loop iteration.
     *
     * @param Message[] $messages The new messages to format.
     *
     * @return array<int, array<string, mixed>> Formatted contents.
     */
    protected function formatMessagesForIncremental(array $messages): array {
        return $this->formatContents($messages);
    }

    /**
     * Inspects an HTTP response and throws the appropriate exception for errors.
     *
     * @param HttpResponse $response The HTTP response to check.
     *
     * @throws AuthenticationException If status is 401 or 403.
     * @throws RateLimitException If status is 429.
     * @throws ProviderException If status indicates a server error.
     */
    protected function handleErrorResponse(HttpResponse $response): void {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = json_decode($response->getBody(), true);
        $error = $body['error'] ?? [];
        $errorMessage = $error['message'] ?? 'Unknown Google error';
        $errorCode = $error['status'] ?? null;

        if ($status === 401 || $status === 403) {
            throw new AuthenticationException($errorMessage, $status);
        }

        if ($status === 429) {
            $retryAfter = $response->getHeader('Retry-After');

            throw new RateLimitException(
                $errorMessage,
                $retryAfter !== null ? (int) $retryAfter : null
            );
        }

        throw new ProviderException($errorMessage, $status, $errorCode);
    }

    /**
     * Parses an HTTP response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from Google.
     *
     * @return ChatResponse The parsed chat response.
     */
    protected function parseChatResponse(HttpResponse $response): ChatResponse {
        $data = $response->getJson();

        $candidates = $data['candidates'] ?? [];

        if (empty($candidates)) {
            return new ChatResponse(
                new Message('assistant', ''),
                $this->getConfig('model', 'gemini-2.5-flash'),
                null,
                null
            );
        }

        $candidate = $candidates[0];
        $parts = $candidate['content']['parts'] ?? [];
        $content = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            // Skip internal thought parts (Gemini 2.5+ thinking mode)
            // These contain the model's reasoning, not the final answer.
            if (!empty($part['thought'])) {
                continue;
            }

            if (isset($part['text'])) {
                $content .= $part['text'];
            }

            if (isset($part['functionCall'])) {
                $toolCall = new ToolCall(
                    uniqid('call_'),
                    $part['functionCall']['name'],
                    $part['functionCall']['args'] ?? []
                );
                // Preserve the raw part so thought_signature and any other
                // provider-specific fields are replayed verbatim in follow-up turns.
                // Ensure args is always an object (never a list) when replaying.
                $rawPart = $part;

                if (isset($rawPart['functionCall']['args']) && (empty($rawPart['functionCall']['args']) || array_is_list($rawPart['functionCall']['args']))) {
                    $rawPart['functionCall']['args'] = (object) [];
                }

                $toolCall->setRawPart($rawPart);
                $toolCalls[] = $toolCall;
            }
        }

        $message = new Message(
            'assistant',
            $content,
            $toolCalls
        );

        // When Google Search grounding is active, the model may return the
        // answer via groundingMetadata.searchEntryPoint.renderedContent
        // instead of parts[].text. Fall back to that if content is empty.
        if ($content === '' && empty($toolCalls)) {
            $renderedContent = $candidate['groundingMetadata']['searchEntryPoint']['renderedContent'] ?? '';

            if ($renderedContent !== '') {
                $message = new Message('assistant', $renderedContent, []);
            }
        }

        $usage = null;

        if (isset($data['usageMetadata'])) {
            $usage = new Usage(
                $data['usageMetadata']['promptTokenCount'] ?? 0,
                $data['usageMetadata']['candidatesTokenCount'] ?? 0
            );
        }

        $finishReason = $this->mapFinishReason($candidate['finishReason'] ?? '');

        return new ChatResponse(
            $message,
            $data['modelVersion'] ?? $this->getConfig('model', 'gemini-2.5-flash'),
            $usage,
            $finishReason
        );
    }

    /**
     * Parses an HTTP response into an EmbeddingResponse.
     *
     * @param HttpResponse $response The HTTP response from Google.
     *
     * @return EmbeddingResponse The parsed embedding response.
     */
    protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
        $data = $response->getJson();
        $vectors = [];

        if ($this->isGeminiApi()) {
            if (isset($data['embedding'])) {
                // Single embedContent response
                $vectors[] = $data['embedding']['values'] ?? [];
            } else {
                // batchEmbedContents response
                foreach ($data['embeddings'] ?? [] as $embedding) {
                    $vectors[] = $embedding['values'] ?? [];
                }
            }
        } else {
            foreach ($data['predictions'] ?? [] as $prediction) {
                $vectors[] = $prediction['embeddings']['values'] ?? [];
            }
        }

        $model = $this->getConfig('embedding_model', 'text-embedding-004');

        return new EmbeddingResponse($vectors, $model);
    }

    /**
     * Parses an HTTP response into an ImageResponse.
     *
     * @param HttpResponse $response The HTTP response from Google.
     *
     * @return ImageResponse The parsed image response.
     */
    protected function parseImageResponse(HttpResponse $response): ImageResponse {
        $data = $response->getJson();
        $images = [];
        $textParts = [];

        // Gemini returns images as inlineData parts inside candidates
        foreach ($data['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['inlineData'])) {
                    $images[] = new GeneratedImage(
                        url: null,
                        base64: $part['inlineData']['data'] ?? null,
                    );
                } elseif (isset($part['text']) && trim($part['text']) !== '') {
                    $textParts[] = trim($part['text']);
                }
            }
        }

        $model = $this->getConfig('image_model', 'gemini-2.5-flash-image');

        // If the model described the image in text, attach it as revisedPrompt on first image
        if (!empty($images) && !empty($textParts)) {
            $description = implode(' ', $textParts);
            $images[0] = new GeneratedImage(
                url: null,
                base64: $images[0]->getBase64(),
                revisedPrompt: $description,
            );
        }

        return new ImageResponse($images, $model);
    }

    /**
     * Validates that required configuration options are present.
     *
     * @param array<string, mixed> $config The configuration to validate.
     *
     * @throws InvalidConfigException If required options are missing.
     */
    protected function validateConfig(array $config): void {
        if (!$this->isGeminiApi()) {
            if (empty($config['project_id'])) {
                throw new InvalidConfigException(
                    'The "project_id" configuration option is required for Google provider.',
                    'project_id'
                );
            }
        }

        // Explicit credentials are optional — ADC will be tried at request time:
        // GOOGLE_APPLICATION_CREDENTIALS env var → gcloud default credentials → GCE metadata server
    }
}
