<?php

namespace WebFiori\Tests\Ai\Fallback;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Fallback\CircuitBreakerConfig;
use WebFiori\Ai\Provider\Fallback\CircuitState;
use WebFiori\Ai\Provider\Fallback\FallbackConfig;
use WebFiori\Ai\Provider\Fallback\FallbackProvider;
use WebFiori\Ai\Provider\Fallback\FallbackStrategy;
use WebFiori\Ai\Provider\ProviderInterface;

class FallbackProviderTest extends TestCase {
    public function testConstructorRequiresAtLeastOneProvider(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one provider');
        new FallbackProvider([]);
    }

    public function testConstructorAcceptsSingleProvider(): void {
        $provider = new MockProvider('test');
        $fallback = new FallbackProvider([$provider]);
        
        $this->assertEquals(1, $fallback->getProviderCount());
        $this->assertSame($provider, $fallback->getProvider(0));
        $this->assertNull($fallback->getProvider(1));
    }

    public function testGetName(): void {
        $fallback = new FallbackProvider([new MockProvider('test')]);
        $this->assertEquals('fallback', $fallback->getName());
    }

    public function testGetProviders(): void {
        $p1 = new MockProvider('p1');
        $p2 = new MockProvider('p2');
        $fallback = new FallbackProvider([$p1, $p2]);
        
        $providers = $fallback->getProviders();
        $this->assertCount(2, $providers);
        $this->assertSame($p1, $providers[0]);
        $this->assertSame($p2, $providers[1]);
    }

    public function testChatUsesFirstProviderOnSuccess(): void {
        $p1 = new MockProvider('primary');
        $p2 = new MockProvider('secondary');
        $fallback = new FallbackProvider([$p1, $p2]);
        
        $response = $fallback->chat([new Message('user', 'Hello')]);
        
        $this->assertStringContainsString('primary', $response->getMessage()->getContent());
        $this->assertEquals(1, $p1->getCallCount());
        $this->assertEquals(0, $p2->getCallCount());
        $this->assertEquals('primary', $fallback->getLastUsedProvider());
    }

    public function testChatFallsBackOnFailure(): void {
        $p1 = new MockProvider('primary', true);
        $p2 = new MockProvider('secondary');
        $fallback = new FallbackProvider([$p1, $p2]);
        
        $response = $fallback->chat([new Message('user', 'Hello')]);
        
        $this->assertStringContainsString('secondary', $response->getMessage()->getContent());
        $this->assertEquals(1, $p1->getCallCount());
        $this->assertEquals(1, $p2->getCallCount());
        $this->assertEquals('secondary', $fallback->getLastUsedProvider());
    }

    public function testChatThrowsWhenAllProvidersFail(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2', true);
        $fallback = new FallbackProvider([$p1, $p2]);
        
        $this->expectException(ProviderException::class);
        $fallback->chat([new Message('user', 'Hello')]);
    }

    public function testChatRespectsMaxAttempts(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2', true);
        $p3 = new MockProvider('p3');
        
        $config = new FallbackConfig(maxAttempts: 2);
        $fallback = new FallbackProvider([$p1, $p2, $p3], $config);
        
        try {
            $fallback->chat([new Message('user', 'Hello')]);
            $this->fail('Expected exception');
        } catch (ProviderException $e) {
            $this->assertEquals(0, $p3->getCallCount());
        }
    }

    public function testChatDoesNotFailoverForNonMatchingException(): void {
        $p1 = new MockProvider('p1', true, new AuthenticationException('Invalid key'));
        $p2 = new MockProvider('p2');
        
        $config = new FallbackConfig(failoverOn: [ProviderException::class]);
        $fallback = new FallbackProvider([$p1, $p2], $config);
        
        try {
            $fallback->chat([new Message('user', 'Hello')]);
            $this->fail('Expected exception');
        } catch (AuthenticationException $e) {
            $this->assertEquals(0, $p2->getCallCount());
        }
    }

    public function testEmbedWithFallback(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        $fallback = new FallbackProvider([$p1, $p2]);
        
        $response = $fallback->embed('test input');
        
        $this->assertEquals('p2', $response->getModel());
        $this->assertEquals('p2', $fallback->getLastUsedProvider());
    }

    public function testGenerateImageWithFallback(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        $fallback = new FallbackProvider([$p1, $p2]);
        
        $response = $fallback->generateImage(new ImageRequest('A test image'));
        
        $this->assertEquals('p2', $response->getModel());
    }

    public function testStreamChatWithFallback(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        $p2->setStreamTokens(['Hello', ' ', 'World']);
        
        $fallback = new FallbackProvider([$p1, $p2]);
        
        $tokens = [];
        $fallback->streamChat(
            [new Message('user', 'Hello')],
            function ($token) use (&$tokens) {
                $tokens[] = $token;
            }
        );
        
        $this->assertEquals(['Hello', ' ', 'World'], $tokens);
        $this->assertEquals('p2', $fallback->getLastUsedProvider());
    }

