<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\LoggerTrait;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolInterface;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Base class for AI provider implementations.
 *
 * Provides shared functionality for configuration management, HTTP client
 * handling, logging, and the template for provider-specific operations.
 * Concrete providers extend this class and implement the abstract methods
 * to handle their specific API formats.
 *
 * @author Ibrahim
 */
abstract class AbstractClient implements ProviderInterface {
    /**
     * Provider configuration options.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * The HTTP client used for making API requests.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $httpClient;

    /**
     * Creates a new provider instance.
     *
     * @param array<string, mixed> $config Provider configuration. Common options:
     *        - 'model': Default model to use for requests.
     *        - 'timeout': Request timeout in seconds.
     *        - 'connect_timeout': Connection timeout in seconds.
     *
     * @throws InvalidConfigException If required configuration is missing.
     */
    public function __construct(array $config = []) {
        $this->config = $config;
        $this->httpClient = new CurlHttpClient(
            $config['timeout'] ?? 120,
            $config['connect_timeout'] ?? 10
        );
        $this->validateConfig($config);
    }

    /**
     * Sends a chat completion request and returns the full response.
     *
     * Handles logging, request building, HTTP transport, response parsing,
     * and error mapping. Delegates provider-specific logic to abstract methods.
     *
     * @param Message[] $messages An array of messages forming the conversation.
     * @param array<string, mixed> $options Additional options (e.g., temperature,
     *        max_tokens, model override).
     *
     * @return ChatResponse The AI-generated response.
     *
     * @throws \WebFiori\Ai\Exception\AuthenticationException If credentials are invalid.
     * @throws \WebFiori\Ai\Exception\RateLimitException If the rate limit is exceeded.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     * @throws \WebFiori\Ai\Exception\HttpException If a transport error occurs.
     */
    public function chat(array $messages, array $options = []): ChatResponse {
        $model = $options['model'] ?? $this->getConfig('model');
        $startTime = microtime(true);

        $this->logInfo('Chat request started', [
            'provider' => $this->getName(),
            'model' => $model,
            'message_count' => count($messages),
        ]);

        $request = $this->buildChatRequest($messages, $options);
        $httpResponse = $this->sendRequest($request);
        $this->handleErrorResponse($httpResponse);
        $response = $this->parseChatResponse($httpResponse);

        $autoExecute = $options['auto_execute_tools'] ?? false;
        $tools = $options['tools'] ?? [];
        $maxIterations = $options['max_tool_iterations'] ?? 10;

        if ($autoExecute && count($tools) > 0) {
            $iteration = 0;

            while ($response->hasToolCalls() && $iteration < $maxIterations) {
                $iteration++;
                $messages[] = $response->getMessage();

                foreach ($response->getMessage()->getToolCalls() as $toolCall) {
                    $tool = $this->findTool($tools, $toolCall->getName());
                    $result = $tool !== null ? $tool->execute($toolCall->getArguments()) : '';

                    $this->logDebug('Tool executed', [
                        'tool' => $toolCall->getName(),
                        'iteration' => $iteration,
                    ]);

                    $messages[] = new Message(
                        'tool',
                        '',
                        [],
                        new ToolResult($toolCall->getId(), $result)
                    );
                }

                $request = $this->buildChatRequest($messages, $options);
                $httpResponse = $this->sendRequest($request);
                $this->handleErrorResponse($httpResponse);
                $response = $this->parseChatResponse($httpResponse);
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->logInfo('Chat request completed', [
            'provider' => $this->getName(),
            'model' => $response->getModel(),
            'finish_reason' => $response->getFinishReason(),
            'duration_ms' => $durationMs,
            'prompt_tokens' => $response->getUsage()?->getPromptTokens(),
            'completion_tokens' => $response->getUsage()?->getCompletionTokens(),
            'total_tokens' => $response->getUsage()?->getTotalTokens(),
        ]);

        return $response;
    }

    /**
     * Generates vector embeddings for the given text input.
     *
     * @param string|string[] $input A single text string or an array of strings.
     * @param array<string, mixed> $options Additional provider-specific options.
     *
     * @return EmbeddingResponse The embedding response containing vector(s).
     *
     * @throws \WebFiori\Ai\Exception\UnsupportedFeatureException If not supported.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     */
    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        $request = $this->buildEmbedRequest($input, $options);
        $httpResponse = $this->sendRequest($request);
        $this->handleErrorResponse($httpResponse);

        return $this->parseEmbedResponse($httpResponse);
    }

    /**
     * Generates an image from a text prompt.
     *
     * @param ImageRequest $request The image generation request.
     *
     * @return ImageResponse The response containing generated image(s).
     *
     * @throws \WebFiori\Ai\Exception\UnsupportedFeatureException If not supported.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     */
    public function generateImage(ImageRequest $request): ImageResponse {
        $httpRequest = $this->buildImageRequest($request);
        $httpResponse = $this->sendRequest($httpRequest);
        $this->handleErrorResponse($httpResponse);

        return $this->parseImageResponse($httpResponse);
    }

    /**
     * Returns a configuration value by key.
     *
     * @param string $key The configuration key.
     * @param mixed $default The default value if the key is not set.
     *
     * @return mixed The configuration value or the default.
     */
    public function getConfig(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    /**
     * Returns the HTTP client used for making API requests.
     *
     * @return HttpClientInterface The HTTP client instance.
     */
    public function getHttpClient(): HttpClientInterface {
        return $this->httpClient;
    }

    /**
     * Sets the HTTP client used for making API requests.
     *
     * @param HttpClientInterface $client The HTTP client to use.
     */
    public function setHttpClient(HttpClientInterface $client): void {
        $this->httpClient = $client;
    }

    /**
     * Sends a chat completion request with streaming response.
     *
     * @param Message[] $messages An array of messages forming the conversation.
     * @param callable $onToken Callback invoked for each token received.
     *        Signature: function(string $token): void
     * @param callable|null $onComplete Optional callback when streaming completes.
     *        Signature: function(ChatResponse $response): void
     * @param callable|null $onError Optional callback on stream error.
     *        Signature: function(\WebFiori\Ai\Exception\StreamingException $e): void
     * @param array<string, mixed> $options Additional provider-specific options.
     *
     * @throws \WebFiori\Ai\Exception\AuthenticationException If credentials are invalid.
     * @throws \WebFiori\Ai\Exception\RateLimitException If the rate limit is exceeded.
     * @throws \WebFiori\Ai\Exception\ProviderException If the provider returns an error.
     */
    public function streamChat(
        array $messages,
        callable $onToken,
        ?callable $onComplete = null,
        ?callable $onError = null,
        array $options = []
    ): void {
        $model = $options['model'] ?? $this->getConfig('model');

        $this->logInfo('Stream chat request started', [
            'provider' => $this->getName(),
            'model' => $model,
            'message_count' => count($messages),
        ]);

        $request = $this->buildStreamChatRequest($messages, $options);
        $this->doStreamChat($request, $onToken, $onComplete, $onError);
    }

    /**
     * Builds the HTTP request for a chat completion call.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    abstract protected function buildChatRequest(array $messages, array $options): HttpRequest;

    /**
     * Builds the HTTP request for an embeddings call.
     *
     * @param string|string[] $input The text input(s) to embed.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    abstract protected function buildEmbedRequest(string|array $input, array $options): HttpRequest;

    /**
     * Builds the HTTP request for an image generation call.
     *
     * @param ImageRequest $request The image generation request.
     *
     * @return HttpRequest The HTTP request to send.
     */
    abstract protected function buildImageRequest(ImageRequest $request): HttpRequest;

    /**
     * Builds the HTTP request for a streaming chat completion call.
     *
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     *
     * @return HttpRequest The HTTP request to send.
     */
    abstract protected function buildStreamChatRequest(array $messages, array $options): HttpRequest;

    /**
     * Executes the streaming chat request.
     *
     * @param HttpRequest $request The HTTP request to send.
     * @param callable $onToken Token callback.
     * @param callable|null $onComplete Completion callback.
     * @param callable|null $onError Error callback.
     */
    abstract protected function doStreamChat(
        HttpRequest $request,
        callable $onToken,
        ?callable $onComplete,
        ?callable $onError
    ): void;

    /**
     * Inspects an HTTP response and throws the appropriate exception for errors.
     *
     * @param HttpResponse $response The HTTP response to check.
     *
     * @throws \WebFiori\Ai\Exception\AuthenticationException If status is 401 or 403.
     * @throws \WebFiori\Ai\Exception\RateLimitException If status is 429.
     * @throws \WebFiori\Ai\Exception\ProviderException If status indicates a server error.
     */
    abstract protected function handleErrorResponse(HttpResponse $response): void;
    use LoggerTrait;

    /**
     * Parses an HTTP response into a ChatResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return ChatResponse The parsed chat response.
     */
    abstract protected function parseChatResponse(HttpResponse $response): ChatResponse;

    /**
     * Parses an HTTP response into an EmbeddingResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return EmbeddingResponse The parsed embedding response.
     */
    abstract protected function parseEmbedResponse(HttpResponse $response): EmbeddingResponse;

    /**
     * Parses an HTTP response into an ImageResponse.
     *
     * @param HttpResponse $response The HTTP response from the provider.
     *
     * @return ImageResponse The parsed image response.
     */
    abstract protected function parseImageResponse(HttpResponse $response): ImageResponse;

    /**
     * Sends an HTTP request using the configured HTTP client.
     *
     * @param HttpRequest $request The request to send.
     *
     * @return HttpResponse The response from the server.
     *
     * @throws \WebFiori\Ai\Exception\HttpException If a transport error occurs.
     */
    protected function sendRequest(HttpRequest $request): HttpResponse {
        $this->logDebug('HTTP request', [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
        ]);

        $response = $this->httpClient->send($request);

        $this->logDebug('HTTP response', [
            'status_code' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * Validates provider configuration.
     *
     * Subclasses should override this to check for required options.
     *
     * @param array<string, mixed> $config The configuration to validate.
     *
     * @throws InvalidConfigException If configuration is invalid.
     */
    protected function validateConfig(array $config): void {
        // Default: no validation. Subclasses override.
    }

    /**
     * Finds a tool by name from an array of tools.
     *
     * @param ToolInterface[] $tools The available tools.
     * @param string $name The tool name to find.
     *
     * @return ToolInterface|null The matching tool, or null if not found.
     */
    private function findTool(array $tools, string $name): ?ToolInterface {
        foreach ($tools as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
