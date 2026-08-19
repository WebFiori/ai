<?php

/**
 * This file is licensed under MIT License.
 */
namespace WebFiori\Tests\Ai\Fallback;

use WebFiori\Ai\ChatResponse;
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\HealthCheckResult;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;
use WebFiori\Ai\Exception\ProviderException;

/**
 * Mock provider for testing FallbackProvider.
 */
class MockProvider implements ProviderInterface {
    private string $name;
    private bool $shouldFail;
    private ?\Throwable $exception;
    private ?ChatResponse $chatResponse = null;
    private ?EmbeddingResponse $embedResponse = null;
    private ?ImageResponse $imageResponse = null;
    private ?HealthCheckResult $healthResult = null;
    private int $callCount = 0;
    private array $streamTokens = [];
    
    public function __construct(
        string $name,
        bool $shouldFail = false,
        ?\Throwable $exception = null
    ) {
        $this->name = $name;
        $this->shouldFail = $shouldFail;
        $this->exception = $exception;
    }
    
    public function chat(array $messages, array $options = []): ChatResponse {
        $this->callCount++;
        if ($this->shouldFail) {
            throw $this->exception ?? new ProviderException('Provider failed', 500);
        }
        return $this->chatResponse ?? $this->createMockChatResponse();
    }
    
    public function embed(string|array $input, array $options = []): EmbeddingResponse {
        $this->callCount++;
        if ($this->shouldFail) {
            throw $this->exception ?? new ProviderException('Provider failed', 500);
        }
        return $this->embedResponse ?? $this->createMockEmbedResponse();
    }
    
    public function generateImage(ImageRequest $request): ImageResponse {
        $this->callCount++;
        if ($this->shouldFail) {
            throw $this->exception ?? new ProviderException('Provider failed', 500);
        }
        return $this->imageResponse ?? $this->createMockImageResponse();
    }
    
    public function getName(): string {
        return $this->name;
    }
    
    public function healthCheck(int $timeout = 5): HealthCheckResult {
        if ($this->healthResult !== null) {
            return $this->healthResult;
        }
        if ($this->shouldFail) {
            return HealthCheckResult::failure('Provider unavailable', 100, 'test');
        }
        return HealthCheckResult::success(50, 'test');
    }
    
    public function setHttpClient(\WebFiori\Ai\Http\HttpClientInterface $client): void {}
    
    public function setLogCallback(?callable $callback): void {}
    
    public function streamChat(
        array $messages,
        callable $onToken,
        ?callable $onComplete = null,
        ?callable $onError = null,
        array $options = []
    ): void {
        $this->callCount++;
        if ($this->shouldFail) {
            throw $this->exception ?? new ProviderException('Provider failed', 500);
        }
        foreach ($this->streamTokens as $token) {
            $onToken($token);
        }
        if ($onComplete !== null) {
            $onComplete($this->createMockChatResponse());
        }
    }
    
    public function getCallCount(): int {
        return $this->callCount;
    }
    
    public function setShouldFail(bool $fail, ?\Throwable $exception = null): void {
        $this->shouldFail = $fail;
        $this->exception = $exception;
    }
    
    public function setChatResponse(ChatResponse $response): void {
        $this->chatResponse = $response;
    }
    
    public function setHealthResult(HealthCheckResult $result): void {
        $this->healthResult = $result;
    }
    
    public function setStreamTokens(array $tokens): void {
        $this->streamTokens = $tokens;
    }
    
    private function createMockChatResponse(): ChatResponse {
        return new ChatResponse(
            new Message('assistant', "Response from {$this->name}"),
            $this->name,
            null,
            'stop'
        );
    }
    
    private function createMockEmbedResponse(): EmbeddingResponse {
        return new EmbeddingResponse([[0.1, 0.2, 0.3]], $this->name);
    }
    
    private function createMockImageResponse(): ImageResponse {
        return new ImageResponse([], $this->name);
    }
}
