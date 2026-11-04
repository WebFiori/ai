<?php

namespace WebFiori\Tests\Ai\Cancellation;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\Exception\OperationCancelledException;

/**
 * Tests for the external stop-signal poller on CancellationTokenSource (issue #185).
 */
class CancellationPollerTest extends TestCase {
    public function testNoPollerBehavesAsBefore(): void {
        $source = new CancellationTokenSource();

        $this->assertFalse($source->getToken()->isCancelled());
        $source->cancel();
        $this->assertTrue($source->getToken()->isCancelled());
    }

    public function testPollerTrueCancelsAndFiresListenersOnce(): void {
        $fired = 0;
        $source = new CancellationTokenSource(fn () => true, 0);
        $source->getToken()->onCancel(function () use (&$fired) {
            $fired++;
        });

        $this->assertTrue($source->getToken()->isCancelled());
        $this->assertTrue($source->isCancelled());
        $source->cancel();

        $this->assertSame(1, $fired);
    }

    public function testThrowIfCancelledObservesPoller(): void {
        $source = new CancellationTokenSource(fn () => true, 0);

        $this->expectException(OperationCancelledException::class);
        $source->getToken()->throwIfCancelled();
    }

    public function testPollerFalseKeepsTokenActive(): void {
        $source = new CancellationTokenSource(fn () => false, 0);

        $this->assertFalse($source->getToken()->isCancelled());
    }

    public function testPollIsThrottledByInterval(): void {
        $now = 1000.0;
        $calls = 0;
        $source = new CancellationTokenSource(
            function () use (&$calls) {
                $calls++;

                return false;
            },
            500,
            function () use (&$now) {
                return $now;
            }
        );
        $token = $source->getToken();

        $token->isCancelled();
        $token->isCancelled();
        $this->assertSame(1, $calls, 'Second check inside the interval must not poll.');

        $now += 499;
        $token->isCancelled();
        $this->assertSame(1, $calls);

        $now += 1;
        $token->isCancelled();
        $this->assertSame(2, $calls, 'Poll again once the interval has elapsed.');
    }

    public function testPollerObservedAfterIntervalElapses(): void {
        $now = 0.0;
        $stop = false;
        $source = new CancellationTokenSource(
            function () use (&$stop) {
                return $stop;
            },
            500,
            function () use (&$now) {
                return $now;
            }
        );

        $this->assertFalse($source->getToken()->isCancelled());
        $stop = true;
        $now += 100;
        $this->assertFalse($source->getToken()->isCancelled(), 'Throttled: stop not yet observed.');
        $now += 400;
        $this->assertTrue($source->getToken()->isCancelled());
    }

    public function testCancelledStateStopsPolling(): void {
        $calls = 0;
        $source = new CancellationTokenSource(function () use (&$calls) {
            $calls++;

            return false;
        }, 0);

        $source->cancel();
        $source->getToken()->isCancelled();

        $this->assertSame(0, $calls);
    }

    public function testThrowingPollerIsTreatedAsNotCancelled(): void {
        $source = new CancellationTokenSource(function () {
            throw new \RuntimeException('store down');
        }, 0);

        $this->assertFalse($source->getToken()->isCancelled());
    }

    public function testPollerFailureLoggedOncePerStreak(): void {
        $logs = [];
        $fail = true;
        $source = new CancellationTokenSource(
            function () use (&$fail) {
                if ($fail) {
                    throw new \RuntimeException('store down');
                }

                return false;
            },
            0,
            null,
            function (string $level, string $message, array $context) use (&$logs) {
                $logs[] = [$level, $message, $context];
            }
        );
        $token = $source->getToken();

        $token->isCancelled();
        $token->isCancelled();
        $token->isCancelled();
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0][0]);
        $this->assertSame('store down', $logs[0][2]['error']);
        $this->assertSame(\RuntimeException::class, $logs[0][2]['exception_class']);

        // Recovery then a new failure streak logs again.
        $fail = false;
        $token->isCancelled();
        $fail = true;
        $token->isCancelled();
        $this->assertCount(2, $logs);
    }

    public function testFailingLogCallbackDoesNotBreakChecks(): void {
        $source = new CancellationTokenSource(
            function () {
                throw new \RuntimeException('x');
            },
            0,
            null,
            function () {
                throw new \RuntimeException('log broken');
            }
        );

        $this->assertFalse($source->getToken()->isCancelled());
    }

    public function testReentrantPollerDoesNotRecurse(): void {
        $calls = 0;
        $source = null;
        $source = new CancellationTokenSource(function () use (&$calls, &$source) {
            $calls++;
            $source->getToken()->isCancelled();

            return false;
        }, 0);

        $source->getToken()->isCancelled();

        $this->assertSame(1, $calls);
    }

    public function testNegativeIntervalRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        new CancellationTokenSource(fn () => false, -1);
    }
}
