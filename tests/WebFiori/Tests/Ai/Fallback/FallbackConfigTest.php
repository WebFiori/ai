<?php

namespace WebFiori\Tests\Ai\Fallback;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Provider\Fallback\CircuitBreakerConfig;
use WebFiori\Ai\Provider\Fallback\FallbackConfig;
use WebFiori\Ai\Provider\Fallback\FallbackStrategy;

class FallbackConfigTest extends TestCase {
    public function testDefaults(): void {
        $config = new FallbackConfig();
        
        $this->assertEquals(FallbackStrategy::SEQUENTIAL, $config->getStrategy());
        $this->assertEquals(3, $config->getMaxAttempts());
        $this->assertNull($config->getCircuitBreaker());
        $this->assertNull($config->getMetricsCallback());
        $this->assertCount(3, $config->getFailoverOn());
    }

    public function testCustomValues(): void {
        $cbConfig = new CircuitBreakerConfig();
        $config = new FallbackConfig(
            strategy: FallbackStrategy::ROUND_ROBIN,
            failoverOn: [ProviderException::class],
            maxAttempts: 5,
            circuitBreaker: $cbConfig,
            weights: [0 => 3, 1 => 1]
        );
        
        $this->assertEquals(FallbackStrategy::ROUND_ROBIN, $config->getStrategy());
        $this->assertEquals(5, $config->getMaxAttempts());
        $this->assertSame($cbConfig, $config->getCircuitBreaker());
        $this->assertCount(1, $config->getFailoverOn());
        $this->assertEquals(3, $config->getWeight(0));
        $this->assertEquals(1, $config->getWeight(1));
        $this->assertEquals(1, $config->getWeight(99));
    }

    public function testShouldFailover(): void {
        $config = new FallbackConfig(
            failoverOn: [ProviderException::class, HttpException::class]
        );
        
        $this->assertTrue($config->shouldFailover(new ProviderException('test', 500)));
        $this->assertTrue($config->shouldFailover(new HttpException('test')));
        $this->assertFalse($config->shouldFailover(new AuthenticationException('test')));
        $this->assertFalse($config->shouldFailover(new \RuntimeException('test')));
    }

    public function testMetricsCallback(): void {
        $config = new FallbackConfig();
        $called = false;
        
        $config->setMetricsCallback(function () use (&$called) {
            $called = true;
        });
        
        $callback = $config->getMetricsCallback();
        $this->assertNotNull($callback);
        $callback('test', true, 100, null);
        $this->assertTrue($called);
    }

    public function testMaxAttemptsMinimum(): void {
        $config = new FallbackConfig(maxAttempts: 0);
        $this->assertEquals(1, $config->getMaxAttempts());
    }

    public function testGetWeights(): void {
        $config = new FallbackConfig(weights: [0 => 5, 2 => 10]);
        $weights = $config->getWeights();
        
        $this->assertEquals(5, $weights[0]);
        $this->assertEquals(10, $weights[2]);
        $this->assertArrayNotHasKey(1, $weights);
    }
}
