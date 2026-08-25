<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Temperature;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Message;
use WebFiori\Ai\Temperature\ChatContext;
use WebFiori\Ai\Temperature\FixedTemperatureStrategy;

/**
 * Tests for FixedTemperatureStrategy.
 */
class FixedTemperatureStrategyTest extends TestCase {
    // =========================================================================
    // Returns fixed temperature regardless of context
    // =========================================================================

    public function testReturnsConfiguredTemperatureRegardlessOfContext(): void {
        $strategy = new FixedTemperatureStrategy(0.5);

        $context1 = new ChatContext([new Message('user', 'Write a poem')]);
        $context2 = new ChatContext([new Message('user', 'Fix this code')]);
        $context3 = new ChatContext([], ['json_mode' => true]);

        $this->assertSame(0.5, $strategy->temperature($context1));
        $this->assertSame(0.5, $strategy->temperature($context2));
        $this->assertSame(0.5, $strategy->temperature($context3));
    }

    public function testReturnsFixedTemperatureWithEmptyContext(): void {
        $strategy = new FixedTemperatureStrategy(1.0);
        $context = new ChatContext([]);

        $this->assertSame(1.0, $strategy->temperature($context));
    }

    // =========================================================================
    // Boundary values
    // =========================================================================

    public function testBoundaryValueZero(): void {
        $strategy = new FixedTemperatureStrategy(0.0);

        $this->assertSame(0.0, $strategy->getTemperature());
        $this->assertSame(0.0, $strategy->temperature(new ChatContext([])));
    }

    public function testBoundaryValueTwo(): void {
        $strategy = new FixedTemperatureStrategy(2.0);

        $this->assertSame(2.0, $strategy->getTemperature());
        $this->assertSame(2.0, $strategy->temperature(new ChatContext([])));
    }

    public function testMidRangeValue(): void {
        $strategy = new FixedTemperatureStrategy(1.5);

        $this->assertSame(1.5, $strategy->getTemperature());
    }

    // =========================================================================
    // Invalid argument exceptions
    // =========================================================================

    public function testThrowsExceptionForNegativeValue(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Temperature must be between 0.0 and 2.0');

        new FixedTemperatureStrategy(-0.1);
    }

    public function testThrowsExceptionForLargeNegativeValue(): void {
        $this->expectException(InvalidArgumentException::class);

        new FixedTemperatureStrategy(-5.0);
    }

    public function testThrowsExceptionForValueAboveTwo(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Temperature must be between 0.0 and 2.0');

        new FixedTemperatureStrategy(2.1);
    }

    public function testThrowsExceptionForVeryLargeValue(): void {
        $this->expectException(InvalidArgumentException::class);

        new FixedTemperatureStrategy(100.0);
    }

    // =========================================================================
    // getTemperature() getter
    // =========================================================================

    public function testGetTemperatureReturnsConfiguredValue(): void {
        $strategy = new FixedTemperatureStrategy(0.7);
        $this->assertSame(0.7, $strategy->getTemperature());
    }

    public function testGetTemperatureMatchesTemperatureMethod(): void {
        $strategy = new FixedTemperatureStrategy(1.2);
        $context = new ChatContext([new Message('user', 'Hello')]);

        $this->assertSame($strategy->getTemperature(), $strategy->temperature($context));
    }

    // =========================================================================
    // Interface implementation
    // =========================================================================

    public function testImplementsTemperatureStrategyInterface(): void {
        $strategy = new FixedTemperatureStrategy(0.5);

        $this->assertInstanceOf(
            \WebFiori\Ai\Temperature\TemperatureStrategyInterface::class,
            $strategy
        );
    }
}
