<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\VertexAI;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\GeneratedImage;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\SseParser;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\AbstractClient;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Usage;

/**
 * Google Cloud Vertex AI (Gemini) provider implementation.
 *
 * Supports chat completions, streaming, embeddings, and image generation
 * via the Vertex AI API or the Gemini API using Gemini models.
 *
 * Configuration options:
 * - 'api' (optional): Which API endpoint to use. Either 'vertex_ai' (default)
 *   or 'gemini'. The Gemini API (generativelanguage.googleapis.com) is simpler
 *   and works with the free tier. Vertex AI (aiplatform.googleapis.com) is the
 *   enterprise endpoint requiring project_id and location.
 * - 'project_id' (required for vertex_ai API): GCP project ID.
 * - 'location' (required for vertex_ai API): GCP region (e.g., 'us-central1').
 * - 'model' (optional): Default model. Defaults to 'gemini-2.0-flash'.
 * - 'credentials' (required): Path to service account JSON file, or an array
 *   with the credentials, or an access token string.
 * - 'access_token' (optional): Pre-fetched OAuth2 access token. If provided,
 *   credentials file is not used.
 *
 * @author Ibrahim
 */
class VertexAIClient extends AbstractClient {
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
     * Returns the provider name.
     *
     * @return string The provider identifier.
     */
    public function getName(): string {
        return 'vertex_ai';
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

        return $config;
    }

    /**
     * Extracts the system instruction from messages.
     *
     * Vertex AI handles system messages as a separate top-level field.
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
     * Formats Message objects into Vertex AI contents format.
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

            if ($message->getContent() !== '') {
                $parts[] = ['text' => $message->getContent()];
            }

            if ($message->hasToolCalls()) {
                foreach ($message->getToolCalls() as $toolCall) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $toolCall->getName(),
                            'args' => $toolCall->getArguments(),
                        ],
                    ];
                }
            }

            if ($message->getToolResult() !== null) {
                $result = $message->getToolResult();
                $parts[] = [
                    'functionResponse' => [
                        'name' => $result->getToolCallId(),
                        'response' => json_decode($result->getContent(), true) ?? ['result' => $result->getContent()],
                    ],
                ];
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
                'Failed to sign JWT for Vertex AI authentication.',
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

        // Generate from service account credentials
        $credentials = $this->getConfig('credentials');

        if (is_string($credentials) && is_file($credentials)) {
            $credentials = json_decode(file_get_contents($credentials), true);
        }

        if (!is_array($credentials)) {
            throw new AuthenticationException(
                'Invalid credentials configuration for Vertex AI provider.',
                401
            );
        }

        $this->accessToken = $this->generateAccessToken($credentials);
        $this->tokenExpiresAt = time() + 3500; // ~58 minutes

        return $this->accessToken;
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
            return sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:%s',
                $model,
                $action
            );
        }

        $projectId = $this->getConfig('project_id');
        $location = $this->getConfig('location');

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
     * Returns the HTTP headers for Vertex AI API requests.
     *
     * @return array<string, string> The headers array.
     */
    private function getHeaders(): array {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->getAccessToken(),
        ];
    }

    /**
     * Maps Vertex AI finish reason to a normalized string.
     *
     * @param string $reason The Vertex AI finish reason.
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
     * Returns whether the Gemini API endpoint should be used.
     *
     * When 'api' is set to 'gemini', uses generativelanguage.googleapis.com.
     * Otherwise uses the Vertex AI aiplatform.googleapis.com endpoint.
     *
     * @return bool True if using the Gemini API, false for Vertex AI.
     */
    private function isGeminiApi(): bool {
        return $this->getConfig('api', 'vertex_ai') === 'gemini';
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
        $model = $this->getConfig('image_model', 'imagen-3.0-generate-001');
        $body = [
            'instances' => [[
                'prompt' => $request->getPrompt(),
            ]],
            'parameters' => [
                'sampleCount' => $request->getCount(),
            ],
        ];

        if ($request->getNegativePrompt() !== null) {
            $body['instances'][0]['negativePrompt'] = $request->getNegativePrompt();
        }

        return new HttpRequest(
            'POST',
            $this->getEndpoint($model, 'predict'),
            $this->getHeaders(),
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
        $errorMessage = $error['message'] ?? 'Unknown Vertex AI error';
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
     * @param HttpResponse $response The HTTP response from Vertex AI.
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
            if (isset($part['text'])) {
                $content .= $part['text'];
            }

            if (isset($part['functionCall'])) {
                $toolCalls[] = new ToolCall(
                    uniqid('call_'),
                    $part['functionCall']['name'],
                    $part['functionCall']['args'] ?? []
                );
            }
        }

        $message = new Message(
            'assistant',
            $content,
            $toolCalls
        );

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
     * @param HttpResponse $response The HTTP response from Vertex AI.
     *
     * @return EmbeddingResponse The parsed embedding response.
     */
    protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse {
        $data = $response->getJson();
        $vectors = [];

        foreach ($data['predictions'] ?? [] as $prediction) {
            $vectors[] = $prediction['embeddings']['values'] ?? [];
        }

        $model = $this->getConfig('embedding_model', 'text-embedding-004');

        return new EmbeddingResponse($vectors, $model);
    }

    /**
     * Parses an HTTP response into an ImageResponse.
     *
     * @param HttpResponse $response The HTTP response from Vertex AI.
     *
     * @return ImageResponse The parsed image response.
     */
    protected function parseImageResponse(HttpResponse $response): ImageResponse {
        $data = $response->getJson();
        $images = [];

        foreach ($data['predictions'] ?? [] as $prediction) {
            $images[] = new GeneratedImage(
                null,
                $prediction['bytesBase64Encoded'] ?? null,
                null
            );
        }

        $model = $this->getConfig('image_model', 'imagen-3.0-generate-001');

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
                    'The "project_id" configuration option is required for Vertex AI provider.',
                    'project_id'
                );
            }

            if (empty($config['location'])) {
                throw new InvalidConfigException(
                    'The "location" configuration option is required for Vertex AI provider.',
                    'location'
                );
            }
        }

        if (empty($config['credentials']) && empty($config['access_token'])) {
            throw new InvalidConfigException(
                'Either "credentials" or "access_token" is required for Vertex AI provider.',
                'credentials'
            );
        }
    }
}
