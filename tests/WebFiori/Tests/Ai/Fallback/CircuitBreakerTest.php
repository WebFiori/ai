<?php

namespace WebFiori\Tests\Ai\Fallback;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Provider\Fallback\CircuitBreaker;
use WebFiori\Ai\Provider\Fallback\CircuitBreakerConfig;
use WebFiori\Ai\Provider\Fallback\CircuitState;

class CircuitBreakerTest extends TestCase {
    public function testConfigDefaults(): void {
        $config = new CircuitBreakerConfig();
        $this->assertEquals(5, $config->getFailureThreshold());
        $this->assertEquals(60, $config->getCooldownSeconds());
        $this->assertEquals(2, $config->getSuccessThreshold());
    }

    public function testConfigCustomValues(): void {
        $config = new CircuitBreakerConfig(10, 120, 3);
        $this->assertEquals(10, $config->getFailureThreshold());
        $this->assertEquals(120, $config->getCooldownSeconds());
        $this->assertEquals(3, $config->getSuccessThreshold());
    }

    public function testConfigMinimumValues(): void {
        $config = new CircuitBreakerConfig(0, -5, 0);
        $this->assertEquals(1, $config->getFailureThreshold());
        $this->assertEquals(1, $config->getCooldownSeconds());
        $this->assertEquals(1, $config->getSuccessThreshold());
    }

    public function testStartsClosed(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig());
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
        $this->assertEquals(0, $cb->getFailureCount());
        $this->assertEquals(0, $cb->getSuccessCount());
        $this->assertTrue($cb->allowRequest());
    }

    public function testOpensAfterFailureThreshold(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(3));
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
        $this->assertEquals(1, $cb->getFailureCount());
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        $this->assertFalse($cb->allowRequest());
    }

    public function testResetsFailureCountOnSuccess(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(3));
        
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertEquals(2, $cb->getFailureCount());
        
        $cb->recordSuccess();
        $this->assertEquals(0, $cb->getFailureCount());
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
    }

    public function testReset(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(1));
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        
        $cb->reset();
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
        $this->assertEquals(0, $cb->getFailureCount());
        $this->assertTrue($cb->allowRequest());
    }

    public function testHalfOpenAfterCooldown(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(1, 1));
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        $this->assertFalse($cb->allowRequest());
        
        sleep(2);
        
        $this->assertTrue($cb->allowRequest());
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());
    }

    public function testClosesAfterSuccessThreshold(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(1, 1, 2));
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        
        sleep(2);
        $cb->allowRequest();
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());
        
        $cb->recordSuccess();
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());
        $this->assertEquals(1, $cb->getSuccessCount());
        
        $cb->recordSuccess();
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
    }

    public function testReopensOnHalfOpenFailure(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(1, 1));
        
        $cb->recordFailure();
        
        sleep(2);
        $cb->allowRequest();
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
    }

    public function testRecordSuccessInOpenStateTransitionsToHalfOpen(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(1, 60));
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        
        // Force a success while in OPEN state (edge case)
        $cb->recordSuccess();
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());
    }

    public function testRecordFailureInOpenStateStaysOpen(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(1, 60));
        
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        
        // Additional failure while already open
        $cb->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $cb->getState());
        $this->assertEquals(2, $cb->getFailureCount());
    }

    public function testAllowRequestInHalfOpenReturnsTrue(): void {
        $cb = new CircuitBreaker(new CircuitBreakerConfig(1, 1));
        
        $cb->recordFailure();
        sleep(2);
        $cb->allowRequest(); // Transitions to HALF_OPEN
        
        // Second call in HALF_OPEN should still return true
        $this->assertTrue($cb->allowRequest());
        $this->assertEquals(CircuitState::HALF_OPEN, $cb->getState());
    }

    public function testIsCooldownExpiredWithNullOpenedAt(): void {
        // Circuit starts closed with openedAt = null
        $cb = new CircuitBreaker(new CircuitBreakerConfig(1, 60));
        
        // This tests the isCooldownExpired() when openedAt is null
        // We can verify this indirectly by checking allowRequest returns true
        $this->assertTrue($cb->allowRequest());
        $this->assertEquals(CircuitState::CLOSED, $cb->getState());
    }
}
