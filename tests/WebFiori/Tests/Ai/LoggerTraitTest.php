<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\LoggerTrait;

/**
 * Unit tests for the LoggerTrait.
 *
 * @author Ibrahim
 */
class LoggerTraitTest extends TestCase {
    /**
     * @test
     */
    public function testCallbackReceivesContext() {
        $logger = $this->createLoggerInstance();
        $received = [];

        $logger->setLogCallback(function (string $level, string $message, array $context) use (&$received) {
            $received = ['level' => $level, 'message' => $message, 'context' => $context];
        });

        $logger->doLogInfo('Chat request sent', [
            'model' => 'gpt-4o',
            'tokens_used' => 150,
            'duration_ms' => 1234,
        ]);

        $this->assertEquals('info', $received['level']);
        $this->assertEquals('Chat request sent', $received['message']);
        $this->assertEquals('gpt-4o', $received['context']['model']);
        $this->assertEquals(150, $received['context']['tokens_used']);
        $this->assertEquals(1234, $received['context']['duration_ms']);
    }

    /**
     * @test
     */
    public function testDebugLevel() {
        $logger = $this->createLoggerInstance();
        $received = [];

        $logger->setLogCallback(function (string $level, string $message, array $context) use (&$received) {
            $received[] = ['level' => $level, 'message' => $message];
        });

        $logger->doLogDebug('Request body', ['body' => '{"model":"gpt-4o"}']);

        $this->assertCount(1, $received);
        $this->assertEquals('debug', $received[0]['level']);
        $this->assertEquals('Request body', $received[0]['message']);
    }

    /**
     * @test
     */
    public function testDisableLogging() {
        $logger = $this->createLoggerInstance();
        $callCount = 0;

        $logger->setLogCallback(function () use (&$callCount) {
            $callCount++;
        });

        $logger->doLogInfo('First message');
        $this->assertEquals(1, $callCount);

        $logger->setLogCallback(null);
        $logger->doLogInfo('Second message');
        $this->assertEquals(1, $callCount);
    }

    /**
     * @test
     */
    public function testErrorLevel() {
        $logger = $this->createLoggerInstance();
        $received = [];

        $logger->setLogCallback(function (string $level, string $message, array $context) use (&$received) {
            $received[] = ['level' => $level, 'message' => $message];
        });

        $logger->doLogError('API request failed', ['status_code' => 500]);

        $this->assertCount(1, $received);
        $this->assertEquals('error', $received[0]['level']);
    }

    /**
     * @test
     */
    public function testGetLogCallback() {
        $logger = $this->createLoggerInstance();

        $this->assertNull($logger->getLogCallback());

        $callback = function () {};
        $logger->setLogCallback($callback);

        $this->assertSame($callback, $logger->getLogCallback());
    }

    /**
     * @test
     */
    public function testNoCallbackNoOp() {
        $logger = $this->createLoggerInstance();

        // Should not throw or produce any output
        $logger->doLogDebug('test');
        $logger->doLogInfo('test');
        $logger->doLogWarning('test');
        $logger->doLogError('test');

        $this->assertNull($logger->getLogCallback());
    }

    /**
     * @test
     */
    public function testWarningLevel() {
        $logger = $this->createLoggerInstance();
        $received = [];

        $logger->setLogCallback(function (string $level, string $message, array $context) use (&$received) {
            $received[] = ['level' => $level, 'message' => $message];
        });

        $logger->doLogWarning('Rate limit approaching', ['remaining' => 5]);

        $this->assertCount(1, $received);
        $this->assertEquals('warning', $received[0]['level']);
        $this->assertEquals('Rate limit approaching', $received[0]['message']);
    }

    /**
     * Creates an anonymous class instance that uses LoggerTrait and exposes
     * the protected log methods for testing.
     *
     * @return object An object with public doLog* methods.
     */
    private function createLoggerInstance(): object {
        return new class {
            use LoggerTrait;

            public function doLogDebug(string $message, array $context = []): void {
                $this->logDebug($message, $context);
            }

            public function doLogError(string $message, array $context = []): void {
                $this->logError($message, $context);
            }

            public function doLogInfo(string $message, array $context = []): void {
                $this->logInfo($message, $context);
            }

            public function doLogWarning(string $message, array $context = []): void {
                $this->logWarning($message, $context);
            }
        };
    }
}