    public function testSequentialStrategyTriesInOrder(): void {
        $p1 = new MockProvider('first', true);
        $p2 = new MockProvider('second', true);
        $p3 = new MockProvider('third');
        
        $config = new FallbackConfig(strategy: FallbackStrategy::SEQUENTIAL);
        $fallback = new FallbackProvider([$p1, $p2, $p3], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        
        $this->assertEquals('third', $fallback->getLastUsedProvider());
        $this->assertEquals(1, $p1->getCallCount());
        $this->assertEquals(1, $p2->getCallCount());
        $this->assertEquals(1, $p3->getCallCount());
    }

    public function testRoundRobinStrategyRotates(): void {
        $p1 = new MockProvider('first');
        $p2 = new MockProvider('second');
        $p3 = new MockProvider('third');
        
        $config = new FallbackConfig(strategy: FallbackStrategy::ROUND_ROBIN);
        $fallback = new FallbackProvider([$p1, $p2, $p3], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals('first', $fallback->getLastUsedProvider());
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals('second', $fallback->getLastUsedProvider());
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals('third', $fallback->getLastUsedProvider());
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals('first', $fallback->getLastUsedProvider());
    }

    public function testRoundRobinFallsBackOnFailure(): void {
        $p1 = new MockProvider('first');
        $p2 = new MockProvider('second');
        $p3 = new MockProvider('third');
        
        $config = new FallbackConfig(strategy: FallbackStrategy::ROUND_ROBIN);
        $fallback = new FallbackProvider([$p1, $p2, $p3], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals('first', $fallback->getLastUsedProvider());
        
        $p2->setShouldFail(true);
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals('third', $fallback->getLastUsedProvider());
    }

    public function testWeightedStrategyDistribution(): void {
        $p1 = new MockProvider('heavy');
        $p2 = new MockProvider('light');
        
        $config = new FallbackConfig(
            strategy: FallbackStrategy::WEIGHTED,
            weights: [0 => 9, 1 => 1]
        );
        $fallback = new FallbackProvider([$p1, $p2], $config);
        
        $counts = ['heavy' => 0, 'light' => 0];
        for ($i = 0; $i < 100; $i++) {
            $fallback->chat([new Message('user', 'Hello')]);
            $counts[$fallback->getLastUsedProvider()]++;
        }
        
        $this->assertGreaterThan(60, $counts['heavy']);
    }

    public function testWeightedStrategyFallsBackOnFailure(): void {
        $p1 = new MockProvider('heavy', true);
        $p2 = new MockProvider('light');
        
        $config = new FallbackConfig(
            strategy: FallbackStrategy::WEIGHTED,
            weights: [0 => 99, 1 => 1]
        );
        $fallback = new FallbackProvider([$p1, $p2], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals('light', $fallback->getLastUsedProvider());
    }

    public function testCircuitBreakersAreCreatedWhenConfigured(): void {
        $p1 = new MockProvider('p1');
        $p2 = new MockProvider('p2');
        
        $config = new FallbackConfig(circuitBreaker: new CircuitBreakerConfig());
        $fallback = new FallbackProvider([$p1, $p2], $config);
        
        $this->assertCount(2, $fallback->getCircuitBreakers());
        $this->assertNotNull($fallback->getCircuitBreaker(0));
        $this->assertNotNull($fallback->getCircuitBreaker(1));
        $this->assertNull($fallback->getCircuitBreaker(99));
    }

    public function testNoCircuitBreakersWithoutConfig(): void {
        $fallback = new FallbackProvider([new MockProvider('p1')]);
        
        $this->assertEmpty($fallback->getCircuitBreakers());
        $this->assertNull($fallback->getCircuitBreaker(0));
    }

    public function testCircuitBreakerOpensAfterFailures(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        
        $config = new FallbackConfig(circuitBreaker: new CircuitBreakerConfig(2));
        $fallback = new FallbackProvider([$p1, $p2], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        $fallback->chat([new Message('user', 'Hello')]);
        
        $cb = $fallback->getCircuitBreaker(0);
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        
        $p1CallsBefore = $p1->getCallCount();
        $fallback->chat([new Message('user', 'Hello')]);
        
        $this->assertEquals($p1CallsBefore, $p1->getCallCount());
    }

    public function testCircuitBreakerRecordsSuccess(): void {
        $p1 = new MockProvider('p1');
        
        $config = new FallbackConfig(circuitBreaker: new CircuitBreakerConfig());
        $fallback = new FallbackProvider([$p1], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        
        $cb = $fallback->getCircuitBreaker(0);
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
        $this->assertEquals(0, $cb->getFailureCount());
    }

    public function testResetCircuitBreakers(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        
        $config = new FallbackConfig(circuitBreaker: new CircuitBreakerConfig(1));
        $fallback = new FallbackProvider([$p1, $p2], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals(CircuitState::OPEN, $fallback->getCircuitBreaker(0)->getState());
        
        $fallback->resetCircuitBreakers();
        $this->assertEquals(CircuitState::CLOSED, $fallback->getCircuitBreaker(0)->getState());
    }

    public function testHealthCheckReturnsSuccessWhenOneProviderAvailable(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        
        $fallback = new FallbackProvider([$p1, $p2]);
        $result = $fallback->healthCheck();
        
        $this->assertTrue($result->isAvailable());
        $this->assertEquals('fallback_health_check', $result->getCheckMethod());
    }

    public function testHealthCheckReturnsFailureWhenAllProvidersUnavailable(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2', true);
        
        $fallback = new FallbackProvider([$p1, $p2]);
        $result = $fallback->healthCheck();
        
        $this->assertFalse($result->isAvailable());
        $this->assertStringContainsString('All providers unavailable', $result->getError());
    }

    public function testHealthCheckSkipsOpenCircuits(): void {
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        
        $config = new FallbackConfig(circuitBreaker: new CircuitBreakerConfig(1));
        $fallback = new FallbackProvider([$p1, $p2], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        $this->assertEquals(CircuitState::OPEN, $fallback->getCircuitBreaker(0)->getState());
        
        $result = $fallback->healthCheck();
        $this->assertTrue($result->isAvailable());
    }

    public function testMetricsCallbackIsInvoked(): void {
        $metrics = [];
        $config = new FallbackConfig();
        $config->setMetricsCallback(function ($provider, $success, $latency, $error) use (&$metrics) {
            $metrics[] = compact('provider', 'success', 'latency', 'error');
        });
        
        $fallback = new FallbackProvider([new MockProvider('p1')], $config);
        $fallback->chat([new Message('user', 'Hello')]);
        
        $this->assertCount(1, $metrics);
        $this->assertEquals('p1', $metrics[0]['provider']);
        $this->assertTrue($metrics[0]['success']);
        $this->assertGreaterThanOrEqual(0, $metrics[0]['latency']);
        $this->assertNull($metrics[0]['error']);
    }

    public function testMetricsCallbackRecordsFailures(): void {
        $metrics = [];
        $config = new FallbackConfig();
        $config->setMetricsCallback(function ($provider, $success, $latency, $error) use (&$metrics) {
            $metrics[] = compact('provider', 'success', 'error');
        });
        
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        $fallback = new FallbackProvider([$p1, $p2], $config);
        
        $fallback->chat([new Message('user', 'Hello')]);
        
        $this->assertCount(2, $metrics);
        $this->assertEquals('p1', $metrics[0]['provider']);
        $this->assertFalse($metrics[0]['success']);
        $this->assertNotNull($metrics[0]['error']);
        $this->assertEquals('p2', $metrics[1]['provider']);
        $this->assertTrue($metrics[1]['success']);
    }

    public function testSetHttpClientPropagates(): void {
        $p1 = $this->createMock(ProviderInterface::class);
        $p2 = $this->createMock(ProviderInterface::class);
        
        $httpClient = $this->createStub(\WebFiori\Ai\Http\HttpClientInterface::class);
        
        $p1->expects($this->once())->method('setHttpClient')->with($httpClient);
        $p2->expects($this->once())->method('setHttpClient')->with($httpClient);
        
        $fallback = new FallbackProvider([$p1, $p2]);
        $fallback->setHttpClient($httpClient);
    }

    public function testSetLogCallbackPropagates(): void {
        $p1 = $this->createMock(ProviderInterface::class);
        $p2 = $this->createMock(ProviderInterface::class);
        
        $callback = function () {};
        
        $p1->expects($this->once())->method('setLogCallback')->with($callback);
        $p2->expects($this->once())->method('setLogCallback')->with($callback);
        
        $fallback = new FallbackProvider([$p1, $p2]);
        $fallback->setLogCallback($callback);
    }

    public function testLoggingDuringFallback(): void {
        $logs = [];
        $logCallback = function ($level, $message, $context) use (&$logs) {
            $logs[] = ['level' => $level, 'message' => $message];
        };
        
        $p1 = new MockProvider('p1', true);
        $p2 = new MockProvider('p2');
        $fallback = new FallbackProvider([$p1, $p2]);
        $fallback->setLogCallback($logCallback);
        
        $fallback->chat([new Message('user', 'Hello')]);
        
        $this->assertNotEmpty($logs);
        
        $hasWarning = false;
        foreach ($logs as $log) {
            if ($log['level'] === 'warning') {
                $hasWarning = true;
            }
        }
        $this->assertTrue($hasWarning, 'Should log warning on failure');
    }

    public function testGetConfig(): void {
        $config = new FallbackConfig(strategy: FallbackStrategy::WEIGHTED);
        $fallback = new FallbackProvider([new MockProvider('p1')], $config);
        
        $this->assertSame($config, $fallback->getConfig());
    }

    public function testLastUsedProviderIsNullBeforeFirstCall(): void {
        $fallback = new FallbackProvider([new MockProvider('p1')]);
        $this->assertNull($fallback->getLastUsedProvider());
    }
}
